<?php
/**
 * Plugin Name: LuwiPress Core
 * Plugin URI: https://luwi.dev/luwipress
 * Description: AI-powered content enrichment, SEO optimization, and translation automation for WooCommerce stores.
 * Version: 3.17.4
 * Author: Luwi Developments LLC
 * Author URI: https://luwi.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: luwipress
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LUWIPRESS_VERSION', '3.17.4');
define('LUWIPRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LUWIPRESS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LUWIPRESS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Production Ed25519 public key for the luwi.dev license server (base64, 32-byte
// raw). With this pinned, LuwiPress_License verifies every server response via
// sodium_crypto_sign_verify_detached and rejects anything unsigned/forged. The
// guarded define lets wp-config.php point a staging site at a different key
// before the plugin loads. Verified end-to-end against https://luwi.dev/license/v1.
if ( ! defined( 'LUWIPRESS_LICENSE_PUBKEY' ) ) {
    define( 'LUWIPRESS_LICENSE_PUBKEY', 'pXWw2M8qKLWlsB65jw4+zT6GiM8x83XyJ6ca6s2w9Rc=' );
}

// Distribution build flag. `build-zip.php` writes a one-line config-dist.php into
// the shipped ZIP that defines LUWIPRESS_LICENSE_ENFORCE = true — turning ON
// license enforcement for distributed/sold copies WITHOUT exposing it as a
// customer-toggleable option (the vendor decides, not the buyer). The file is
// absent in source/dev builds, so local development is never gated. Loaded here,
// before any class, so the constant is defined before LuwiPress_License reads it.
if ( file_exists( __DIR__ . '/config-dist.php' ) ) {
    require_once __DIR__ . '/config-dist.php';
}

/**
 * Main LuwiPress Plugin Class
 */
class LuwiPress {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Is WooCommerce active and loaded? Single source of truth — every WC-only
     * feature gates on this. Plugin runs without WC; product enrichment, AEO,
     * marketplace, CRM, and KG product nodes silently disable themselves.
     *
     * @return bool
     */
    public static function is_wc_active() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        // Instantiate modules synchronously — each module's constructor
        // registers its own admin_menu / rest_api_init hooks, and we're
        // already on plugins_loaded p10 here (see luwipress_init at the
        // end of this file), which runs BEFORE admin_menu fires.
        $this->load_modules();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Module instantiation runs synchronously at the end of __construct
        // (see load_modules() call below). Each module's constructor is what
        // registers its own admin_menu / rest_api_init hooks, so we MUST
        // instantiate them before WP fires those actions. The bootstrap is
        // already deferred to plugins_loaded p10 by the file footer
        // `add_action('plugins_loaded', 'luwipress_init')`, which runs
        // BEFORE admin_menu (p10 in admin context) — so calling
        // load_modules() inline in the constructor is the simplest correct
        // ordering. Scheduling a NEW plugins_loaded callback from inside an
        // existing plugins_loaded callback at lower priority would silently
        // never fire (WP's hook iteration is already past it).

        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_init', array($this, 'handle_cron_notice_dismiss'));
        // Cron + migration notices stay registered globally so they reach the
        // operator on the WP plugins screen and other native pages — but they
        // are SUPPRESSED inside any LuwiPress admin page (see
        // suppress_admin_notices_on_luwipress_pages below) because notices
        // injected into the dashboard hero break our layout. The status
        // ribbon's red WC pill is the in-dashboard signal for missing WC.
        add_action('admin_notices', array($this, 'render_cron_notices'));
        add_action('admin_notices', array($this, 'render_webmcp_moved_notice'));
        add_action('admin_init', array($this, 'handle_webmcp_notice_dismiss'));
        add_action('admin_notices', array($this, 'render_open_claw_moved_notice'));
        add_action('admin_init', array($this, 'handle_open_claw_notice_dismiss'));

        // Strip ALL admin_notices / all_admin_notices output on LuwiPress
        // pages so third-party plugins (BeTheme TGM "recommended plugins",
        // WP core update nags, etc.) don't push the dashboard hero around
        // or overflow the header ribbon. Operator still sees these on their
        // native screens (Plugins list, Dashboard home, etc.).
        add_action('in_admin_header', array($this, 'suppress_admin_notices_on_luwipress_pages'), 1000);
        add_action('wp_footer', array($this, 'render_chat_assets'), 1);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_ajax_luwipress_scan_opportunities', array($this, 'ajax_scan_opportunities'));
        add_action('wp_ajax_luwipress_get_thin_products', array($this, 'ajax_get_thin_products'));
        add_action('wp_ajax_luwipress_emergency_stop', array($this, 'ajax_emergency_stop'));
        add_action('wp_ajax_luwipress_dashboard_data', array($this, 'ajax_dashboard_data'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Core infrastructure
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-permission.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-api.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-auth.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-logger.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-job-queue.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-token-tracker.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-security.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-hmac.php';

        // License Manager — per-site activation, tier/entitlement resolution,
        // and the daily heartbeat against the self-hosted luwi.dev license
        // server (3.13.0+). Loaded early so every other module + companion can
        // query the static capability gate (LuwiPress_License::can() / ::tier()
        // / ::is_active()) at construct time. Phase 1 is informational only —
        // nothing is gated yet; hard-block enforcement + auto-update follow.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-license.php';

        // Environment detection & bridge services
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-plugin-detector.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-site-config.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-email-proxy.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-seo-writer.php';

        // AI Engine: provider interface, providers, prompts, dispatcher, image handler
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-ai-provider.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-anthropic.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-openai.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-google.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/providers/class-luwipress-provider-openai-compatible.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-prompts.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-ai-engine.php';
        // DeepL translation engine — translate-only helper used by the translation
        // module's hybrid path. Not an AI provider; loaded before the translation module.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-deepl.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-image-handler.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-image-studio.php';

        // Content & automation modules
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-ai-content.php';
        // Schema Registry — generic JSON-LD type registry (3.4.0+). Loads BEFORE
        // AEO so AEO can register its (now-legacy) FAQ/HowTo/Speakable types
        // through the registry and any third-party plugin can pile on at the
        // `luwipress_schema_registry_init` action.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-schema-registry.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-aeo.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-translation.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-translation-sync.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-taxonomy-editor.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-content-scheduler.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-internal-linker.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-review-analytics.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-crm-bridge.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-crm-campaigns.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-knowledge-graph.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-kg-signals.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-kg-opportunities.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-kg-autopilot.php';
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-elementor.php';

        // Marketplace integration moved to the standalone "LuwiPress
        // Marketplace Sync" companion plugin in 3.1.44. Existing
        // luwipress_marketplace_* options + the wp_luwipress_marketplace_listings
        // table are read directly by the companion — no migration needed.

        // BM25 search index
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-search-index.php';

        // Customer-facing chat
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-customer-chat.php';

        // WordPress 6.9+ Abilities API bridge (no-op on older WP)
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-abilities.php';

        // ACP/ACS attribution bridge — server-side multi-cast for AI agent orders
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-acp-attribution.php';

        // Agentic Commerce (Google UCP + AP2) moved to the LuwiPress Agentic
        // companion plugin in core 3.6.2 so the core stays lean for stores that
        // don't sell through AI agents. The WebMCP ucp_/ap2_ tools guard on
        // class_exists, so they simply don't register when Agentic is inactive.

        // Theme Bridge — generic contract for LuwiPress-aware themes (3.1.48+).
        // Themes register tools + theme_mod proxies via two filters; the bridge
        // exposes them in the admin "Theme" tab, REST endpoints, and WebMCP.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-theme-bridge.php';

        // Slug Collision Resolver — generic page↔product_cat redirect engine
        // promoted from theme-tier to core in 3.1.58 so every LuwiPress site
        // (any theme) inherits the same migration-friendly rescue behaviour.
        // Six-pass discovery (exact / WPML cross / fuzzy / Levenshtein /
        // menu-parent inheritance / empty-term ancestor fallback) + REST
        // diagnostic surface for cross-customer troubleshooting.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-slug-resolver.php';

        // Bot Account Cleaner — score-based detection + safe deletion of fake
        // user accounts (3.1.60+). Eligibility gated to subscriber/customer
        // roles only; admins/editors/shop_managers never scored. WC customers
        // with any order are auto-excluded. Dry-run by default for bulk delete.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-bot-account-cleaner.php';

        // Cookie Consent — GDPR/ePrivacy banner + preferences modal +
        // consent log + AI policy generator + script-blocking helper (3.2.1+).
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-cookie-consent.php';

        // Bot Shield — UA blocklist + rate limit + honeypot + REST/XML-RPC
        // enumeration block. Front-edge guard at `init` priority 1 (3.2.1+).
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-bot-shield.php';

        // Content Audit — promotional-phrase detection across title / meta /
        // body for GMC-disapproval prevention (3.4.1+). Multilingual phrase
        // bank (en/tr/fr/it/es) with per-language WPML/Polylang detection.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-content-audit.php';

        // Content Health Score — pillar-weighted store quality composite
        // that drives the KG Store Health hero, Action Queue cards, and
        // achievement badges (3.5.4+). Settings → Content Health tab
        // configures per-pillar weights, targets, and action thresholds.
        // Loaded AFTER Content Audit so the brand-voice pillar can call
        // its phrase scanner without ordering surprises.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-health-score.php';

        // FAQ Tab Editor — inline metabox for editing `_luwipress_faq`
        // post meta directly from the post edit screen (3.5.5+). Closes
        // the biggest non-WebMCP UI gap: until now FAQ rows were only
        // writable through MCP / REST or AI generation. The metabox
        // mounts on product, post, page (filter-extensible).
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-faq-editor.php';

        // Frontend Inspector — fetch live URL + dump head / content / meta /
        // schema scopes (3.4.1+). Composes with Schema Registry's diagnostic
        // emitter; replaces 80%+ of chrome-devtools-mcp round-trips for SEO
        // and rendering audits.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-frontend-inspector.php';

        // Redirections — Rank Math Redirections CRUD bridge (3.12.0+). Wraps the
        // RankMath\Redirections\DB data layer so redirects can be listed /
        // created / batch-seeded / updated / deleted via REST + WebMCP. Built
        // for post-migration (DNS swap) redirect management. Degrades to a 503
        // with a clear message when Rank Math (or its Redirections module) is
        // not active, so it's safe to ship on every site.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-redirections.php';

        // Internal Link Audit — read-only WP_Query + body/meta scan that surfaces
        // where (and how often) a target URL / slug is linked across the site
        // (3.12.0+). Powers pre/post-migration link hygiene without server log
        // access. Pure-read; no third-party plugin dependency.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-internal-link-audit.php';

        // Product Snapshot — WooCommerce product snapshot / rollback mirroring
        // the Elementor snapshot pattern (3.12.0+). Captures post fields + all
        // meta + variations + gallery + term assignments before risky edits;
        // rollback is auth-gated. WC soft-dep: routes 503 when WC is inactive.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-product-snapshot.php';

        // CPT Engine — generic custom-post-type registry (3.7.x+). The single
        // source of truth for what content types exist (Vendors = preset #1;
        // Events / Team = future presets). Loaded before Vendors so presets can
        // describe themselves into it. Phase 0: directory only (modules still
        // self-register); downstream integrations (Translation Manager,
        // Elementor, WooCommerce) enumerate it.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-cpt-engine.php';
        LuwiPress_CPT_Engine::get_instance();

        // Vendors CPT — generic E-E-A-T maker/atelier/team profile system (3.5.2+).
        // Per-site configurable: music store → "Luthiers", restaurant → "Chefs",
        // gallery → "Artists", agency → "Team". Emits Schema.org Person /
        // Organization / LocalBusiness JSON-LD via the Schema Registry,
        // including verified social URLs in `sameAs` for strong vendor /
        // author identity signals. Product → vendor attribution via the
        // `_lwp_vendor_ids` meta key feeds Schema.org `manufacturer`.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-vendors.php';
        LuwiPress_Vendors::get_instance();

        // Events CPT — CPT Engine preset #2 (3.9.0+). Concerts / workshops /
        // classes as a first-class content type (lwp_event) with structured
        // fields, organizer/performer links to Vendors, Schema.org Event JSON-LD
        // (reuses the Schema Registry `event` type), and .ics calendar export.
        // DORMANT by default — registers nothing until the operator enables it
        // (LuwiPress → Events, REST /events/settings, or MCP event_settings_set).
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-events.php';
        LuwiPress_Events::get_instance();

        // Booking — remote tour configuration (3.15.0+). REST surface for
        // reading/writing `_fbd_*` tour meta on WooCommerce products without the
        // product editor (flag a tour, set pax range / time slots / add-ons /
        // cancellation, etc.). The `_fbd_*` schema is owned by the Amber theme's
        // booking module; core mirrors its validation and writes the raw keys,
        // closing the gap where the theme can't REST-write the array meta
        // (_fbd_time_slots / _fbd_addons). WC-gated at the handler level.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-booking.php';
        LuwiPress_Booking::get_instance();

        // Theme String Translation (3.15.0+). AI-translates a theme's .pot/.po UI
        // strings into the active languages and compiles .mo — a built-in stand-in
        // for Loco Translate's paid auto-translate quota. Writes to the update-safe
        // system languages dir by default. REST: /translation/theme-coverage +
        // /translation/theme-strings. Reuses the configured AI engine.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-theme-i18n.php';
        LuwiPress_Theme_I18n::get_instance();

        // Forms Bridge (3.15.0+). Remote create/edit/list/inspect of the detected
        // form plugin's forms (v1: Fluent Forms) via REST — so the Plan-My-Trip
        // wizard etc. can be built by MCP instead of the form builder UI.
        // REST: /forms (list/create), /forms/{id} (get/update), /forms/{id}/entries.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-forms.php';
        LuwiPress_Forms::get_instance();

        // Backup Bridge (3.15.0+). Wraps the detected backup plugin (v1: UpdraftPlus)
        // so a backup can be triggered, monitored, listed, and an archive streamed to a
        // trusted peer for cross-server migration — all from REST/MCP. Symmetric: the
        // same routes serve as source (run/status/list/download) and target
        // (pull/rescan/restore). Degrades to 503 when UpdraftPlus is absent.
        // REST: /backup/diag|run|status|list|download|pull|rescan|restore.
        require_once LUWIPRESS_PLUGIN_DIR . 'includes/class-luwipress-backup-bridge.php';
        LuwiPress_Backup_Bridge::get_instance();

    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $this->set_default_options();

        // Create all database tables
        LuwiPress_Logger::create_table();
        LuwiPress_Token_Tracker::create_table();
        LuwiPress_Customer_Chat::create_table();
        LuwiPress_Search_Index::create_table();
        LuwiPress_Bot_Account_Cleaner::create_table();
        LuwiPress_Cookie_Consent::create_table();
        LuwiPress_Bot_Shield::create_table();
        LuwiPress_CRM_Campaigns::maybe_create_tables();
        // Marketplace listings table created by the LuwiPress Marketplace Sync
        // companion plugin's own activation hook (3.1.44+).

        // Generate HMAC secret if not set
        LuwiPress_HMAC::ensure_secret();

        // Schedule daily cleanup
        if ( ! wp_next_scheduled( 'luwipress_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'luwipress_daily_cleanup' );
        }

        // Schedule the daily license heartbeat (also self-heals on admin_init
        // for ZIP-overwrite upgrades that skip this hook).
        if ( class_exists( 'LuwiPress_License' ) ) {
            LuwiPress_License::activate();
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Log activation
        LuwiPress_Logger::log('Plugin activated', 'info');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'luwipress_daily_cleanup' );

        // Modules with their own cron hooks unschedule via static deactivate
        // helpers. Add new module hooks here as they ship.
        if ( class_exists( 'LuwiPress_Health_Score' ) ) {
            LuwiPress_Health_Score::deactivate();
        }
        if ( class_exists( 'LuwiPress_License' ) ) {
            LuwiPress_License::deactivate();
        }

        flush_rewrite_rules();
        LuwiPress_Logger::log('Plugin deactivated', 'info');
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        load_plugin_textdomain('luwipress', false, dirname(LUWIPRESS_PLUGIN_BASENAME) . '/languages');

        // Daily cleanup cron
        add_action( 'luwipress_daily_cleanup', array( $this, 'run_daily_cleanup' ) );
    }

    /**
     * Instantiate all modules. Runs on `plugins_loaded` priority 5 so module
     * constructors (which `add_action('admin_menu', ...)`) execute BEFORE
     * WP fires `admin_menu` on first activation. Previously this ran on
     * `init`, racing same-priority hooks and producing an incomplete menu
     * on the first page load.
     */
    public function load_modules() {
        // License Manager first — its static gate API must be callable before
        // any other module constructs (Phase 4 companions will query can()).
        LuwiPress_License::get_instance();

        // Core infrastructure
        LuwiPress_API::get_instance();
        LuwiPress_Auth::get_instance();
        LuwiPress_Security::get_instance();

        // Environment detection & bridge services
        LuwiPress_Plugin_Detector::get_instance();
        LuwiPress_Site_Config::get_instance();
        LuwiPress_Email_Proxy::get_instance();
        LuwiPress_SEO_Writer::get_instance();

        // Content & automation modules
        LuwiPress_AI_Content::get_instance();
        LuwiPress_AI_Content::init_frontend_hooks();
        LuwiPress_Image_Studio::get_instance();
        LuwiPress_Schema_Registry::get_instance();
        LuwiPress_AEO::get_instance();
        LuwiPress_Translation::get_instance();
        LuwiPress_Translation_Sync::get_instance();
        LuwiPress_Content_Scheduler::get_instance();
        LuwiPress_Internal_Linker::get_instance();
        LuwiPress_Review_Analytics::get_instance();
        LuwiPress_CRM_Bridge::get_instance();
        LuwiPress_CRM_Campaigns::get_instance();
        LuwiPress_Knowledge_Graph::get_instance();
        LuwiPress_KG_Signals::get_instance();
        LuwiPress_KG_Opportunities::get_instance();
        LuwiPress_KG_Autopilot::get_instance();
        // LuwiPress_Marketplace::get_instance() — split into companion plugin (3.1.44).
        LuwiPress_Search_Index::get_instance();
        LuwiPress_Customer_Chat::get_instance();
        LuwiPress_Abilities::get_instance();
        LuwiPress_ACP_Attribution::get_instance();
        LuwiPress_Theme_Bridge::get_instance();
        LuwiPress_Slug_Resolver::get_instance();
        LuwiPress_Bot_Account_Cleaner::get_instance();
        LuwiPress_Cookie_Consent::get_instance();
        LuwiPress_Bot_Shield::get_instance();
        LuwiPress_Content_Audit::get_instance();
        LuwiPress_Health_Score::get_instance();
        LuwiPress_FAQ_Editor::get_instance();
        LuwiPress_Taxonomy_Editor::get_instance();
        LuwiPress_Frontend_Inspector::get_instance();
        LuwiPress_Redirections::get_instance();
        LuwiPress_Internal_Link_Audit::get_instance();
        LuwiPress_Product_Snapshot::get_instance();

        // Elementor integration (only if Elementor is active)
        if ( LuwiPress_Elementor::is_elementor_active() || is_admin() ) {
            LuwiPress_Elementor::get_instance();
        }
    }
    
    /**
     * Register REST API routes
     *
     * Routes are handled by LuwiPress_API::register_endpoints().
     * This hook is kept for backward compatibility / filter use.
     */
    public function register_rest_routes() {
        // Routes delegated to LuwiPress_API to avoid duplicate registration conflicts.
        do_action( 'luwipress_register_rest_routes' );
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Position 3.13 — decimal under Dashboard (2). Integer 3 was taken
        // by Jetpack on Tapadum (visible bug: Jetpack appeared as a phantom
        // submenu under our parent), and integer collisions overwrite slots
        // unpredictably. WP accepts float positions and uses the decimal as
        // a tiebreak so collision risk drops to near zero. Stays out of the
        // BeTheme 25-55 cluster and any cleanup themes that filter null
        // positions still render us because we pass a real numeric value.
        add_menu_page(
            'LuwiPress',
            'LuwiPress',
            'manage_options',
            'luwipress',
            array($this, 'admin_page'),
            LUWIPRESS_PLUGIN_URL . 'assets/images/luwi-logo-white.png',
            3.13
        );

        // WP renders menu icons at 20×20 with 7px/2px padding, which squashes our 2000px logo.
        // Force the image to fill the 34px icon cell so the omega motif stays readable.
        add_action( 'admin_head', function () {
            echo '<style>
                #adminmenu #toplevel_page_luwipress .wp-menu-image img {
                    width: 24px; height: 24px; padding: 0; opacity: 1;
                }
                #adminmenu #toplevel_page_luwipress .wp-menu-image {
                    padding: 5px 0 0 4px;
                }
            </style>';
        } );

        // ─── VISIBLE SUBMENUS (6 total — Content folded into Site 3.7.3) ─
        // Pre-3.5.7 each module shipped a flat submenu; 15+ items overflowed
        // the WP sidebar and operator focus. The 3.5.7 IA folded related
        // tools into two hubs (Content / Site); 3.7.3 folds Content INTO the
        // Site hub (one grouped tab strip: Site / Content) so the visible
        // row drops to 6. Every tool keeps a deep-linkable URL via ?tab=;
        // old slugs remain as hidden redirect-only submenus below.

        // 1. Dashboard (rename the auto-generated first submenu)
        add_submenu_page(
            'luwipress',
            __( 'Dashboard', 'luwipress' ),
            __( 'Dashboard', 'luwipress' ),
            'manage_options',
            'luwipress',
            array( $this, 'admin_page' )
        );

        // 2. Knowledge Graph
        add_submenu_page(
            'luwipress',
            __( 'Knowledge Graph', 'luwipress' ),
            __( 'Knowledge Graph', 'luwipress' ),
            'manage_options',
            'luwipress-knowledge-graph',
            array( $this, 'knowledge_graph_page' )
        );

        // 3. Translations — owned by LuwiPress_Translation; left between
        //    Knowledge Graph and Site in the visible row. Sync Audit panel
        //    lives inside that page at ?tab=sync-audit (handled by module).

        // 4. Site — combined hub (Content folded in 3.7.3). Site group:
        //    Slug Resolver / Vendors / Theme / Bot Defense / Cookie Consent.
        //    Content group: Health Audit / Schema / Taxonomy / Image Alt /
        //    Scheduler. Tab dispatch is server-rendered (`?tab=` whitelist)
        //    inside site-hub-page.php; the strip is grouped by section.
        //    The old `luwipress-content` slug + its tab deep-links survive
        //    via the hidden compat redirects below.
        add_submenu_page(
            'luwipress',
            __( 'Site', 'luwipress' ),
            __( 'Site', 'luwipress' ),
            'manage_options',
            'luwipress-site',
            array( $this, 'site_hub_page' )
        );

        // (Agentic Commerce hub moved to the LuwiPress Agentic companion in
        //  core 3.6.2 — it registers its own submenu under this parent.)

        // 7. Settings
        add_submenu_page(
            'luwipress',
            __( 'Settings', 'luwipress' ),
            __( 'Settings', 'luwipress' ),
            'manage_options',
            'luwipress-settings',
            array( $this, 'settings_page' )
        );

        // 8. Usage & Logs
        add_submenu_page(
            'luwipress',
            __( 'Usage & Logs', 'luwipress' ),
            __( 'Usage & Logs', 'luwipress' ),
            'manage_options',
            'luwipress-usage',
            array( $this, 'usage_page' )
        );

        // ─── HIDDEN COMPAT SLUGS (deep-link survival) ───────────────
        // Every pre-3.5.7 submenu slug remains addressable so existing
        // bookmarks / WP-CLI deep links / docs / MCP integrations do not
        // 404 after the IA consolidation. Each old slug redirects to the
        // matching hub home with the right ?tab= parameter. Parent slug
        // '' keeps them out of the visible sidebar.
        $compat_redirects = array(
            // Content hub — folded into the Site hub in 3.7.3. The old
            // `luwipress-content` slug + every former tab deep-link now
            // resolve onto the combined Site hub (Content group).
            'luwipress-content'          => 'luwipress-site&tab=audit',
            'luwipress-content-audit'    => 'luwipress-site&tab=audit',
            'luwipress-schema-picker'    => 'luwipress-site&tab=schema',
            'luwipress-schema-preview'   => 'luwipress-site&tab=schema', // merged into Schema tab (3.6.x)
            'luwipress-taxonomy-editor'  => 'luwipress-site&tab=taxonomy',
            'luwipress-image-alt-bulk'   => 'luwipress-site&tab=alt',
            // Site hub tabs
            'luwipress-slug-resolver'    => 'luwipress-site&tab=slug-resolver',
            'luwipress-vendors'          => 'luwipress-site&tab=vendors',
            'luwipress-events'           => 'luwipress-site&tab=events',
            'luwipress-theme'            => 'luwipress-site&tab=theme',
            'luwipress-bot-defense'      => 'luwipress-site&tab=bot-defense',
            'luwipress-cookies'          => 'luwipress-site&tab=cookies',
            // Translation Sync — opens the existing Translations page on
            // its sync-audit tab so the entire surface stays in one place.
            'luwipress-translation-sync' => 'luwipress-translations&tab=sync-audit',
            // Bot Defense — second-generation hidden slugs (3.2.2+) also
            // redirect to the new hub.
            'luwipress-bot-accounts'     => 'luwipress-site&tab=bot-defense',
            'luwipress-bot-shield'       => 'luwipress-site&tab=bot-defense',
        );
        foreach ( $compat_redirects as $old_slug => $target ) {
            // Closure captures $target so each registered callback knows
            // its own destination. wp_safe_redirect + exit is enough; the
            // browser will land on the hub with the correct tab selected
            // because the hub renderer reads ?tab= directly.
            //
            // Sub-tab forwarding (3.5.8+): pages with their own sub-tab
            // system (Cookie Consent → Settings/Log/AI Policy, Bot Defense
            // → Overview/Accounts/Shield/Comments, Translations Sync →
            // sync-audit panel) emit `&tab=<sub>` on their internal links.
            // When the redirect fires we recover that value and forward it
            // as `&sub=<sub>` so the hub page renders the correct sub-tab
            // on arrival instead of always landing on the default.
            add_submenu_page(
                '',
                '',
                '',
                'manage_options',
                $old_slug,
                function () use ( $old_slug, $target ) {
                    if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
                    }
                    // phpcs:disable WordPress.Security.NonceVerification.Recommended
                    // Hub→hub remap (Content folded into Site, 3.7.3): the old
                    // `?page=luwipress-content&tab=<x>` bookmarks carry a
                    // TOP-LEVEL hub tab whose key is identical in the Site hub.
                    // Forward it as `&tab=` (not `&sub=`) so the deep-link lands
                    // on the right tab instead of the default. Whitelist the
                    // former Content tabs so an unknown ?tab= can't be injected.
                    if ( 'luwipress-content' === $old_slug && isset( $_GET['tab'] ) ) {
                        $ctab     = sanitize_key( wp_unslash( $_GET['tab'] ) );
                        $content_tabs = array( 'audit', 'schema', 'taxonomy', 'alt', 'scheduler' );
                        if ( in_array( $ctab, $content_tabs, true ) ) {
                            wp_safe_redirect( admin_url( 'admin.php?page=luwipress-site&tab=' . $ctab ) );
                            exit;
                        }
                    }
                    $url = admin_url( 'admin.php?page=' . $target );
                    // Sub-tab forwarding: pages with their own inner sub-tab
                    // system (Cookie Consent, Bot Defense, Translations Sync)
                    // emit `&tab=<sub>` on internal links — recover it as
                    // `&sub=<sub>` so the hub renders the right inner panel.
                    if ( isset( $_GET['tab'] ) ) {
                        $sub = sanitize_key( wp_unslash( $_GET['tab'] ) );
                        if ( '' !== $sub ) {
                            $url = add_query_arg( 'sub', $sub, $url );
                        }
                    } elseif ( isset( $_GET['sub'] ) ) {
                        $sub = sanitize_key( wp_unslash( $_GET['sub'] ) );
                        if ( '' !== $sub ) {
                            $url = add_query_arg( 'sub', $sub, $url );
                        }
                    }
                    // phpcs:enable WordPress.Security.NonceVerification.Recommended
                    wp_safe_redirect( $url );
                    exit;
                }
            );
        }

    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/admin-page.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/settings-page.php';
    }
    
    /**
     * Unified Usage & Logs page
     */
    public function usage_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/usage-page.php';
    }

    /**
     * Content hub (3.5.7+) — submenu shell that renders the Health Audit
     * / Schema / Schema Preview / Taxonomy / Image Alt / Scheduler tabs.
     * Per-tab body content is the existing page file (e.g.
     * `admin/content-audit-page.php`) included with LUWIPRESS_HUB_INCLUDED
     * defined so the included page drops its outer wrap + page-level h1.
     */
    public function content_hub_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/content-hub-page.php';
    }

    /**
     * Site hub (3.5.7+) — submenu shell that renders the Slug Resolver /
     * Vendors / Theme / Bot Defense / Cookie Consent tabs. Same include
     * pattern as the Content hub: each tab pulls its existing page file
     * with LUWIPRESS_HUB_INCLUDED defined to suppress outer chrome.
     */
    public function site_hub_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/site-hub-page.php';
    }

    /**
     * Knowledge Graph page
     */
    public function knowledge_graph_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/knowledge-graph-page.php';
    }

    /**
     * Theme page — renders Status/Tools/Settings tabs for the active companion theme.
     */
    public function theme_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/theme-page.php';
    }

    /**
     * Bot Defense page — unified Bot Accounts + Bot Shield surface.
     * Old slugs (luwipress-bot-accounts / luwipress-bot-shield) route here too;
     * the template detects the slug and preselects the matching tab.
     */
    public function bot_defense_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/bot-defense-page.php';
    }

    /**
     * Cookie Consent page — GDPR banner + consent log + AI policy generator.
     */
    public function cookies_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/cookies-page.php';
    }

    /**
     * Content Audit page (3.5.4+) — unified surface over promotional phrase
     * audit + AI-tell scanner + per-CPT word count compliance. The page
     * reads the same Health Score pillar values the KG dashboard uses, so
     * fixes here update the operator's visible Store Health score.
     */
    public function content_audit_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/content-audit-page.php';
    }

    /**
     * Schema Preview page (3.5.4+) — wraps the Frontend Inspector for
     * cache-bypass JSON-LD inspection. Lists Schema Registry types,
     * fetches any live URL, extracts JSON-LD blocks, and offers a 1-click
     * handoff to Google's Rich Results Test.
     */
    public function schema_preview_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/schema-preview-page.php';
    }

    /**
     * Multi-language Taxonomy Editor admin page (3.5.6+).
     */
    public function taxonomy_editor_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/taxonomy-editor-page.php';
    }

    /**
     * Image Alt Bulk admin page (3.5.6+).
     */
    public function image_alt_bulk_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/image-alt-bulk-page.php';
    }

    /**
     * Vendors admin page (3.5.6+).
     */
    public function vendors_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/vendors-page.php';
    }

    /**
     * Schema Picker admin page (3.5.6+).
     */
    public function schema_picker_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/schema-picker-page.php';
    }

    /**
     * Slug Resolver admin page (3.5.6+).
     */
    public function slug_resolver_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/slug-resolver-page.php';
    }

    /**
     * Translation Sync deep-link callback (3.5.4+) — bounces to the
     * existing Translations page with `tab=sync-audit` so the operator
     * lands directly on the sync-audit panel. The Translations page
     * itself is registered by LuwiPress_Translation::add_submenu; this
     * callback only fires when WP routes the deep-link slug to us.
     */
    public function translation_sync_redirect() {
        $target = add_query_arg(
            array(
                'page' => 'luwipress-translations',
                'tab'  => 'sync-audit',
            ),
            admin_url( 'admin.php' )
        );
        if ( ! headers_sent() ) {
            wp_safe_redirect( $target );
            exit;
        }
        // Fallback for environments where headers already went out — render
        // a tiny meta-refresh + link so the operator still lands on target.
        ?>
        <meta http-equiv="refresh" content="0; url=<?php echo esc_attr( $target ); ?>">
        <p><a href="<?php echo esc_url( $target ); ?>"><?php esc_html_e( 'Open Translation Sync Audit', 'luwipress' ); ?></a></p>
        <?php
    }

    /**
     * Whether the active theme registered itself with the LuwiPress companion
     * contract (capabilities matrix OR tools OR settings). The Theme tab in
     * the Site hub only appears when at least one of these surfaces returns
     * content. Public since 3.5.7 because the Site hub admin page reads this
     * via LuwiPress::get_instance() from outside the class.
     *
     * @return bool
     */
    public function theme_companion_present() {
        $slug = get_stylesheet();

        $companions = apply_filters( 'luwipress_theme_companion', array() );
        if ( is_array( $companions ) && ! empty( $companions[ $slug ] ) ) {
            return true;
        }

        if ( class_exists( 'LuwiPress_Theme_Bridge' ) ) {
            $bridge = LuwiPress_Theme_Bridge::get_instance();
            if ( ! empty( $bridge->get_tools() ) || ! empty( $bridge->get_settings() ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * AJAX: Emergency stop — disables all AI enrichment and sets limit to $0.001
     */
    public function ajax_emergency_stop() {
        check_ajax_referer('luwipress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        update_option('luwipress_auto_enrich_thin', false);
        update_option('luwipress_auto_enrich', false);
        update_option('luwipress_daily_token_limit', 0.001);
        wp_clear_scheduled_hook('luwipress_auto_enrich_thin_cron');

        LuwiPress_Logger::log('Emergency stop activated — all AI enrichment disabled', 'warning', array(
            'user' => get_current_user_id(),
        ));

        wp_send_json_success(array('message' => 'Emergency stop activated. All AI enrichment disabled.'));
    }

    /**
     * Daily cleanup: remove old logs, token records, and expired transients.
     */
    public function run_daily_cleanup() {
        $log_days   = absint( get_option( 'luwipress_log_retention_days', 30 ) );
        $token_days = absint( get_option( 'luwipress_token_retention_days', 90 ) );

        if ( method_exists( 'LuwiPress_Logger', 'cleanup' ) ) {
            LuwiPress_Logger::cleanup( $log_days );
        }
        if ( method_exists( 'LuwiPress_Token_Tracker', 'cleanup' ) ) {
            LuwiPress_Token_Tracker::cleanup( $token_days );
        }
        if ( method_exists( 'LuwiPress_Customer_Chat', 'cleanup' ) ) {
            $chat_days = absint( get_option( 'luwipress_chat_retention_days', 90 ) );
            LuwiPress_Customer_Chat::cleanup( $chat_days );
        }
        // Orphan translation scan (detect non-EN content registered as EN originals)
        $this->scan_orphan_translations();

        LuwiPress_Logger::log( 'Daily cleanup completed', 'info' );
    }

    /**
     * Scan for orphan translations — posts/products registered as EN originals
     * but containing non-English content. Logs warnings for manual review.
     */
    private function scan_orphan_translations() {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return;
        }

        global $wpdb;
        $default_lang = LuwiPress_Translation::get_default_language();

        // Find posts registered as originals (source_language_code IS NULL)
        // in the default language but created in the last 7 days
        $recent_originals = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, t.language_code
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
             WHERE t.source_language_code IS NULL
               AND t.language_code = %s
               AND t.element_type LIKE 'post_%%'
               AND p.post_status = 'publish'
               AND p.post_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY p.post_date DESC
             LIMIT 50",
            $default_lang
        ) );

        if ( empty( $recent_originals ) ) {
            return;
        }

        $orphans = array();
        foreach ( $recent_originals as $post ) {
            $title = mb_strtolower( $post->post_title );
            // Detect non-English titles by checking for common non-EN words
            $non_en_indicators = array(
                'professionale', 'argilla', 'elettro', 'strumenti', 'percussione',
                'guitarra', 'eléctric', 'percusión', 'tambor', 'cuerdas', 'madera',
                'professionnel', 'guitare', 'tambour', 'oud turc',
            );
            $matches = 0;
            foreach ( $non_en_indicators as $word ) {
                if ( false !== mb_strpos( $title, $word ) ) {
                    $matches++;
                }
            }
            if ( $matches >= 1 ) {
                $orphans[] = $post;
            }
        }

        if ( ! empty( $orphans ) ) {
            $ids = wp_list_pluck( $orphans, 'ID' );
            LuwiPress_Logger::log(
                sprintf(
                    'Orphan scan: %d suspect post(s) with non-English content registered as %s originals: %s',
                    count( $orphans ),
                    strtoupper( $default_lang ),
                    implode( ', ', array_map( function( $p ) { return '#' . $p->ID . ' "' . $p->post_title . '"'; }, $orphans ) )
                ),
                'warning'
            );
        }
    }

    /**
     * Admin init
     */
    public function admin_init() {
        // Auto-upgrade: ensure all tables exist on version change
        $db_version = get_option( 'luwipress_db_version', '0' );
        if ( version_compare( $db_version, LUWIPRESS_VERSION, '<' ) ) {
            LuwiPress_Logger::create_table();
            LuwiPress_Token_Tracker::create_table();
            LuwiPress_Customer_Chat::create_table();
            LuwiPress_Search_Index::create_table();
            LuwiPress_Bot_Account_Cleaner::create_table();
            LuwiPress_Cookie_Consent::create_table();
            LuwiPress_Bot_Shield::create_table();
            update_option( 'luwipress_db_version', LUWIPRESS_VERSION );

            // Auto-build BM25 index on first upgrade
            if ( class_exists( 'LuwiPress_Search_Index' ) ) {
                $idx = LuwiPress_Search_Index::get_instance();
                if ( ! $idx->is_indexed() ) {
                    $idx->reindex_all();
                }
            }
        }
    }

    /**
     * Strip every admin_notices / all_admin_notices callback (ours and
     * third-party) inside LuwiPress admin pages. WP injects notices between
     * the `<h1>` and the first `<div>` of page content, which collides with
     * the dashboard hero / Knowledge Graph header / Settings tabs and
     * physically pushes the layout. The status ribbon's red WC pill (and
     * other category pills) replaces any in-page warning the operator
     * needs while inside LuwiPress; native screens (Plugins list, WP
     * Dashboard home) keep getting the notices unchanged.
     */
    public function suppress_admin_notices_on_luwipress_pages() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'luwipress' ) === false ) {
            return;
        }
        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
        remove_all_actions( 'user_admin_notices' );
        remove_all_actions( 'network_admin_notices' );
    }

    /**
     * Persistent notice: WooCommerce is no longer required (3.1.38+) but most
     * value-add features need it. Show a discreet warning banner so the operator
     * knows what's disabled, with a one-click link to install WC.
     */
    public function render_woocommerce_inactive_notice() {
        if ( self::is_wc_active() ) {
            return;
        }
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        // Only show on LuwiPress admin pages to avoid notice spam across WP admin.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'luwipress' ) === false ) {
            return;
        }

        $install_url = wp_nonce_url(
            self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ),
            'install-plugin_woocommerce'
        );
        self::render_pill_notice_styles();
        $tooltip = __( 'Generic features (content scheduler, customer chat, AI provider settings, token tracking, generic SEO/AEO writers) are active. WooCommerce-dependent features are disabled: product enrichment, AEO product schema, marketplace publishing, CRM customer segmentation, product knowledge graph nodes, and review analytics.', 'luwipress' );
        ?>
        <div class="notice notice-warning notice-luwi-pill">
            <span class="luwi-pill" tabindex="0" title="<?php echo esc_attr( $tooltip ); ?>">
                <span class="luwi-pill__dot" aria-hidden="true"></span>
                <span class="luwi-pill__label"><?php esc_html_e( 'WooCommerce inactive — generic features only', 'luwipress' ); ?></span>
                <span class="luwi-pill__tip" role="tooltip" style="display:none;"><?php echo esc_html( $tooltip ); ?></span>
            </span>
            <a href="<?php echo esc_url( $install_url ); ?>" class="button button-small button-primary luwi-pill__action"><?php esc_html_e( 'Install WooCommerce', 'luwipress' ); ?></a>
        </div>
        <?php
    }

    /**
     * Inline styles for the compact pill notices — emit once per page so
     * multiple notices share one stylesheet block. Also prints an empty
     * style tag with id so subsequent calls early-return.
     */
    private static function render_pill_notice_styles() {
        static $printed = false;
        if ( $printed ) {
            return;
        }
        $printed = true;
        ?>
        <style id="luwi-pill-notice-css">
        .notice.notice-luwi-pill {
            padding: 8px 12px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin: 8px 20px 8px 2px;
        }
        .notice.notice-luwi-pill > p { margin: 0; padding: 0; }
        .luwi-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(0,0,0,.04);
            font-size: 12px;
            line-height: 1.4;
            font-weight: 600;
            color: #1d2327;
            position: relative;
            cursor: help;
            white-space: nowrap;
        }
        .luwi-pill:focus { outline: 2px solid #2271b1; outline-offset: 1px; }
        .luwi-pill__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #dba617;
        }
        .notice-info .luwi-pill__dot { background: #2271b1; }
        .notice-success .luwi-pill__dot { background: #00a32a; }
        .notice-error .luwi-pill__dot { background: #d63638; }
        .luwi-pill__tip {
            display: none !important;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            z-index: 9999;
            min-width: 280px;
            max-width: 480px;
            padding: 10px 12px;
            border-radius: 6px;
            background: #1d2327;
            color: #fff;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.5;
            white-space: normal;
            box-shadow: 0 4px 14px rgba(0,0,0,.18);
            pointer-events: none;
        }
        .luwi-pill:hover .luwi-pill__tip,
        .luwi-pill:focus .luwi-pill__tip,
        .luwi-pill:focus-within .luwi-pill__tip {
            display: block !important;
        }
        .luwi-pill__action { margin-left: 4px !important; }
        </style>
        <?php
    }

    /**
     * One-time migration notice: Open Claw moved to a companion plugin in 3.1.0.
     */
    public function render_open_claw_moved_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) return;
        if ( class_exists( 'LuwiPress_Open_Claw' ) || defined( 'LUWIPRESS_OPEN_CLAW_VERSION' ) ) return;
        if ( get_option( 'luwipress_open_claw_moved_dismissed', false ) ) return;
        // Migration notice: only show if the site previously had Open Claw
        // enabled (i.e. is upgrading from pre-3.1.0). Fresh installs never
        // had Open Claw so there's nothing to migrate. Without this gate the
        // notice appeared on every clean install (Birikim 3.1.38) as a
        // confusing "what's this?" message for a feature the user never used.
        if ( false === get_option( 'luwipress_open_claw_enabled', false ) ) {
            return;
        }
        // Match the WC notice scoping: only show on LuwiPress admin pages.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'luwipress' ) === false ) {
            return;
        }
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'luwipress_dismiss_open_claw_notice', '1' ),
            'luwipress_dismiss_open_claw_notice'
        );
        self::render_pill_notice_styles();
        $tooltip = __( 'The admin AI assistant now lives in the separate "LuwiPress Open Claw" plugin. Install and activate it to keep the LuwiPress → Open Claw menu.', 'luwipress' );
        ?>
        <div class="notice notice-info notice-luwi-pill">
            <span class="luwi-pill" tabindex="0" title="<?php echo esc_attr( $tooltip ); ?>">
                <span class="luwi-pill__dot" aria-hidden="true"></span>
                <span class="luwi-pill__label"><?php esc_html_e( 'Open Claw moved to companion plugin (3.1+)', 'luwipress' ); ?></span>
                <span class="luwi-pill__tip" role="tooltip" style="display:none;"><?php echo esc_html( $tooltip ); ?></span>
            </span>
            <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-small luwi-pill__action"><?php esc_html_e( 'Dismiss', 'luwipress' ); ?></a>
        </div>
        <?php
    }

    public function handle_open_claw_notice_dismiss() {
        if ( empty( $_GET['luwipress_dismiss_open_claw_notice'] ) ) return;
        if ( ! current_user_can( 'activate_plugins' ) ) return;
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'luwipress_dismiss_open_claw_notice' ) ) return;
        update_option( 'luwipress_open_claw_moved_dismissed', true );
        wp_safe_redirect( remove_query_arg( array( 'luwipress_dismiss_open_claw_notice', '_wpnonce' ) ) );
        exit;
    }

    /**
     * One-time migration notice: WebMCP moved to a companion plugin in 3.0.0.
     * Only shown if the site was previously using WebMCP (enabled option present)
     * and the companion isn't installed yet. Dismissable.
     */
    public function render_webmcp_moved_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        // Only show if the old enabled flag exists (site had WebMCP turned on before 3.0.0)
        if ( false === get_option( 'luwipress_webmcp_enabled', false ) ) {
            return;
        }
        // Hide if companion is active
        if ( class_exists( 'LuwiPress_WebMCP' ) || defined( 'LUWIPRESS_WEBMCP_VERSION' ) ) {
            return;
        }
        // Hide if dismissed
        if ( get_option( 'luwipress_webmcp_moved_dismissed', false ) ) {
            return;
        }
        // Match the WC notice scoping: only show on LuwiPress admin pages.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'luwipress' ) === false ) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg( 'luwipress_dismiss_webmcp_notice', '1' ),
            'luwipress_dismiss_webmcp_notice'
        );
        self::render_pill_notice_styles();
        $tooltip = __( 'Your MCP configuration is preserved, but the WebMCP endpoint and admin page now live in the separate "LuwiPress WebMCP" plugin. Install and activate it to restore AI-agent integration.', 'luwipress' );
        ?>
        <div class="notice notice-info notice-luwi-pill">
            <span class="luwi-pill" tabindex="0" title="<?php echo esc_attr( $tooltip ); ?>">
                <span class="luwi-pill__dot" aria-hidden="true"></span>
                <span class="luwi-pill__label"><?php esc_html_e( 'WebMCP moved to companion plugin (3.0+)', 'luwipress' ); ?></span>
                <span class="luwi-pill__tip" role="tooltip" style="display:none;"><?php echo esc_html( $tooltip ); ?></span>
            </span>
            <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-small luwi-pill__action"><?php esc_html_e( 'Dismiss', 'luwipress' ); ?></a>
        </div>
        <?php
    }

    /**
     * Handle dismissal of the WebMCP migration notice.
     */
    public function handle_webmcp_notice_dismiss() {
        if ( empty( $_GET['luwipress_dismiss_webmcp_notice'] ) ) {
            return;
        }
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'luwipress_dismiss_webmcp_notice' ) ) {
            return;
        }
        update_option( 'luwipress_webmcp_moved_dismissed', true );
        wp_safe_redirect( remove_query_arg( array( 'luwipress_dismiss_webmcp_notice', '_wpnonce' ) ) );
        exit;
    }

    /**
     * Render an admin notice when WP-Cron is disabled.
     *
     * Many LuwiPress features (translation queue, content scheduler, enrichment,
     * CRM refresh) rely on WP-Cron. If DISABLE_WP_CRON is true and no external
     * trigger is confirmed, jobs sit unexecuted.
     */
    public function render_cron_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
            return;
        }

        if ( get_option( 'luwipress_external_cron_confirmed', false ) ) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg( 'luwipress_dismiss_cron_notice', '1' ),
            'luwipress_dismiss_cron_notice'
        );

        $cron_url = site_url( 'wp-cron.php?doing_wp_cron' );
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'LuwiPress — WP-Cron is disabled.', 'luwipress' ); ?></strong>
                <?php esc_html_e( 'DISABLE_WP_CRON is defined as true in wp-config.php. Background jobs (translation queue, content scheduler, product enrichment, CRM refresh) will not run automatically until an external cron trigger is configured.', 'luwipress' ); ?>
            </p>
            <p>
                <?php esc_html_e( 'Add a server cron entry that hits this URL every 5 minutes:', 'luwipress' ); ?>
                <br><code>*/5 * * * * wget -q -O - <?php echo esc_url( $cron_url ); ?> &gt;/dev/null 2&gt;&amp;1</code>
            </p>
            <p>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Dismiss — external cron is configured', 'luwipress' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Handle the cron notice dismiss link.
     *
     * Sets a site-wide option so the notice stays hidden. If WP-Cron is later
     * re-enabled (DISABLE_WP_CRON removed from wp-config.php), the confirmation
     * is cleared automatically so the warning returns if cron gets disabled again.
     */
    public function handle_cron_notice_dismiss() {
        // Auto-clear confirmation when WP-Cron is no longer disabled.
        if ( ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) && get_option( 'luwipress_external_cron_confirmed', false ) ) {
            delete_option( 'luwipress_external_cron_confirmed' );
        }

        if ( empty( $_GET['luwipress_dismiss_cron_notice'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'luwipress_dismiss_cron_notice' ) ) {
            return;
        }

        update_option( 'luwipress_external_cron_confirmed', true );

        wp_safe_redirect( remove_query_arg( array( 'luwipress_dismiss_cron_notice', '_wpnonce' ) ) );
        exit;
    }

    /**
     * Render chat assets directly to the footer.
     *
     * Why not wp_enqueue_*:
     *   Cache plugins (LiteSpeed, WP Rocket, W3TC) frequently combine/defer
     *   or strip enqueued chat assets, leaving the launcher invisible despite
     *   the config being present. We bypass the enqueue pipeline entirely
     *   and echo the CSS inline + JS with data-no-optimize attributes so
     *   optimizers know to leave them alone.
     */
    public function render_chat_assets() {
        if ( is_admin() ) {
            return;
        }
        if ( ! get_option( 'luwipress_chat_enabled', 0 ) ) {
            return;
        }

        $css_file = LUWIPRESS_PLUGIN_DIR . 'assets/css/luwipress-chat.css';
        $js_file  = LUWIPRESS_PLUGIN_DIR . 'assets/js/luwipress-chat.js';
        if ( ! file_exists( $css_file ) || ! file_exists( $js_file ) ) {
            return;
        }

        // Detect the page's current language and read the localized chip
        // vocab for it. Translation itself is fire-and-forget via wp_cron
        // so first-visitor page loads never block on AI latency — they
        // see English fallback once, then cached language pack from the
        // next visit forward.
        $chat   = LuwiPress_Customer_Chat::get_instance();
        $lang   = $chat->detect_chat_language();
        $pack   = $chat->localize_chips( $lang );
        // Also queue translations for every other active site language
        // (WPML/Polylang) so a customer's first visit in FR/IT/ES doesn't
        // wait on the AI either.
        $chat->warm_chip_packs();

        $config = array(
            'rest_url'           => rest_url( 'luwipress/v1/chat/' ),
            'nonce'              => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
            'store_name'         => get_bloginfo( 'name' ),
            'greeting'           => get_option( 'luwipress_chat_greeting', 'Hi! How can I help you today?' ),
            'primary'            => get_option( 'luwipress_chat_color_primary', '#6366f1' ),
            'text_color'         => get_option( 'luwipress_chat_color_text', '#ffffff' ),
            'position'           => get_option( 'luwipress_chat_position', 'bottom-right' ),
            'escalation_channel' => get_option( 'luwipress_chat_escalation_channel', 'whatsapp' ),
            'whatsapp_number'    => get_option( 'luwipress_whatsapp_number', '' ),
            'telegram_username'  => get_option( 'luwipress_telegram_username', '' ),
            'is_logged_in'       => is_user_logged_in(),
            'customer_name'      => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            'fallback_categories' => $this->get_chat_fallback_categories(),
            'lang'                => $lang,
            'chip_pack'           => $pack,
        );

        $css      = file_get_contents( $css_file );
        $js       = file_get_contents( $js_file );
        $chat_ver = LUWIPRESS_VERSION . '.' . filemtime( $js_file );

        // Optimizer-bypass attributes. Recognized by LiteSpeed, WP Rocket, Autoptimize, Cloudflare.
        $skip_attrs = 'data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false"';

        echo "\n<!-- luwipress-chat (v{$chat_ver}) render_chat_assets() -->\n";
        echo '<style id="luwipress-chat-inline-css" ' . $skip_attrs . '>' . $css . '</style>' . "\n";
        echo '<script id="luwipress-chat-config" ' . $skip_attrs . '>window.lpChat=' . wp_json_encode( $config ) . ';</script>' . "\n";
        echo '<script id="luwipress-chat-inline-js" ' . $skip_attrs . '>' . $js . '</script>' . "\n";
    }

    /**
     * Top product categories for the chat widget's fallback chip set.
     *
     * Served from a 1h transient so the wp_head render path stays cheap;
     * sites without WooCommerce return an empty array (chip set then degrades
     * to "Talk to our team" + canned shipping/returns questions).
     */
    private function get_chat_fallback_categories() {
        $cached = get_transient( 'luwipress_chat_fallback_cats' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $names = array();
        if ( taxonomy_exists( 'product_cat' ) ) {
            $terms = get_terms( array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'number'     => 4,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ) );
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    if ( $term->name !== '' && strtolower( $term->slug ) !== 'uncategorized' ) {
                        $names[] = $term->name;
                    }
                }
            }
        }

        set_transient( 'luwipress_chat_fallback_cats', $names, HOUR_IN_SECONDS );
        return $names;
    }

    /**
     * Admin enqueue scripts
     */
    public function admin_enqueue_scripts($hook) {
        // Match BOTH the page-load hook ($hook) and the screen ID, because
        // companions (luwipress-webmcp) and themes can register pages whose
        // hook name doesn't start with our parent slug but whose screen ID
        // does. Belt-and-braces: if either matches, enqueue.
        $screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $screen_id = $screen ? (string) $screen->id : '';
        $is_luwipress_page = strpos( (string) $hook, 'luwipress' ) !== false
                          || strpos( $screen_id, 'luwipress' ) !== false;
        if ($is_luwipress_page) {
            // Thickbox: powers the wordpress.org plugin-information modal that
            // the dashboard ribbon's red pills link to. Without this, clicking
            // a "No SMTP" / "No cache plugin" pill would just open a blank
            // iframe popup instead of the proper plugin detail card.
            add_thickbox();

            // Cache-bust on file mtime (not just plugin version) so CSS tweaks
            // shipped within the same version are picked up immediately — the JS
            // already does this; the stylesheet must too or browsers serve a
            // stale admin.css on every `?ver=<same-version>` request.
            $admin_css_path = LUWIPRESS_PLUGIN_DIR . 'assets/css/admin.css';
            $admin_css_ver  = LUWIPRESS_VERSION . '.' . ( file_exists( $admin_css_path ) ? filemtime( $admin_css_path ) : '0' );
            wp_enqueue_style(
                'luwipress-admin',
                LUWIPRESS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                $admin_css_ver
            );

            $admin_js_path = LUWIPRESS_PLUGIN_DIR . 'assets/js/admin.js';
            $admin_js_ver  = LUWIPRESS_VERSION . '.' . ( file_exists( $admin_js_path ) ? filemtime( $admin_js_path ) : '0' );
            wp_enqueue_script(
                'luwipress-admin',
                LUWIPRESS_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                $admin_js_ver,
                true
            );

            // Shared live-update primitive (polling, countUp, sparkline, barMeter).
            // Lightweight zero-dep module; safe to load on every LuwiPress admin page.
            wp_enqueue_script(
                'luwipress-live',
                LUWIPRESS_PLUGIN_URL . 'assets/js/luwi-live.js',
                array(),
                LUWIPRESS_VERSION,
                true
            );
            wp_localize_script('luwipress-live', 'luwipressLive', array(
                'rest_base'  => esc_url_raw( rest_url( 'luwipress/v1/' ) ),
                'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            ));

            // Theme page — page-specific tools UI (scan/execute/restore + settings save).
            // The Theme surface now lives as the Site hub's `?tab=theme` (3.7.3
            // merge) as well as the legacy standalone slug, so gate on both.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
            $lwp_active_tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
            $lwp_theme_surface = ( false !== strpos( $hook, 'luwipress-theme' ) )
                || ( 'luwipress_page_luwipress-theme' === $screen_id )
                || ( false !== strpos( $screen_id, 'luwipress-site' ) && 'theme' === $lwp_active_tab );
            if ( $lwp_theme_surface ) {
                $tools_js_path = LUWIPRESS_PLUGIN_DIR . 'assets/js/theme-tools.js';
                $tools_js_ver  = LUWIPRESS_VERSION . '.' . ( file_exists( $tools_js_path ) ? filemtime( $tools_js_path ) : '0' );
                wp_enqueue_script(
                    'luwipress-theme-tools',
                    LUWIPRESS_PLUGIN_URL . 'assets/js/theme-tools.js',
                    array(),
                    $tools_js_ver,
                    true
                );
                wp_localize_script( 'luwipress-theme-tools', 'luwipressThemeTools', array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( 'luwipress_theme_tools' ),
                    'i18n'    => array(
                        'scanning'        => __( 'Scanning…', 'luwipress' ),
                        'executing'       => __( 'Executing…', 'luwipress' ),
                        'restoring'       => __( 'Restoring…', 'luwipress' ),
                        'saving'          => __( 'Saving…', 'luwipress' ),
                        'no_candidates'   => __( 'No candidates found.', 'luwipress' ),
                        'select_to_run'   => __( 'Select rows to enable Execute.', 'luwipress' ),
                        'confirm_destruct'=> __( 'This will mutate %d post(s). Continue?', 'luwipress' ),
                        'confirm_wpml'    => __( 'WPML/Polylang siblings will be mutated together. Continue?', 'luwipress' ),
                        'no_backups'      => __( 'No backups for this tool.', 'luwipress' ),
                        'restored'        => __( 'Restored.', 'luwipress' ),
                        'saved'           => __( 'Saved.', 'luwipress' ),
                        'error'           => __( 'Error: ', 'luwipress' ),
                    ),
                ) );
            }

            // Usage & Logs — page-specific live glue.
            if ( false !== strpos( $hook, 'luwipress-usage' ) ) {
                wp_enqueue_script(
                    'luwipress-usage-live',
                    LUWIPRESS_PLUGIN_URL . 'assets/js/usage-live.js',
                    array('luwipress-live'),
                    LUWIPRESS_VERSION,
                    true
                );
            }

            $user = wp_get_current_user();
            wp_localize_script('luwipress-admin', 'luwipress', array(
                'ajax_url'     => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('luwipress_dashboard_nonce'),
                'claw_nonce'   => wp_create_nonce('luwipress_claw_nonce'),
                'user_initial' => mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ),
                'rest_root'    => esc_url_raw( rest_url() ),
                'rest_base'    => esc_url_raw( rest_url( 'luwipress/v1/' ) ),
                'nonce_rest'   => wp_create_nonce( 'wp_rest' ),
                'i18n'         => array(
                    // Scheduler — confirms
                    'confirm_delete_item'      => __( 'Delete this scheduled item?', 'luwipress' ),
                    'confirm_delete_plan'      => __( 'Delete this recurring plan? Already-queued topics are kept — only the auto-brainstorm stops.', 'luwipress' ),
                    'confirm_run_pending'      => __( 'Run up to 10 pending items through AI generation right now?', 'luwipress' ),
                    'confirm_regen_outline'    => __( 'Discard this outline and regenerate from scratch?', 'luwipress' ),
                    'confirm_bulk_publish'     => __( 'Publish %d selected draft(s)?', 'luwipress' ),
                    'confirm_bulk_retry'       => __( 'Retry %d selected item(s)?', 'luwipress' ),
                    'confirm_bulk_delete'      => __( 'Delete %d selected item(s)? This cannot be undone.', 'luwipress' ),
                    'confirm_enrich_batch'     => __( 'Start bulk AI enrichment for thin content products?', 'luwipress' ),
                    'confirm_enrich_batch50'   => __( 'Send up to 50 thin content products for AI enrichment? This will trigger AI enrichment.', 'luwipress' ),
                    'confirm_refresh_stale'    => __( 'Refresh stale content via AI enrichment?', 'luwipress' ),
                    // Scheduler — errors / validation
                    'err_need_topic'           => __( 'Add at least one topic to continue.', 'luwipress' ),
                    'err_topic_limit'          => __( 'Maximum 50 topics per batch. Please trim the list.', 'luwipress' ),
                    'err_need_date'            => __( 'Pick a start date.', 'luwipress' ),
                    'err_need_theme'           => __( 'Please enter a theme.', 'luwipress' ),
                    'err_need_title'           => __( 'Give the article a title first.', 'luwipress' ),
                    'err_need_section'         => __( 'Outline needs at least one section.', 'luwipress' ),
                    'err_keep_one_section'     => __( 'Keep at least one section.', 'luwipress' ),
                    'err_pick_topic'           => __( 'Pick at least one topic.', 'luwipress' ),
                    'err_plan_required'        => __( 'Name and theme are required.', 'luwipress' ),
                    'err_no_thin'              => __( 'No thin content found to enrich. Run a scan first.', 'luwipress' ),
                    'err_no_thin_products'     => __( 'No thin products found.', 'luwipress' ),
                    'err_fetch_thin'           => __( 'Failed to fetch thin products.', 'luwipress' ),
                    'err_request_failed'       => __( 'Request failed. Please try again.', 'luwipress' ),
                    'err_brainstorm'           => __( 'Brainstorm failed.', 'luwipress' ),
                    'err_brainstorm_empty'     => __( 'No topics returned — try a more specific theme.', 'luwipress' ),
                    'err_save_failed'          => __( 'Save failed.', 'luwipress' ),
                    'err_retry_failed'         => __( 'Retry failed.', 'luwipress' ),
                    'err_bulk_failed'          => __( 'Bulk action failed.', 'luwipress' ),
                    'err_enrich_failed'        => __( 'Enrich failed.', 'luwipress' ),
                    'err_regen_failed'         => __( 'Regenerate failed.', 'luwipress' ),
                    'err_outline_load'         => __( 'Could not load outline.', 'luwipress' ),
                    'err_estimate'             => __( 'Unable to estimate.', 'luwipress' ),
                    'err_generic'              => __( 'Error', 'luwipress' ),
                    // Scheduler — success / status
                    'ok_processed'             => __( 'Processed %d item(s).', 'luwipress' ),
                    'ok_batch_started'         => __( 'Batch enrichment started — %d products queued.', 'luwipress' ),
                    'ok_queued'                => __( 'Queued %d topic(s).', 'luwipress' ),
                    'ok_queued_with_skip'      => __( 'Queued %1$d topic(s) · %2$d skipped.', 'luwipress' ),
                    'ok_refreshing'            => __( 'Refreshing…', 'luwipress' ),
                    // Scheduler — loading / ephemeral
                    'loading_calc'             => __( 'Calculating…', 'luwipress' ),
                    'loading_thinking'         => __( 'Thinking…', 'luwipress' ),
                    'loading_ideas'            => __( 'Generating ideas…', 'luwipress' ),
                    'loading_outline'          => __( 'Loading outline…', 'luwipress' ),
                    'loading_regen'            => __( 'Regenerating…', 'luwipress' ),
                    'loading_saving'           => __( 'Saving…', 'luwipress' ),
                    'loading_queuing'          => __( 'Queuing…', 'luwipress' ),
                    'loading_running'          => __( 'Running…', 'luwipress' ),
                    // Scheduler — labels
                    'lbl_retry'                => __( 'Retry', 'luwipress' ),
                    'lbl_enrich'               => __( 'Enrich', 'luwipress' ),
                    'lbl_run_pending'          => __( 'Run pending now', 'luwipress' ),
                    'lbl_queue_all'            => __( 'Queue all topics', 'luwipress' ),
                    'lbl_regen_outline'        => __( 'Regenerate outline', 'luwipress' ),
                    'lbl_approve_generate'     => __( 'Approve & generate article', 'luwipress' ),
                    'lbl_save_plan'            => __( 'Save plan', 'luwipress' ),
                    'lbl_select_all'           => __( 'Select all (%d)', 'luwipress' ),
                    'lbl_add_picked'           => __( 'Add picked to queue', 'luwipress' ),
                    'lbl_topics_count'         => __( '%1$d / %2$d', 'luwipress' ),
                    'lbl_confirm'              => __( 'Confirm', 'luwipress' ),
                    'lbl_confirm_delete'       => __( 'Delete', 'luwipress' ),
                    'lbl_cancel'               => __( 'Cancel', 'luwipress' ),
                    'lbl_generate_ideas'       => __( 'Generate ideas', 'luwipress' ),
                    'title_confirm_delete'     => __( 'Delete item', 'luwipress' ),
                    'title_confirm_bulk_del'   => __( 'Delete selected items', 'luwipress' ),
                    'title_confirm_regen'      => __( 'Regenerate outline', 'luwipress' ),
                    'title_confirm_run_now'    => __( 'Run pending now', 'luwipress' ),
                    'title_confirm_plan_del'   => __( 'Delete recurring plan', 'luwipress' ),
                    'title_confirm_bulk_pub'   => __( 'Publish selected items', 'luwipress' ),
                    'title_confirm_bulk_retry' => __( 'Retry selected items', 'luwipress' ),
                ),
            ));
        }

        // Knowledge Graph page — separate script + style bundle.
        // 2,200+ lines of D3 code and 1,000+ lines of CSS; no point loading on every admin page.
        if ( false !== strpos( $hook, 'luwipress-knowledge-graph' ) ) {
            $kg_css_path = LUWIPRESS_PLUGIN_DIR . 'assets/css/knowledge-graph.css';
            $kg_css_ver  = LUWIPRESS_VERSION . '.' . ( file_exists( $kg_css_path ) ? filemtime( $kg_css_path ) : '0' );
            wp_enqueue_style(
                'luwipress-knowledge-graph',
                LUWIPRESS_PLUGIN_URL . 'assets/css/knowledge-graph.css',
                array( 'luwipress-admin' ),
                $kg_css_ver
            );
            // Use filemtime as cache buster -- in-place hotfixes that don't bump
            // LUWIPRESS_VERSION (e.g. JS-only chip-render fixes) still get a fresh
            // ?ver=... so browsers + LiteSpeed JS minify cache invalidate immediately.
            $kg_js_path = LUWIPRESS_PLUGIN_DIR . 'assets/js/knowledge-graph.js';
            $kg_js_ver  = LUWIPRESS_VERSION . '.' . ( file_exists( $kg_js_path ) ? filemtime( $kg_js_path ) : '0' );
            wp_enqueue_script(
                'luwipress-knowledge-graph',
                LUWIPRESS_PLUGIN_URL . 'assets/js/knowledge-graph.js',
                array(),
                $kg_js_ver,
                true
            );
            wp_localize_script( 'luwipress-knowledge-graph', 'lpKgConfig', array(
                'apiUrl'   => esc_url_raw( rest_url( 'luwipress/v1/knowledge-graph' ) ),
                'apiToken' => (string) get_option( 'luwipress_seo_api_token', '' ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'restBase' => esc_url_raw( rest_url( 'luwipress/v1/' ) ),
            ) );
        }

    }

    /**
    /**
     * AJAX: Scan content opportunities
     */
    public function ajax_scan_opportunities() {
        check_ajax_referer('luwipress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $config = LuwiPress_Site_Config::get_instance();
        $request = new WP_REST_Request('GET', '/luwipress/v1/content/opportunities');
        $request->set_param('limit', 50);
        $response = $config->get_content_opportunities($request);
        $data = $response->get_data();

        wp_send_json_success(array(
            'missing_seo_meta'     => count($data['missing_seo_meta'] ?? array()),
            'missing_translations' => count($data['missing_translations'] ?? array()),
            'stale_content'        => count($data['stale_content'] ?? array()),
            'thin_content'         => count($data['thin_content'] ?? array()),
            'missing_alt_text'     => count($data['missing_alt_text'] ?? array()),
        ));
    }

    /**
     * AJAX: Get thin product IDs for bulk enrichment
     */
    public function ajax_get_thin_products() {
        check_ajax_referer('luwipress_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $threshold = absint(get_option('luwipress_thin_content_threshold', 300));

        global $wpdb;
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_luwipress_enrich_status'
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND LENGTH(p.post_content) < %d
               AND (pm.meta_value IS NULL OR pm.meta_value NOT IN ('processing', 'queued'))
             ORDER BY LENGTH(p.post_content) ASC
             LIMIT 50",
            $threshold
        ));

        wp_send_json_success(array('product_ids' => array_map('absint', $product_ids)));
    }

    /**
     * AJAX: Dashboard data — all stats in one call for fast render.
     */
    public function ajax_dashboard_data() {
        check_ajax_referer( 'luwipress_dashboard_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Hero stats
        $product_counts = wp_count_posts( 'product' );
        $total_products = absint( $product_counts->publish ?? 0 );

        // Revenue (30d)
        $revenue_30d = 0;
        $orders_30d  = 0;
        if ( class_exists( 'WooCommerce' ) ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(*) as cnt, COALESCE(SUM(pm.meta_value),0) as total
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                 WHERE p.post_type IN ('shop_order','shop_order_placehold')
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date >= %s",
                gmdate( 'Y-m-d', strtotime( '-30 days' ) )
            ) );
            if ( $row ) {
                $revenue_30d = floatval( $row->total );
                $orders_30d  = intval( $row->cnt );
            }
        }

        // Token stats
        $token_stats = class_exists( 'LuwiPress_Token_Tracker' ) ? LuwiPress_Token_Tracker::get_stats( 30 ) : null;
        $today_calls = $token_stats ? intval( $token_stats['today']['calls'] ) : 0;
        $today_cost  = $token_stats ? floatval( $token_stats['today']['cost'] ) : 0;
        $limit_pct   = $token_stats ? intval( $token_stats['limit_used'] ) : 0;

        // 7-day cost breakdown
        $daily_costs = array();
        if ( class_exists( 'LuwiPress_Token_Tracker' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'luwipress_token_usage';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT DATE(created_at) as day, SUM(estimated_cost) as cost
                     FROM {$table}
                     WHERE created_at >= %s
                     GROUP BY DATE(created_at)
                     ORDER BY day ASC",
                    gmdate( 'Y-m-d', strtotime( '-6 days' ) )
                ) );
                $day_map = array();
                foreach ( $rows as $r ) {
                    $day_map[ $r->day ] = floatval( $r->cost );
                }
                for ( $i = 6; $i >= 0; $i-- ) {
                    $d = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
                    $daily_costs[] = array(
                        'day'   => gmdate( 'D', strtotime( $d ) ),
                        'date'  => $d,
                        'cost'  => $day_map[ $d ] ?? 0,
                    );
                }
            }
        }

        // Opportunities (reuse existing scan)
        $opps = array();
        if ( class_exists( 'LuwiPress_Site_Config' ) ) {
            $config  = LuwiPress_Site_Config::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/opportunities' );
            $request->set_param( 'limit', 50 );
            $response = $config->get_content_opportunities( $request );
            $data     = $response->get_data();
            $opps = array(
                'missing_translations' => count( $data['missing_translations'] ?? array() ),
                'thin_content'         => count( $data['thin_content'] ?? array() ),
                'missing_seo'          => count( $data['missing_seo_meta'] ?? array() ),
                'missing_alt'          => count( $data['missing_alt_text'] ?? array() ),
                'stale_content'        => count( $data['stale_content'] ?? array() ),
            );
        }

        // Content health percentages
        $total_content   = $total_products > 0 ? $total_products : 1;
        $optimized_count = $total_content - ( $opps['thin_content'] ?? 0 ) - ( $opps['missing_seo'] ?? 0 );
        $optimized_pct   = max( 0, round( ( $optimized_count / $total_content ) * 100 ) );

        // Recent logs
        $logs = array();
        if ( class_exists( 'LuwiPress_Logger' ) ) {
            $raw_logs = LuwiPress_Logger::get_logs( 8 );
            foreach ( $raw_logs as $log ) {
                $logs[] = array(
                    'level'   => $log->level,
                    'message' => $log->message,
                    'time'    => human_time_diff( strtotime( $log->timestamp ), current_time( 'timestamp' ) ),
                );
            }
        }

        // Translation coverage
        $trans_coverage = array();
        $target_langs = get_option( 'luwipress_translation_languages', array() );
        if ( ! empty( $target_langs ) && class_exists( 'LuwiPress_Translation' ) ) {
            global $wpdb;
            $meta_keys = array();
            foreach ( $target_langs as $lang ) {
                $meta_keys[] = '_luwipress_translation_' . $lang . '_status';
            }
            $key_ph = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, COUNT(DISTINCT post_id) AS cnt
                 FROM {$wpdb->postmeta}
                 WHERE meta_key IN ({$key_ph}) AND meta_value = 'completed'
                 GROUP BY meta_key",
                $meta_keys
            ) );
            $done_map = array();
            foreach ( $rows as $r ) {
                $done_map[ $r->meta_key ] = intval( $r->cnt );
            }
            foreach ( $target_langs as $lang ) {
                $key  = '_luwipress_translation_' . $lang . '_status';
                $done = $done_map[ $key ] ?? 0;
                $trans_coverage[ $lang ] = $total_products > 0 ? round( ( $done / $total_products ) * 100 ) : 0;
            }
        }

        // Workflow/model breakdown (last 30 days)
        $workflow_breakdown = array();
        $model_breakdown    = array();
        if ( class_exists( 'LuwiPress_Token_Tracker' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'luwipress_token_usage';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                // By workflow
                $wf_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT workflow, COUNT(*) AS calls, SUM(input_tokens) AS input_tok, SUM(output_tokens) AS output_tok, SUM(estimated_cost) AS cost
                     FROM {$table} WHERE created_at >= %s GROUP BY workflow ORDER BY cost DESC",
                    gmdate( 'Y-m-d', strtotime( '-30 days' ) )
                ) );
                foreach ( $wf_rows as $r ) {
                    $workflow_breakdown[] = array(
                        'workflow'      => $r->workflow,
                        'calls'         => intval( $r->calls ),
                        'input_tokens'  => intval( $r->input_tok ),
                        'output_tokens' => intval( $r->output_tok ),
                        'cost'          => round( floatval( $r->cost ), 4 ),
                    );
                }
                // By model
                $m_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT model, provider, COUNT(*) AS calls, SUM(input_tokens) AS input_tok, SUM(output_tokens) AS output_tok, SUM(estimated_cost) AS cost
                     FROM {$table} WHERE created_at >= %s GROUP BY model, provider ORDER BY cost DESC",
                    gmdate( 'Y-m-d', strtotime( '-30 days' ) )
                ) );
                foreach ( $m_rows as $r ) {
                    $model_breakdown[] = array(
                        'model'         => $r->model,
                        'provider'      => $r->provider,
                        'calls'         => intval( $r->calls ),
                        'input_tokens'  => intval( $r->input_tok ),
                        'output_tokens' => intval( $r->output_tok ),
                        'cost'          => round( floatval( $r->cost ), 4 ),
                    );
                }
            }
        }

        wp_send_json_success( array(
            'products'           => $total_products,
            'revenue'            => $revenue_30d,
            'orders'             => $orders_30d,
            'ai_calls'           => $today_calls,
            'ai_cost_today'      => $today_cost,
            'budget_pct'         => $limit_pct,
            'daily_costs'        => $daily_costs,
            'workflow_breakdown' => $workflow_breakdown,
            'model_breakdown'    => $model_breakdown,
            'opportunities'      => $opps,
            'health_pct'         => $optimized_pct,
            'health_thin'        => $opps['thin_content'] ?? 0,
            'health_seo'         => $opps['missing_seo'] ?? 0,
            'logs'               => $logs,
            'trans_coverage'     => $trans_coverage,
            'currency'           => class_exists( 'WooCommerce' ) ? get_woocommerce_currency_symbol() : '$',
        ) );
    }

    /**
     * Set default options
     */
    private function set_default_options() {
        $defaults = array(
            'luwipress_enable_logging'    => 1,
            'luwipress_log_level'         => 'info',
            'luwipress_rate_limit'        => 1000,
            'luwipress_security_headers'  => 1,
            'luwipress_webhook_timeout'   => 30,
            'luwipress_ai_provider'       => 'openai',
            'luwipress_ai_model'          => 'gpt-4o-mini',
            'luwipress_daily_token_limit' => 1.00,
            // AI Engine defaults (v2.0)
            'luwipress_default_provider'  => 'anthropic',
            'luwipress_anthropic_model'   => 'claude-haiku-4-5-20241022',
            'luwipress_openai_model'      => 'gpt-4o-mini',
            'luwipress_google_model'      => 'gemini-2.0-flash',
        );
        
        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }

        // Legacy: external webhook support was removed in v2.0 — processing mode is always 'local'.
    }
}

// Initialize plugin
function luwipress_init() {
    return LuwiPress::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'luwipress_init');

// Global helper functions
function luwipress_log($message, $level = 'info', $context = array()) {
    if (class_exists('LuwiPress_Logger')) {
        LuwiPress_Logger::log($message, $level, $context);
    }
}

function luwipress_get_option($option, $default = false) {
    return get_option('luwipress_' . $option, $default);
}

/**
 * Build the standard _meta block sent with every webhook payload.
 *
 * Workflows read $json.body._meta.* so they never need per-site env variables.
 * Only the user's AI API key stays in provider credentials.
 *
 * @param string $callback_url  Full REST URL webhook should POST results back to.
 * @return array
 */
function luwipress_build_meta_block( $callback_url = '' ) {
    return array(
        'site_url'       => get_site_url(),
        'rest_base'      => rest_url( 'luwipress/v1/' ),
        'callback_url'   => $callback_url,
        'api_token'      => get_option( 'luwipress_seo_api_token', '' ),
        'provider'       => get_option( 'luwipress_ai_provider', 'openai' ),
        'model'          => get_option( 'luwipress_ai_model', 'gpt-4o-mini' ),
        'max_tokens'     => absint( get_option( 'luwipress_max_output_tokens', 1024 ) ),
        'image_provider' => get_option( 'luwipress_image_provider', 'dall-e-3' ),
        'language'       => get_option( 'luwipress_target_language', 'en' ),
        'site_name'      => get_bloginfo( 'name' ),
        'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
    );
}