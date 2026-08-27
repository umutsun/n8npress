<?php
/**
 * LuwiPress Translation Manager
 *
 * REST API endpoints for managing product translations.
 * Integrates with WPML/Polylang via the LuwiPress AI translation engine.
 *
 * @package LuwiPress
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class LuwiPress_Translation {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private static $detector_cache = null;

    /**
     * Default language code from Plugin Detector - works for WPML, Polylang, TranslatePress.
     * apply_filters("wpml_default_language", ...) only fires when WPML is active; on Polylang
     * sites it falls through to get_locale() which returns "en_US" instead of "en" and
     * silently breaks every WHERE language_code = X query downstream. Static + public so all
     * LuwiPress modules (AI Content, Elementor, Knowledge Graph, etc.) share one source.
     */
    public static function get_default_language() {
        if ( null === self::$detector_cache && class_exists( 'LuwiPress_Plugin_Detector' ) ) {
            self::$detector_cache = LuwiPress_Plugin_Detector::get_instance()->detect_translation();
        }
        $lang = self::$detector_cache['default_language'] ?? get_locale();
        return self::normalize_language_code( $lang );
    }

    /**
     * Active language codes from Plugin Detector. WPML/Polylang/TranslatePress aware.
     */
    public static function get_active_languages() {
        if ( null === self::$detector_cache && class_exists( 'LuwiPress_Plugin_Detector' ) ) {
            self::$detector_cache = LuwiPress_Plugin_Detector::get_instance()->detect_translation();
        }
        $langs = self::$detector_cache['active_languages'] ?? array( get_locale() );
        return array_map( array( __CLASS__, 'normalize_language_code' ), $langs );
    }

    /**
     * Configured translation target languages with a sane fallback: the
     * luwipress_translation_languages option when set, otherwise every active
     * language except the default. Never returns the default language itself.
     */
    public static function get_configured_target_languages() {
        $configured = array_values( array_filter( array_map( 'trim', (array) get_option( 'luwipress_translation_languages', array() ) ) ) );
        $default    = self::get_default_language();
        if ( empty( $configured ) ) {
            $configured = self::get_active_languages();
        }
        return array_values( array_filter( $configured, static function ( $l ) use ( $default ) {
            return '' !== $l && $l !== $default;
        } ) );
    }

    /**
     * Normalize "en_US" / "pt_BR" to "en" / "pt-br" so codes match WPML/Polylang storage format.
     * Keeps real multi-region WPML codes (pt-br, zh-cn) intact while collapsing locale-only
     * forms (en_US -> en) that come from get_locale() fallback.
     */
    private static function normalize_language_code( $code ) {
        $code = strtolower( str_replace( '_', '-', (string) $code ) );
        $multi_region_kept = array( 'pt-br', 'pt-pt', 'zh-cn', 'zh-tw', 'zh-hk', 'es-mx', 'es-cl', 'fr-ca', 'en-gb', 'en-au', 'en-ca' );
        if ( strpos( $code, '-' ) !== false && ! in_array( $code, $multi_region_kept, true ) ) {
            return substr( $code, 0, 2 );
        }
        return $code;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_endpoints']);
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('wp_ajax_luwipress_fix_translation_images', [$this, 'ajax_fix_translation_images']);
        add_action('wp_ajax_luwipress_fix_category_assignments', [$this, 'ajax_fix_category_assignments']);
        add_action('wp_ajax_luwipress_clean_orphan_translations', [$this, 'ajax_clean_orphan_translations']);
        add_action('wp_ajax_luwipress_get_missing_items', [$this, 'ajax_get_missing_items']);
        add_action('wp_ajax_luwipress_translate_single', [$this, 'ajax_translate_single']);
        add_action('wp_ajax_luwipress_translate_taxonomy_batch', [$this, 'ajax_translate_taxonomy_batch']);
        add_action('wp_ajax_luwipress_translation_progress', [$this, 'ajax_translation_progress']);
        add_action('wp_ajax_luwipress_get_missing_terms', [$this, 'ajax_get_missing_terms']);
        add_action('wp_ajax_luwipress_translate_single_term', [$this, 'ajax_translate_single_term']);
        add_action('wp_ajax_luwipress_retranslate_broken', [$this, 'ajax_retranslate_broken']);
        add_action('wp_ajax_luwipress_stop_translations', [$this, 'ajax_stop_translations']);
        add_action('wp_ajax_luwipress_fix_excerpts', [$this, 'ajax_fix_excerpts']);
        add_action('wp_ajax_luwipress_fix_orphan_translations', [$this, 'ajax_fix_orphan_translations']);
        add_action('wp_ajax_luwipress_sync_wpml_menus', [$this, 'ajax_sync_wpml_menus']);
        add_action('wp_ajax_luwipress_translate_wpml_menus', [$this, 'ajax_translate_wpml_menus']);

        // Cron worker for /translation/force-retranslate when async path is used.
        // Each (post_id, lang) tuple lands here and re-fires request_translation
        // after clearing the elementor "already-translated" guard meta so the
        // pipeline actually re-runs instead of skipping the post.
        add_action( 'luwipress_force_retranslate_single', array( $this, 'cron_force_retranslate_single' ), 10, 2 );

        // Register chunk workers with the generic LuwiPress_Job_Queue.
        if ( class_exists( 'LuwiPress_Job_Queue' ) ) {
            LuwiPress_Job_Queue::register_type( 'taxonomy_translation', array( $this, 'jq_taxonomy_translation_worker' ) );
            LuwiPress_Job_Queue::register_type( 'post_translation',     array( $this, 'jq_post_translation_worker' ) );
        }
    }

    /**
     * Add submenu under LuwiPress
     */
    public function add_submenu() {
        add_submenu_page(
            'luwipress',
            __('Translations', 'luwipress'),
            __('Translations', 'luwipress'),
            'manage_options',
            'luwipress-translations',
            [$this, 'render_page']
        );
    }

    /**
     * Render the translation admin page
     */
    public function render_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/translation-page.php';
    }

    /**
     * Register REST API endpoints
     */
    public function register_endpoints() {
        $namespace = 'luwipress/v1';

        register_rest_route($namespace, '/translation/missing', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_missing_translations'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'target_language' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'post_type'       => ['default' => 'product', 'sanitize_callback' => 'sanitize_text_field'],
                'limit'           => ['default' => 50, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Multi-language missing: returns products with which languages they're missing
        register_rest_route($namespace, '/translation/missing-all', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_missing_translations_all'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'target_languages' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'post_type'        => ['default' => 'product', 'sanitize_callback' => 'sanitize_text_field'],
                'limit'            => ['default' => 20, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Outdated translations: source post was edited after the translation was last synced.
        // Returns posts whose source post_modified_gmt > translation's _luwipress_synced_source_modified.
        register_rest_route($namespace, '/translation/outdated', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_outdated_translations'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'post_type' => ['default' => 'page', 'sanitize_callback' => 'sanitize_text_field'],
                'limit'     => ['default' => 100, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Language drift: translation post EXISTS for the target language but its body
        // content is still in the source language (the silent failure mode that makes
        // /translation/missing report 100% coverage even when the body is broken).
        // Heuristic: stop-word ratio. Returns posts whose target-lang stop-word share
        // falls below the threshold (default 0.45).
        register_rest_route($namespace, '/translation/language-drift', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_language_drift'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'post_type'   => ['default' => 'post', 'sanitize_callback' => 'sanitize_text_field'],
                'languages'   => ['required' => false, 'description' => 'Comma-separated target languages. Defaults to every active non-source language.'],
                'limit'       => ['default' => 200, 'sanitize_callback' => 'absint'],
                'threshold'   => ['default' => 0.45, 'description' => 'Below this target-language score the post is flagged as drifted (0.0..1.0).'],
                'min_words'   => ['default' => 30, 'sanitize_callback' => 'absint', 'description' => 'Skip posts whose body has fewer words than this — too little signal to score.'],
            ],
        ]);

        // Force-retranslate: bypass /translation/missing-all gating. Caller supplies an
        // explicit post_ids whitelist (typically from /translation/language-drift) plus
        // the target languages to overwrite. Each (post, lang) tuple is dispatched via
        // request_translation, which already updates in-place via create_wpml_translation
        // when a translation already exists.
        register_rest_route($namespace, '/translation/force-retranslate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'force_retranslate'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'post_ids'  => ['required' => true, 'description' => 'Array or comma-separated source post IDs (default-language).'],
                'languages' => ['required' => true, 'description' => 'Array or comma-separated target language codes.'],
                'async'     => ['default' => true, 'description' => 'Queue via wp_cron for batches > 5 (post*lang) work units. Set false to run inline.'],
            ],
        ]);

        register_rest_route($namespace, '/translation/request', [
            'methods'             => 'POST',
            'callback'            => [$this, 'request_translation'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'post_id'          => ['sanitize_callback' => 'absint'],
                'product_id'       => ['sanitize_callback' => 'absint'], // backward compat alias for post_id
                'target_languages' => ['required' => true],
                'source_language'  => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Batch: translate N untranslated posts for one or more target languages.
        // Used by the Knowledge Graph "Translate N missing products" button
        // and the category-scoped "Translate this category to X" action.
        register_rest_route($namespace, '/translation/batch', [
            'methods'             => 'POST',
            'callback'            => [$this, 'batch_translate_missing'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'languages' => ['required' => true],
                'post_type' => ['default' => 'product', 'sanitize_callback' => 'sanitize_text_field'],
                'limit'     => ['default' => 50, 'sanitize_callback' => 'absint'],
                'post_ids'  => ['description' => 'Optional whitelist (array or comma-separated IDs) to scope the batch to specific posts (e.g. one category).'],
            ],
        ]);

        register_rest_route($namespace, '/translation/callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_translation_callback'],
            'permission_callback' => [$this, 'check_token_permission'],
        ]);

        register_rest_route($namespace, '/translation/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_translation_status'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route($namespace, '/translation/quality-check', [
            'methods'             => 'POST',
            'callback'            => [$this, 'trigger_quality_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route($namespace, '/translation/taxonomy', [
            'methods'             => 'POST',
            'callback'            => [$this, 'request_taxonomy_translation'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'taxonomy'         => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'target_languages' => ['required' => true],
                'limit'            => ['default' => 50, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // GET endpoint for AI clients to fetch missing terms (no webhook loop)
        register_rest_route($namespace, '/translation/taxonomy-missing', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_missing_taxonomy_terms_api'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'taxonomy'         => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'target_languages' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'limit'            => ['default' => 50, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route($namespace, '/translation/taxonomy-callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_taxonomy_callback'],
            'permission_callback' => [$this, 'check_token_permission'],
        ]);

        // Fix excerpts â€” extract from Elementor content
        register_rest_route($namespace, '/translation/fix-excerpts', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_fix_excerpts'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Fix Elementor mode on translated blog posts â€” removes _elementor_edit_mode
        // so WordPress renders post_content instead of English _elementor_data
        register_rest_route($namespace, '/translation/fix-elementor', [
            'methods'             => 'POST',
            'callback'            => [$this, 'fix_elementor_translated_posts'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'post_ids' => ['description' => 'Comma-separated post IDs to fix, or "all" for all translated posts'],
                'language'  => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Read translation module settings (target + active language list + hreflang mode)
        register_rest_route($namespace, '/translation/settings', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_get_settings'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Write translation module settings â€” partial update, only provided keys are touched
        register_rest_route($namespace, '/translation/settings', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_set_settings'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'target_language'       => ['required' => false, 'type' => 'string'],
                'translation_languages' => ['required' => false, 'type' => 'array'],
                'hreflang_mode'         => ['required' => false, 'type' => 'string'],
                'translation_engine'    => ['required' => false, 'type' => 'string'],
            ],
        ]);
    }

    /**
     * GET /translation/settings â€” mirrors the Translation tab in Settings.
     */
    /** Hard cap so a runaway glossary cannot crowd out the content in a prompt. */
    const MAX_PROTECTED_TERMS = 200;

    /**
     * Terms the translator must reproduce verbatim (brand names, product lines).
     *
     * "Preserve brand names" as a prose instruction is not enough: models happily
     * rendered "Tapadum Music Store" as "Tapadum Musikladen", and because the page
     * title and slug are derived from translated copy, the mistranslation
     * propagated into the URL (tapadum, 2026-08-25). An explicit list is
     * enforceable in a way an adjective is not.
     *
     * Accepts a newline- or comma-separated string, or an array.
     *
     * @return string[]
     */
    public static function get_protected_terms() {
        $raw = get_option( 'luwipress_translation_glossary', '' );

        if ( ! is_array( $raw ) ) {
            $raw = preg_split( '/[\r\n,]+/', (string) $raw );
        }

        $terms = array();
        foreach ( (array) $raw as $term ) {
            if ( ! is_scalar( $term ) ) {
                continue;
            }
            $term = trim( sanitize_text_field( (string) $term ) );
            if ( '' !== $term && ! in_array( $term, $terms, true ) ) {
                $terms[] = $term;
            }
        }

        $terms = array_slice( $terms, 0, self::MAX_PROTECTED_TERMS );

        /**
         * Filter the protected-term glossary.
         *
         * @param string[] $terms Terms that must never be translated.
         */
        return (array) apply_filters( 'luwipress_translation_protected_terms', $terms );
    }

    /**
     * Prompt fragment enforcing the glossary. Empty string when none is configured.
     *
     * @return string
     */
    public static function glossary_prompt_rule() {
        $terms = self::get_protected_terms();
        if ( empty( $terms ) ) {
            return '';
        }
        return "\n- NEVER translate these terms — reproduce them character for character, including when they appear inside a longer sentence, a heading, or a title: "
            . implode( ' | ', $terms );
    }

    public function handle_get_settings( $request ) {
        return array(
            'target_language'       => (string) get_option( 'luwipress_target_language', 'en' ),
            'translation_languages' => (array) get_option( 'luwipress_translation_languages', array() ),
            'hreflang_mode'         => (string) get_option( 'luwipress_hreflang_mode', 'auto' ),
            'translation_engine'    => (string) get_option( 'luwipress_translation_engine', 'ai' ),
            // The DeepL key itself is never exposed — only whether one is usable.
            'translation_glossary'  => self::get_protected_terms(),
            'deepl_configured'      => class_exists( 'LuwiPress_DeepL' ) && LuwiPress_DeepL::is_configured(),
        );
    }

    /**
     * POST /translation/settings â€” partial update.
     */
    public function handle_set_settings( $request ) {
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }
        $updated = array();

        if ( array_key_exists( 'target_language', $data ) ) {
            update_option( 'luwipress_target_language', sanitize_text_field( (string) $data['target_language'] ) );
            $updated[] = 'target_language';
        }

        if ( array_key_exists( 'translation_languages', $data ) ) {
            $langs = is_array( $data['translation_languages'] )
                ? $data['translation_languages']
                : array_filter( array_map( 'trim', explode( ',', (string) $data['translation_languages'] ) ) );
            $langs = array_values( array_filter( array_map( 'sanitize_text_field', $langs ) ) );
            update_option( 'luwipress_translation_languages', $langs );
            $updated[] = 'translation_languages';
        }

        if ( array_key_exists( 'hreflang_mode', $data ) ) {
            $mode = sanitize_text_field( (string) $data['hreflang_mode'] );
            if ( ! in_array( $mode, array( 'auto', 'always', 'never' ), true ) ) {
                return new WP_Error( 'invalid_mode', 'hreflang_mode must be auto, always, or never.', array( 'status' => 400 ) );
            }
            update_option( 'luwipress_hreflang_mode', $mode );
            $updated[] = 'hreflang_mode';
        }

        if ( array_key_exists( 'translation_engine', $data ) ) {
            $engine = sanitize_text_field( (string) $data['translation_engine'] );
            if ( ! in_array( $engine, array( 'ai', 'deepl' ), true ) ) {
                return new WP_Error( 'invalid_engine', 'translation_engine must be ai or deepl.', array( 'status' => 400 ) );
            }
            update_option( 'luwipress_translation_engine', $engine );
            $updated[] = 'translation_engine';
        }

        if ( array_key_exists( 'translation_glossary', $data ) ) {
            $glossary = $data['translation_glossary'];
            if ( ! is_array( $glossary ) ) {
                $glossary = preg_split( '/[
,]+/', (string) $glossary );
            }
            $glossary = array_values( array_filter( array_map( function ( $t ) {
                return trim( sanitize_text_field( (string) $t ) );
            }, (array) $glossary ) ) );
            update_option( 'luwipress_translation_glossary', array_slice( $glossary, 0, self::MAX_PROTECTED_TERMS ) );
            $updated[] = 'translation_glossary';
        }

        LuwiPress_Logger::log( 'Translation settings updated via REST: ' . implode( ', ', $updated ), 'info' );

        return array(
            'success'  => true,
            'updated'  => $updated,
            'settings' => $this->handle_get_settings( $request ),
        );
    }

    /**
     * Permission checks
     */
    public function check_permission( $request ) {
        return LuwiPress_Permission::check_token_or_admin( $request );
    }

    public function check_token_permission( $request ) {
        return LuwiPress_Permission::check_token( $request );
    }

    /**
     * Resolve the WPML language code of a post. Returns null if WPML is inactive
     * or the language can't be determined. Centralises the array/object/null
     * handling for `wpml_post_language_details`.
     */
    public static function get_post_wpml_language( $post_id ) {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return null;
        }
        $info = apply_filters( 'wpml_post_language_details', null, $post_id );
        if ( is_array( $info ) ) {
            return $info['language_code'] ?? null;
        }
        if ( is_object( $info ) ) {
            return $info->language_code ?? null;
        }
        return null;
    }

    /**
     * GET /translation/missing â€” Products missing translations
     */
    public function get_missing_translations($request) {
        $target_lang = $request->get_param('target_language');
        $post_type   = $request->get_param('post_type');
        // min(null, 200) is null -> SQL LIMIT 0 -> the scan silently returned ZERO
        // rows whenever the caller omitted limit (the WebMCP proxy never sends it;
        // tapadum 2026-06-10). Default to 50, clamp to 200.
        $limit       = absint( $request->get_param('limit') );
        $limit       = $limit > 0 ? min( $limit, 200 ) : 50;

        $translation_plugin = $this->detect_translation_plugin();

        if ('wpml' === $translation_plugin) {
            return $this->get_missing_wpml($target_lang, $post_type, $limit);
        } elseif ('polylang' === $translation_plugin) {
            return $this->get_missing_polylang($target_lang, $post_type, $limit);
        }

        // Fallback: use LuwiPress own translation tracking
        return $this->get_missing_luwipress($target_lang, $post_type, $limit);
    }

    /**
     * GET /translation/missing-all â€” Products missing any target language.
     * Returns each product with its list of missing languages.
     * AI clients use this to translate all missing languages in one pass.
     */
    public function get_missing_translations_all($request) {
        $langs_str   = (string) $request->get_param('target_languages');
        $post_type   = $request->get_param('post_type');
        $limit       = absint( $request->get_param('limit') );
        $limit       = $limit > 0 ? min( $limit, 500 ) : 100;
        $target_langs = array_values( array_filter( array_map( 'trim', explode( ',', $langs_str ) ) ) );

        if ( empty( $target_langs ) ) {
            // No explicit list (the WebMCP tool sends none): explode('') yielded ['']
            // and every scan matched nothing -- missing-detection reported 0 forever
            // (tapadum 2026-06-10). Fall back to the configured module setting, then
            // to WPML's active languages minus the default.
            $target_langs = self::get_configured_target_languages();
        }
        if ( empty( $target_langs ) ) {
            return rest_ensure_response( array(
                'count' => 0, 'products' => array(), 'target_languages' => array(),
                'note'  => 'no target languages configured (set translation_languages in /translation/settings)',
            ) );
        }

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return rest_ensure_response(['count' => 0, 'products' => []]);
        }

        global $wpdb;
        $default_lang = self::get_default_language();
        $element_type = 'post_' . $post_type;

        // Get all original posts. Multiple guards layered here based on incidents:
        //
        //  (1) post_title != ''  -- skip blank-title rows (corrupt or trash)
        //
        //  (2) NOT EXISTS (older sibling EN row in same trid) -- skip cascade dups
        //      that share a trid with an older legitimate EN source.
        //
        //  (3) NOT EXISTS (_luwipress_elementor_translated meta) -- THIS IS THE STRONG
        //      guard. We set _luwipress_elementor_translated=1 on every post we create
        //      AS a translation. So if a post has this meta, by definition it is a
        //      translation, not a source -- regardless of what icl_translations says.
        //      This catches the cascade-duplicate case where a translation post got
        //      mis-stamped as language_code='en' (the bug we keep chasing). The post
        //      stays in the missing-list otherwise because each cascade dup gets its
        //      own unique trid (lonely-trid), so guard (2) cannot help.
        $originals = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, t.trid
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND t.element_type = %s AND t.language_code = %s
               AND t.source_language_code IS NULL
               AND p.post_title != ''
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->prefix}icl_translations t2
                   WHERE t2.trid = t.trid
                     AND t2.element_type = t.element_type
                     AND t2.language_code = %s
                     AND t2.source_language_code IS NULL
                     AND t2.element_id < t.element_id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id = p.ID
                     AND pm.meta_key = '_luwipress_elementor_translated'
                     AND pm.meta_value = '1'
               )
             ORDER BY p.post_date DESC
             LIMIT %d",
            $post_type, $element_type, $default_lang, $default_lang, $limit * 2
        ));

        // NOTE: A heuristic non-English-title filter used to live here -- it scanned
        // post titles for foreign-language words (tambour, guia, instrumenti, etc.) to
        // skip orphan source rows. In practice it produced false positives on legit
        // English posts about world music (Tambourine vs Bendir, Persian Tar guide)
        // and silently hid them from the missing-list, even though the Coverage SQL
        // counted them as missing -- causing the 8 missing but 0 to translate UI bug.
        // The source_language_code IS NULL filter above already excludes real WPML
        // orphans. Operators who suspect mis-filed source posts can run the dedicated
        // Fix Orphan Translations maintenance tool instead.

        // Bulk-fetch all existing translations for these trids in one query
        $trids = wp_list_pluck( $originals, 'trid' );
        $existing_translations = array();

        if ( ! empty( $trids ) ) {
            $trid_ph = implode( ',', array_fill( 0, count( $trids ), '%d' ) );
            $lang_ph = implode( ',', array_fill( 0, count( $target_langs ), '%s' ) );
            // CRITICAL: source_language_code IS NOT NULL — must match the Coverage SQL filter
            // exactly. Without it, the fetcher counts mis-flagged "lonely-trid" rows
            // (where a non-EN post sits in the same trid as the EN source but is
            // wrongly stamped source_language_code = NULL) as "translation exists",
            // while Coverage SQL skips them. Result: coverage shows "8 missing" but
            // fetcher returns 0 items and the operator is stuck. Symmetric filter
            // = symmetric counts = no more phantom missing.
            // Also require the translated post to actually exist + be visible — same
            // post_status set as Coverage uses.
            $sql     = sprintf(
                "SELECT t.trid, t.language_code
                 FROM {$wpdb->prefix}icl_translations t
                 JOIN {$wpdb->posts} p ON t.element_id = p.ID
                 WHERE t.trid IN (%s)
                   AND t.element_type = %%s
                   AND t.language_code IN (%s)
                   AND t.source_language_code IS NOT NULL
                   AND p.post_status IN ('publish','draft','private')",
                $trid_ph,
                $lang_ph
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare( $sql, array_merge( array_map( 'intval', $trids ), array( $element_type ), $target_langs ) )
            );

            foreach ( $rows as $row ) {
                $existing_translations[ $row->trid ][ $row->language_code ] = true;
            }
        }

        $items = [];
        foreach ($originals as $orig) {
            $missing_langs = [];
            foreach ($target_langs as $lang) {
                if ( empty( $existing_translations[ $orig->trid ][ $lang ] ) ) {
                    $missing_langs[] = $lang;
                }
            }

            if (!empty($missing_langs)) {
                $items[] = [
                    'post_id'           => absint($orig->ID),
                    'product_id'        => absint($orig->ID), // backward compat
                    'name'              => $orig->post_title,
                    'missing_languages' => $missing_langs,
                ];
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return rest_ensure_response([
            'target_languages'    => $target_langs,
            'translation_plugin'  => 'wpml',
            'count'               => count($items),
            'items'               => $items,
            'products'            => $items, // backward compat
        ]);
    }

    /**
     * GET /translation/outdated â€” Translation posts whose source has been edited
     * since the last sync. Source post_modified_gmt > stored _luwipress_synced_source_modified.
     * Returns each translation grouped by source so the UI can show "1 source has 3 outdated translations".
     */
    public function get_outdated_translations( $request ) {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return rest_ensure_response( array( 'count' => 0, 'sources' => array() ) );
        }
        global $wpdb;
        $post_type    = sanitize_text_field( $request->get_param( 'post_type' ) ?: 'page' );
        $limit        = min( max( 1, absint( $request->get_param( 'limit' ) ) ), 500 );
        $default_lang = self::get_default_language();
        $element_type = 'post_' . $post_type;

        // Find every translation post (has _luwipress_synced_source_modified meta) where
        // source's post_modified_gmt is strictly newer than the stored sync stamp.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                t.element_id   AS translation_id,
                t.language_code,
                t.trid,
                pm.meta_value  AS synced_at,
                src_t.element_id AS source_id,
                src_p.post_title AS source_title,
                src_p.post_modified_gmt AS source_modified
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->postmeta} pm
               ON pm.post_id = t.element_id
              AND pm.meta_key = '_luwipress_synced_source_modified'
             JOIN {$wpdb->prefix}icl_translations src_t
               ON src_t.trid = t.trid
              AND src_t.element_type = t.element_type
              AND src_t.language_code = %s
              AND src_t.source_language_code IS NULL
             JOIN {$wpdb->posts} src_p
               ON src_p.ID = src_t.element_id
              AND src_p.post_status = 'publish'
             WHERE t.element_type = %s
               AND t.source_language_code IS NOT NULL
               AND src_p.post_modified_gmt > pm.meta_value
             ORDER BY src_p.post_modified_gmt DESC
             LIMIT %d",
            $default_lang, $element_type, $limit
        ) );

        // Group by source so the UI can show "1 source -> 3 outdated translations"
        $sources = array();
        foreach ( $rows as $row ) {
            $sid = absint( $row->source_id );
            if ( ! isset( $sources[ $sid ] ) ) {
                $sources[ $sid ] = array(
                    'source_id'       => $sid,
                    'title'           => (string) $row->source_title,
                    'source_modified' => (string) $row->source_modified,
                    'translations'    => array(),
                );
            }
            $sources[ $sid ]['translations'][] = array(
                'translation_id' => absint( $row->translation_id ),
                'language'       => (string) $row->language_code,
                'synced_at'      => (string) $row->synced_at,
                'lag_hours'      => round( ( strtotime( $row->source_modified ) - strtotime( $row->synced_at ) ) / 3600, 1 ),
            );
        }

        return rest_ensure_response( array(
            'count'   => count( $sources ),
            'total_translations' => count( $rows ),
            'sources' => array_values( $sources ),
        ) );
    }

    /**
     * POST /translation/request â€” Translate a post/product/page via AI.
     * Accepts post_id (preferred) or product_id (backward compat).
     */
    public function request_translation($request) {
        $product_id = $request->get_param('post_id') ?: $request->get_param('product_id');
        $target_languages = $request->get_param('target_languages');

        if ( ! $product_id ) {
            return new WP_Error('missing_id', 'post_id or product_id is required', ['status' => 400]);
        }

        if (is_string($target_languages)) {
            $target_languages = array_map('trim', explode(',', $target_languages));
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product($product_id) : null;
        $post    = get_post($product_id);

        // Filterable so the vendor CPT (and any custom type) is translatable
        // without re-touching this whitelist. lwp_vendor is translatable per
        // wpml-config.xml; this list is the pipeline-side gate that was the real
        // blocker behind the "not translatable" 404 (FR-021).
        $translatable_types = apply_filters( 'luwipress_translatable_post_types', array( 'product', 'post', 'page', 'lwp_vendor' ) );
        if ( ! $post || ! in_array( $post->post_type, $translatable_types, true ) ) {
            return new WP_Error('not_found', 'Post not found or not translatable', ['status' => 404]);
        }

        $source_override = $request->get_param( 'source_language' );
        $source_language = $source_override ? sanitize_text_field( $source_override ) : self::get_default_language();

        // If source_language override is given and WPML is active, read content from THAT language's
        // translated post rather than the post_id passed in. This lets callers pick a clean source
        // (e.g. retranslate EN from ES when the EN copy is corrupted).
        $source_post_id = $product_id;
        if ( $source_override && defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $element_type = 'post_' . $post->post_type;
            $trid = apply_filters( 'wpml_element_trid', null, $product_id, $element_type );
            if ( $trid ) {
                $translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
                if ( is_array( $translations ) && isset( $translations[ $source_language ]->element_id ) ) {
                    $candidate = (int) $translations[ $source_language ]->element_id;
                    if ( $candidate && get_post( $candidate ) ) {
                        $source_post_id = $candidate;
                    }
                }
            }
        }

        $source_product = ( $source_post_id !== $product_id && function_exists( 'wc_get_product' ) )
            ? wc_get_product( $source_post_id )
            : $product;
        $source_post_obj = ( $source_post_id !== $product_id ) ? get_post( $source_post_id ) : $post;

        // Use WC product if available, fall back to WP post (WPML compatibility)
        $payload = [
            'product_id'       => $product_id,
            'source_language'  => $source_language,
            'target_languages' => $target_languages,
            'content' => [
                'name'              => $source_product ? $source_product->get_name() : $source_post_obj->post_title,
                'description'       => $source_product ? $source_product->get_description() : $source_post_obj->post_content,
                'short_description' => $source_product ? $source_product->get_short_description() : $source_post_obj->post_excerpt,
                'meta_title'        => $this->get_seo_meta($source_post_id, 'title'),
                'meta_description'  => $this->get_seo_meta($source_post_id, 'description'),
                'faq'               => get_post_meta($source_post_id, '_luwipress_faq', true) ?: [],
            ],
            'categories' => wp_list_pluck(get_the_terms($product_id, $post->post_type === 'product' ? 'product_cat' : 'category') ?: [], 'name'),
            'permalink'  => get_permalink($product_id),
        ];

        // Translate directly via AI Engine for each language
        $lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese', 'ko' => 'Korean' );
        $source_name = $lang_names[ $source_language ] ?? ucfirst( $source_language );

        // Elementor pages: ALWAYS use cron background job (never sync â€” avoids timeout)
        $is_elementor_page = class_exists( 'LuwiPress_Elementor' ) && LuwiPress_Elementor::is_elementor_page( $product_id );

        // Ghost-Elementor guard: posts with substantial post_content but only nominal
        // _elementor_data widgets (e.g. blog posts migrated from Hello Elementor or
        // with kit hero overlays) are rendered by the theme via post_content, NOT
        // Elementor. Routing them through the Elementor chunked translator only
        // rewrites the kit overlay strings and leaves post_content English.
        // Detect & force the standard path so the actual body gets translated.
        if ( $is_elementor_page ) {
            $body_strip   = wp_strip_all_tags( (string) $source_post_obj->post_content );
            $has_real_body = mb_strlen( trim( $body_strip ) ) > 200;
            $edata = get_post_meta( $product_id, '_elementor_data', true );
            $widget_count = is_string( $edata ) ? substr_count( $edata, '"widgetType"' ) : 0;
            $elementor_nominal = $widget_count > 0 && $widget_count < 5;
            if ( $has_real_body && $elementor_nominal ) {
                LuwiPress_Logger::log(
                    'Ghost-Elementor detected on #' . $product_id . ' (post_content has ' . mb_strlen( $body_strip ) . ' chars, _elementor_data has only ' . $widget_count . ' widgets) — forcing standard translation path so post_content gets rewritten.',
                    'info',
                    array( 'post_id' => $product_id, 'widgets' => $widget_count, 'body_chars' => mb_strlen( $body_strip ) )
                );
                $is_elementor_page = false;
            }
        }

        if ( $is_elementor_page ) {
            // Tell the caller UP FRONT when the page carries no translatable widget
            // text (pure dynamic CPT-grid pages). The background job then produces a
            // structure-only sibling and no text ever changes -- which, without this,
            // looks exactly like the request having vanished (tapadum finding 4).
            $elementor_texts = LuwiPress_Elementor::get_instance()->extract_translatable_text( $product_id );
            $has_widget_text = ! is_wp_error( $elementor_texts ) && ! empty( $elementor_texts );

            foreach ( $target_languages as $lang ) {
                update_post_meta( $product_id, '_luwipress_translation_status', wp_json_encode( array(
                    'status'   => 'queued',
                    'language' => $lang,
                    'queued'   => current_time( 'mysql' ),
                ) ) );
                wp_schedule_single_event( time(), 'luwipress_elementor_translate_single', array( $product_id, $lang ) );
            }
            spawn_cron();
            LuwiPress_Logger::log( sprintf(
                'Elementor page #%d queued for background translation → %s (%s)',
                $product_id, implode( ',', $target_languages ),
                $has_widget_text ? count( $elementor_texts ) . ' translatable widget(s)' : 'NO translatable widget text — structure-only sibling will be created'
            ), $has_widget_text ? 'info' : 'warning', array( 'post_id' => $product_id ) );

            return rest_ensure_response( array(
                'status'             => 'queued',
                'post_id'            => $product_id,
                'languages'          => $target_languages,
                'translatable_widgets' => $has_widget_text ? count( $elementor_texts ) : 0,
                'message'            => $has_widget_text
                    ? 'Elementor page queued for background translation'
                    : 'Queued, but this page has NO translatable widget text (dynamic/shortcode-only). A structure-only translation will be created so the language pair exists; no text will change.',
            ) );
        }

        // A page whose visible copy comes entirely from a dynamic grid/shortcode has
        // nothing for the translator to work on. The engine used to run anyway and
        // produce no sibling and no log line, so repeated requests looked like they
        // vanished (tapadum finding 4, 2026-08-25). Say so instead.
        $source_text_len = mb_strlen( trim( wp_strip_all_tags(
            (string) ( $payload['content']['description'] ?? '' ) . ' ' .
            (string) ( $payload['content']['short_description'] ?? '' )
        ) ) );
        if ( $source_text_len < 20 ) {
            foreach ( $target_languages as $lang ) {
                update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_status', 'skipped_no_content' );
            }
            LuwiPress_Logger::log( sprintf(
                'Translation skipped for post #%d (%s): source has %d chars of translatable body — nothing to translate (dynamic/shortcode-only page).',
                $product_id, implode( ',', $target_languages ), $source_text_len
            ), 'warning', array( 'post_id' => $product_id, 'languages' => $target_languages ) );

            return rest_ensure_response( array(
                'status'           => 'skipped',
                'reason'           => 'no_translatable_content',
                'message'          => sprintf( 'Source post #%d has %d characters of translatable body text. Nothing was created.', $product_id, $source_text_len ),
                'post_id'          => $product_id,
                'product_id'       => $product_id,
                'target_languages' => $target_languages,
            ) );
        }

        // Per-language outcome, so a rejection reaches the caller instead of only
        // the system log (tapadum finding 3, 2026-08-25).
        $results = array();

        foreach ( $target_languages as $lang ) {
            update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_status', 'processing' );
            update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_requested', current_time( 'c' ) );

            $target_name = $lang_names[ $lang ] ?? ucfirst( $lang );

            // â”€â”€ Standard Translation Path (non-Elementor or short content) â”€â”€

            // Calculate max_tokens based on content length. chars/3 undersized the
            // budget for long bodies: translated Romance-language HTML runs ~1 token
            // per 2.5-3 chars, so an 11k-char article needs ~4.5k tokens and was
            // truncated at the old 4096 floor -- which then parsed to an empty or
            // invalid JSON payload (tapadum 2026-06-10). chars/2 + a 6000 floor
            // leaves headroom; the 16k cap still bounds cost.
            $content_length = strlen( $payload['content']['description'] ?? '' );
            $estimated_tokens = max( 6000, intval( $content_length / 2 ) );
            $max_tokens = min( $estimated_tokens, 16000 ); // GPT-4o-mini limit

            $prompt   = LuwiPress_Prompts::translation( $payload['content'], $source_name, $target_name, $product_id );
            $messages = LuwiPress_AI_Engine::build_messages( $prompt );
            $ai_result = LuwiPress_AI_Engine::dispatch_json( 'translation-pipeline', $messages, array(
                'max_tokens' => $max_tokens,
                'timeout'    => 180,
            ) );

            // ── Hybrid: DeepL handles body text, the LLM keeps the SEO meta ──
            // When the operator selects the DeepL engine AND a key is configured
            // AND DeepL supports the target language, overwrite the body fields
            // (name/description/short_description) with DeepL's higher-accuracy
            // translation while leaving meta_title/meta_description/focus_keyword/
            // slug/faq from the LLM untouched. DeepL failures or unsupported
            // languages fall through to the full-LLM result so a job never fails
            // because of DeepL. The data-loss guard below still validates the
            // merged description, so a truncated DeepL body is rejected too.
            $use_deepl = ( 'deepl' === get_option( 'luwipress_translation_engine', 'ai' ) )
                && class_exists( 'LuwiPress_DeepL' )
                && LuwiPress_DeepL::is_configured()
                && null !== LuwiPress_DeepL::map_lang_code( $lang, false );

            if ( $use_deepl && ! is_wp_error( $ai_result ) && is_array( $ai_result ) ) {
                $deepl_in = array_filter( array(
                    'name'              => (string) ( $payload['content']['name'] ?? '' ),
                    'description'       => (string) ( $payload['content']['description'] ?? '' ),
                    'short_description' => (string) ( $payload['content']['short_description'] ?? '' ),
                ), static function ( $v ) {
                    return '' !== $v;
                } );

                $deepl_out = $deepl_in
                    ? LuwiPress_DeepL::translate_batch( $deepl_in, $source_language, $lang, array( 'timeout' => 120 ) )
                    : array();

                if ( is_wp_error( $deepl_out ) ) {
                    LuwiPress_Logger::log(
                        'DeepL translation failed for ' . $lang . ' (post #' . $product_id . '): '
                            . $deepl_out->get_error_message() . ' — falling back to LLM body.',
                        'warning',
                        array( 'product_id' => $product_id, 'language' => $lang )
                    );
                } else {
                    if ( isset( $deepl_out['name'] ) ) {
                        $ai_result['title'] = $deepl_out['name'];
                        $ai_result['name']  = $deepl_out['name'];
                    }
                    if ( isset( $deepl_out['description'] ) ) {
                        $ai_result['description'] = $deepl_out['description'];
                    }
                    if ( isset( $deepl_out['short_description'] ) ) {
                        $ai_result['short_description'] = $deepl_out['short_description'];
                    }
                }
            }

            // If JSON parse failed, extract from error data or retry
            if ( is_wp_error( $ai_result ) && strpos( $ai_result->get_error_message(), 'parse JSON' ) !== false ) {
                $raw_text = $ai_result->get_error_data()['raw'] ?? '';

                // If no raw text in error, do a single retry with dispatch (non-JSON)
                if ( empty( $raw_text ) ) {
                    $raw_result = LuwiPress_AI_Engine::dispatch( 'translation-pipeline', $messages, array(
                        'max_tokens' => $max_tokens,
                        'timeout'    => 180,
                    ) );
                    if ( ! is_wp_error( $raw_result ) ) {
                        $raw_text = $raw_result['content'] ?? '';
                    }
                }

                if ( ! empty( $raw_text ) ) {
                    // Try to extract JSON
                    $parsed = LuwiPress_AI_Engine::extract_json( $raw_text );
                    if ( $parsed ) {
                        $ai_result = $parsed;
                    } else {
                        // JSON parse failed AND extract_json failed. NEVER write raw text to
                        // post_content â€” it could be a literal JSON payload dump (bug history:
                        // corrupted IT copies on tapadum.com, 2026-04-20). Fail the translation
                        // and preserve existing content untouched.
                        update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_status', 'failed' );
                        $results[ $lang ] = array(
                            'status' => 'rejected',
                            'reason' => 'unparseable_ai_response',
                            'detail' => sprintf( 'AI response could not be parsed as JSON (raw length %d). Existing content preserved.', strlen( $raw_text ) ),
                        );
                        LuwiPress_Logger::log(
                            'Translation JSON parse failed for ' . $lang . ' (product #' . $product_id . '): raw response could not be parsed, translation rejected to protect existing content. raw_len=' . strlen( $raw_text ),
                            'error',
                            array( 'product_id' => $product_id, 'language' => $lang, 'raw_head' => mb_substr( $raw_text, 0, 200 ) )
                        );
                        continue;
                    }
                }
            }

            if ( is_wp_error( $ai_result ) ) {
                update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_status', 'failed' );
                $results[ $lang ] = array(
                    'status' => 'failed',
                    'reason' => $ai_result->get_error_code(),
                    'detail' => $ai_result->get_error_message(),
                );
                LuwiPress_Logger::log( 'Translation failed for ' . $lang . ': ' . $ai_result->get_error_message(), 'error', array( 'product_id' => $product_id ) );
                continue;
            }

            // Feed into existing callback handler
            // If title is empty (JSON fallback), keep original title rather than leaving blank
            $translated_title = $ai_result['title'] ?? $ai_result['name'] ?? '';
            if ( empty( $translated_title ) ) {
                $translated_title = $post ? ( $product ? $product->get_name() : $post->post_title ) : '';
                LuwiPress_Logger::log( 'Translation title empty for ' . $lang . ', keeping original: "' . mb_substr( $translated_title, 0, 50 ) . '"', 'warning' );
            }

            // GUARD: never accept an empty/truncated body when the source has real
            // content. A truncated AI response (max_tokens) can parse to an empty or
            // tiny description, and the callback path would overwrite the existing
            // translation with it -- data loss (tapadum 2026-06-10: post #33424 lost
            // its 11k-char body to a content_len=0 write).
            $src_desc_len = strlen( (string) ( $payload['content']['description'] ?? '' ) );
            $tr_desc_len  = strlen( (string) ( $ai_result['description'] ?? '' ) );
            if ( $src_desc_len > 500 && $tr_desc_len < max( 200, (int) ( $src_desc_len * 0.2 ) ) ) {
                update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_status', 'failed' );
                $results[ $lang ] = array(
                    'status' => 'rejected',
                    'reason' => 'empty_or_truncated_translation',
                    'detail' => sprintf( 'Translated body was %d chars against a %d-char source. Existing content preserved; re-run to retry.', $tr_desc_len, $src_desc_len ),
                );
                LuwiPress_Logger::log( sprintf(
                    'Translation rejected for %s (post #%d): translated body %d chars vs source %d -- empty/truncated AI output, existing content preserved.',
                    $lang, $product_id, $tr_desc_len, $src_desc_len
                ), 'error', array( 'product_id' => $product_id, 'language' => $lang ) );
                continue;
            }

            $results[ $lang ] = array( 'status' => 'completed' );

            $callback_request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/callback' );
            $callback_request->set_body_params( array(
                'product_id' => $product_id,
                'language'   => $lang,
                'content'    => array(
                    'name'             => $translated_title,
                    'description'      => $ai_result['description'] ?? '',
                    'short_description' => $ai_result['short_description'] ?? '',
                    'meta_title'       => $ai_result['meta_title'] ?? '',
                    'meta_description' => $ai_result['meta_description'] ?? '',
                    'focus_keyword'    => $ai_result['focus_keyword'] ?? '',
                    'slug'             => $ai_result['slug'] ?? '',
                    'faq'              => is_array( $ai_result['faq'] ?? null ) ? $ai_result['faq'] : array(),
                ),
                'status' => 'completed',
            ) );
            $this->handle_translation_callback( $callback_request );
        }

        // Overall status reflects what actually happened. Returning a flat
        // "completed" while a language was rejected made failures invisible to the
        // caller -- they only showed up in system_logs (tapadum finding 3).
        $completed = array_keys( array_filter( $results, static function ( $r ) {
            return 'completed' === $r['status'];
        } ) );
        $failed = array_diff( $target_languages, $completed );

        if ( empty( $completed ) ) {
            $overall = 'rejected';
        } elseif ( empty( $failed ) ) {
            $overall = 'completed';
        } else {
            $overall = 'partial';
        }

        LuwiPress_Logger::log( sprintf(
            'Translation requested for: %s — %d/%d language(s) completed%s',
            ( $product ? $product->get_name() : $post->post_title ),
            count( $completed ), count( $target_languages ),
            empty( $failed ) ? '' : ' (failed: ' . implode( ',', $failed ) . ')'
        ), empty( $failed ) ? 'info' : 'warning', array(
            'product_id'      => $product_id,
            'source_post_id'  => $source_post_id,
            'source_language' => $source_language,
            'languages'       => $target_languages,
            'results'         => $results,
        ) );

        return rest_ensure_response( array(
            'status'           => $overall,
            'product_id'       => $product_id,
            'post_id'          => $product_id,
            'target_languages' => $target_languages,
            'completed'        => array_values( $completed ),
            'failed'           => array_values( $failed ),
            'results'          => $results,
        ) );
    }

    /**
     * Bulk translate missing content items for a post type + language.
     * Called from the Translation Manager admin page in local AI mode.
     *
     * @param WP_REST_Request $request  Not used directly.
     * @param string          $post_type Product, post, or page.
     * @param array           $languages Target language codes.
     * @param int             $limit     Max items to translate.
     * @return array|WP_Error Result with 'translated' count.
     */
    /**
     * POST /translation/batch â€” Translate N untranslated posts for one or more target languages.
     *
     * Used by the Knowledge Graph "Translate N missing products" button. Thin
     * REST wrapper around handle_bulk_translation() which does the heavy lifting
     * (fetches missing items via /translation/missing-all, fires request_translation
     * for each).
     */
    public function batch_translate_missing( $request ) {
        $languages = $request->get_param( 'languages' );
        $post_type = $request->get_param( 'post_type' );
        $limit     = min( max( 1, absint( $request->get_param( 'limit' ) ) ), 200 );

        if ( is_string( $languages ) ) {
            $languages = array_map( 'trim', explode( ',', $languages ) );
        }
        $languages = array_values( array_filter( array_map( 'sanitize_text_field', (array) $languages ) ) );

        if ( empty( $languages ) ) {
            return new WP_Error( 'missing_languages', 'languages parameter is required (array or comma-separated).', array( 'status' => 400 ) );
        }

        // Optional post_ids whitelist â€” lets callers scope the batch to a category,
        // search result set, etc. Normalised here so handle_bulk_translation can read it back.
        $post_ids = $request->get_param( 'post_ids' );
        if ( ! empty( $post_ids ) ) {
            if ( is_string( $post_ids ) ) {
                $post_ids = array_map( 'trim', explode( ',', $post_ids ) );
            }
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
            $request->set_param( 'post_ids', $post_ids );
        }

        $result = $this->handle_bulk_translation( $request, $post_type, $languages, $limit );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array_merge( array(
            'status'    => 'queued',
            'languages' => $languages,
            'post_type' => $post_type,
        ), (array) $result ) );
    }

    public function handle_bulk_translation( $request, $post_type, $languages, $limit = 20 ) {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return new WP_Error( 'no_wpml', 'WPML is required for translation.', array( 'status' => 400 ) );
        }

        // Fetch missing items
        $fetch_request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/missing-all' );
        $fetch_request->set_param( 'target_languages', implode( ',', $languages ) );
        $fetch_request->set_param( 'post_type', $post_type );
        $fetch_request->set_param( 'limit', $limit );

        $missing_response = $this->get_missing_translations_all( $fetch_request );
        $missing_data     = $missing_response->get_data();
        $missing_items    = $missing_data['items'] ?? $missing_data['products'] ?? array();

        // Optional whitelist: restrict to specific post IDs (used by category batch).
        $whitelist = $request->get_param( 'post_ids' );
        if ( ! empty( $whitelist ) && is_array( $whitelist ) ) {
            $whitelist_map = array_flip( array_map( 'absint', $whitelist ) );
            $missing_items = array_values( array_filter( $missing_items, function ( $item ) use ( $whitelist_map ) {
                $pid = absint( $item['post_id'] ?? $item['product_id'] ?? 0 );
                return $pid && isset( $whitelist_map[ $pid ] );
            } ) );
        }

        if ( empty( $missing_items ) ) {
            return array( 'translated' => 0, 'message' => 'Nothing to translate.' );
        }

        // Estimate total work units: post * languages_each_post_is_missing.
        $total_units = 0;
        foreach ( $missing_items as $item ) {
            $missing_langs = $item['missing_languages'] ?? $languages;
            $total_units += count( $missing_langs );
        }

        // Async path for big batches: anything past 10 work units (each unit = one
        // AI translation call for one post in one language) goes to wp_cron via
        // LuwiPress_Job_Queue. Sync path stays for small batches so the dashboard
        // stays snappy.
        if ( $total_units > 10 && class_exists( 'LuwiPress_Job_Queue' ) ) {
            $chunks = array();
            foreach ( $missing_items as $item ) {
                $post_id       = $item['post_id'] ?? $item['product_id'] ?? 0;
                $missing_langs = $item['missing_languages'] ?? $languages;
                if ( ! $post_id ) { continue; }
                foreach ( $missing_langs as $lang ) {
                    $chunks[] = array( 'post_id' => $post_id, 'lang' => $lang );
                }
            }
            $job = LuwiPress_Job_Queue::enqueue( 'post_translation', array(
                'chunks' => $chunks,
                'meta'   => array(
                    'post_type' => $post_type,
                    'languages' => $languages,
                ),
            ) );
            if ( is_wp_error( $job ) ) {
                return $job;
            }
            return array(
                'translated'  => 0,
                'queued'      => count( $chunks ),
                'job_id'      => $job['job_id'],
                'total_units' => $job['total_units'],
                'total_found' => count( $missing_items ),
                'status'      => 'queued',
                'message'     => sprintf( 'Queued %d translations. Poll job_id for progress.', count( $chunks ) ),
            );
        }

        // Sync path -- small batches (<=10 units).
        $translated = 0;
        foreach ( $missing_items as $item ) {
            $post_id       = $item['post_id'] ?? $item['product_id'] ?? 0;
            $missing_langs = $item['missing_languages'] ?? $languages;

            if ( ! $post_id ) {
                continue;
            }

            $tr_request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/request' );
            $tr_request->set_param( 'post_id', $post_id );
            $tr_request->set_param( 'target_languages', $missing_langs );

            $result = $this->request_translation( $tr_request );

            if ( ! is_wp_error( $result ) ) {
                $translated++;
            }
        }

        return array( 'translated' => $translated, 'total_found' => count( $missing_items ) );
    }

    /**
     * Worker for one post-translation chunk: { post_id, lang }.
     * Returns: [ 'sent' => N, 'saved' => N, 'errors' => [...] ]
     */
    public function jq_post_translation_worker( $chunk_payload, $meta, $job_id ) {
        $post_id = absint( $chunk_payload['post_id'] ?? 0 );
        $lang    = sanitize_text_field( $chunk_payload['lang'] ?? '' );

        if ( ! $post_id || ! $lang ) {
            return array( 'sent' => 0, 'saved' => 0, 'errors' => array( 'invalid chunk: missing post_id or lang' ) );
        }

        $tr_request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/request' );
        $tr_request->set_param( 'post_id', $post_id );
        $tr_request->set_param( 'target_languages', array( $lang ) );

        $result = $this->request_translation( $tr_request );

        if ( is_wp_error( $result ) ) {
            return array( 'sent' => 1, 'saved' => 0, 'errors' => array( sprintf( 'post #%d -> %s: %s', $post_id, $lang, $result->get_error_message() ) ) );
        }

        // request_translation returns rest_ensure_response on success. We treat the
        // chunk as saved if we didn't get a wp_error -- the actual write happens
        // inside request_translation -> AI -> handle_translation_callback chain.
        return array( 'sent' => 1, 'saved' => 1, 'errors' => array() );
    }

    /**
     * POST /translation/callback â€” Receive translated content from async AI pipeline
     */
    public function handle_translation_callback($request) {
        // Support both JSON body (external REST) and body_params (internal call)
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_params();
        }

        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
        $language   = isset($data['language']) ? sanitize_text_field($data['language']) : '';
        $content    = isset($data['content']) ? $data['content'] : [];

        if (!$product_id || empty($language) || empty($content)) {
            return new WP_Error('invalid_data', 'Missing required fields', ['status' => 400]);
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product($product_id) : null;
        $post    = get_post($product_id);
        if ( ! $post ) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // Sanitize FAQ round-trip from AI: preserve entry shape, strip everything
        // else; drop rows where both Q and A came back empty. Result is the same
        // serialized array shape as `_luwipress_faq` meta.
        $raw_faq  = is_array( $content['faq'] ?? null ) ? $content['faq'] : array();
        $faq_safe = array();
        foreach ( $raw_faq as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $q = sanitize_text_field( $row['question'] ?? '' );
            $a = wp_kses_post( $row['answer'] ?? '' );
            if ( '' !== $q || '' !== $a ) {
                $faq_safe[] = array( 'question' => $q, 'answer' => $a );
            }
        }

        // Store translated content
        $translated = [
            'name'              => sanitize_text_field( $content['name'] ?? $content['title'] ?? '' ),
            'description'       => wp_kses_post( $content['description'] ?? '' ),
            'short_description' => wp_kses_post( $content['short_description'] ?? '' ),
            'meta_title'        => sanitize_text_field( $content['meta_title'] ?? '' ),
            'meta_description'  => sanitize_text_field( $content['meta_description'] ?? '' ),
            'focus_keyword'     => sanitize_text_field( $content['focus_keyword'] ?? '' ),
            'slug'              => sanitize_title( $content['slug'] ?? '' ),
            'faq'               => $faq_safe,
        ];

        update_post_meta($product_id, '_luwipress_translation_' . $language, $translated);
        update_post_meta($product_id, '_luwipress_translation_' . $language . '_status', 'completed');
        update_post_meta($product_id, '_luwipress_translation_' . $language . '_completed', current_time('c'));

        $post_obj = get_post( $product_id );
        LuwiPress_Logger::log('Translation completed: ' . ( $post_obj ? $post_obj->post_title : $product_id ) . ' â†’ ' . strtoupper( $language ), 'info', array(
            'product_id' => $product_id,
            'language'   => $language,
        ));

        // If WPML/Polylang is active, try to create/update the translated post
        $translation_plugin = $this->detect_translation_plugin();
        $save_error = null;
        try {
            if ('wpml' === $translation_plugin) {
                $this->create_wpml_translation($product_id, $language, $translated);
            } elseif ('polylang' === $translation_plugin) {
                $this->create_polylang_translation($product_id, $language, $translated);
            }
        } catch ( \Exception $e ) {
            $save_error = $e->getMessage();
            LuwiPress_Logger::log(
                sprintf('Translation save error: product #%d â†’ %s: %s', $product_id, strtoupper($language), $save_error),
                'error',
                ['product_id' => $product_id, 'language' => $language, 'error' => $save_error]
            );
            // Don't fail â€” meta is already saved, WPML post creation just failed
        }

        // Purge cache for original and translated post
        $detector = LuwiPress_Plugin_Detector::get_instance();
        $detector->purge_post_cache($product_id);

        /**
         * Fires after a translation request completes (saved or partial).
         * Theme companions can invalidate language-bound caches (related-products
         * rail, hreflang sitemap, language-specific KG snapshots) on this signal.
         *
         * @param int    $product_id Source post id whose translation was written.
         * @param string $language   Target language code (e.g. 'it', 'fr', 'es').
         * @param string $status     'saved' or 'partial' (WPML/Polylang post create failed).
         */
        do_action( 'luwipress_after_translation_request', $product_id, $language, $save_error ? 'partial' : 'saved' );

        return rest_ensure_response([
            'status'     => $save_error ? 'partial' : 'saved',
            'product_id' => $product_id,
            'language'   => $language,
            'error'      => $save_error,
        ]);
    }

    /**
     * GET /translation/status â€” Translation queue status
     */
    public function get_translation_status($request) {
        global $wpdb;

        $raw_langs = get_option( 'luwipress_translation_languages', array() );
        $target_languages = is_array( $raw_langs ) ? $raw_langs : array_map( 'trim', explode( ',', $raw_langs ) );
        if ( empty( $target_languages ) ) {
            // Fallback: get from translation plugin
            $detector = LuwiPress_Plugin_Detector::get_instance();
            $t = $detector->detect_translation();
            $target_languages = array_diff( $t['active_languages'] ?? array(), array( $t['default_language'] ?? 'tr' ) );
        }

        // Batch: single query counts all statuses across all languages
        $meta_keys = array();
        foreach ( $target_languages as $lang ) {
            $meta_keys[] = '_luwipress_translation_' . $lang . '_status';
        }
        $key_ph = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
        $sql    = sprintf(
            "SELECT meta_key, meta_value, COUNT(DISTINCT post_id) AS cnt
             FROM {$wpdb->postmeta}
             WHERE meta_key IN (%s) AND meta_value IN ('processing','completed')
             GROUP BY meta_key, meta_value",
            $key_ph
        );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $meta_keys ) );
        $counts = array();
        foreach ( $rows as $row ) {
            $counts[ $row->meta_key ][ $row->meta_value ] = (int) $row->cnt;
        }
        $stats = [];
        foreach ( $target_languages as $lang ) {
            $key = '_luwipress_translation_' . $lang . '_status';
            $stats[ $lang ] = [
                'processing' => $counts[ $key ]['processing'] ?? 0,
                'completed'  => $counts[ $key ]['completed'] ?? 0,
            ];
        }

        // Elementor pages run on a wp_cron queue and stamp a DIFFERENT meta key
        // (_luwipress_translation_status, no language segment), so the counts above
        // structurally cannot see them — "processing: 0" while jobs were waiting was
        // a correct answer from the wrong table (tapadum, 2026-08-25). Surface the
        // background queue here too, next to the counts operators actually check.
        $elementor_queue = array( 'pending' => 0, 'entries' => array() );
        if ( class_exists( 'LuwiPress_Elementor' ) ) {
            $elementor_queue = LuwiPress_Elementor::get_instance()->list_translation_queue();
        }

        return rest_ensure_response([
            'translation_plugin' => $this->detect_translation_plugin(),
            'languages'          => $stats,
            'elementor_queue'    => $elementor_queue,
        ]);
    }

    /**
     * POST /translation/quality-check â€” Trigger quality audit
     */
    public function trigger_quality_check($request) {
        $data = $request->get_json_params();
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
        $language   = isset($data['language']) ? sanitize_text_field($data['language']) : '';

        if (!$product_id || empty($language)) {
            return new WP_Error('invalid_data', 'product_id and language required', ['status' => 400]);
        }

        $translated = get_post_meta($product_id, '_luwipress_translation_' . $language, true);
        if (empty($translated)) {
            return new WP_Error('no_translation', 'No translation found', ['status' => 404]);
        }

        $product  = function_exists( 'wc_get_product' ) ? wc_get_product($product_id) : null;
        $post_obj = get_post($product_id);
        $payload = [
            'product_id'      => $product_id,
            'language'        => $language,
            'source_content'  => [
                'name'        => $product ? $product->get_name() : ( $post_obj->post_title ?? '' ),
                'description' => $product ? $product->get_description() : ( $post_obj->post_content ?? '' ),
            ],
            'translated_content' => $translated,
            'type' => 'quality_check',
        ];

        // Quality check via built-in AI Engine (synchronous â€” small payload)
        $budget = LuwiPress_Token_Tracker::check_budget( 'translation-pipeline' );
        if ( is_wp_error( $budget ) ) {
            return $budget;
        }

        $system = 'You are a translation quality auditor. Compare the source and translated content. Return JSON with: {"score": 0-100, "issues": ["issue1", ...], "suggestions": ["fix1", ...]}';
        $user   = sprintf(
            "Source (%s):\nTitle: %s\nDescription: %s\n\nTranslated (%s):\nTitle: %s\nDescription: %s",
            get_option( 'luwipress_target_language', 'tr' ),
            $payload['source_content']['name'],
            wp_trim_words( $payload['source_content']['description'], 200 ),
            $language,
            $payload['translated_content']['name'] ?? '',
            wp_trim_words( $payload['translated_content']['description'] ?? '', 200 )
        );

        $messages = LuwiPress_AI_Engine::build_messages( array( 'system' => $system, 'user' => $user ) );
        $result = LuwiPress_AI_Engine::dispatch_json( 'translation-quality', $messages, array( 'max_tokens' => 500 ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'status'     => 'completed',
            'product_id' => $product_id,
            'language'   => $language,
            'quality'    => $result,
        ) );
    }

    /**
     * Detect active translation plugin (cached per-request)
     */
    private $detected_plugin = null;

    private function detect_translation_plugin() {
        if ( null !== $this->detected_plugin ) {
            return $this->detected_plugin;
        }
        if (defined('ICL_SITEPRESS_VERSION')) {
            $this->detected_plugin = 'wpml';
        } elseif (function_exists('pll_languages_list')) {
            $this->detected_plugin = 'polylang';
        } else {
            $this->detected_plugin = 'none';
        }
        return $this->detected_plugin;
    }

    /**
     * Get SEO meta value via Plugin Detector (supports all SEO plugins).
     */
    private function get_seo_meta($post_id, $type) {
        $detector = LuwiPress_Plugin_Detector::get_instance();
        $meta = $detector->get_seo_meta($post_id);

        if ('title' === $type) {
            return $meta['title'] ?? '';
        }
        if ('description' === $type) {
            return $meta['description'] ?? '';
        }
        return '';
    }

    /**
     * Get missing translations using LuwiPress tracking
     */
    private function get_missing_luwipress($target_lang, $post_type, $limit) {
        global $wpdb;

        $meta_key = '_luwipress_translation_' . $target_lang . '_status';

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND p.ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = %s AND meta_value = 'completed'
               )
             ORDER BY p.post_date DESC
             LIMIT %d",
            $post_type,
            $meta_key,
            $limit
        ));

        $missing = [];
        foreach ($products as $p) {
            $missing[] = [
                'product_id' => $p->ID,
                'name'       => $p->post_title,
                'permalink'  => get_permalink($p->ID),
            ];
        }

        return rest_ensure_response([
            'target_language' => $target_lang,
            'count'           => count($missing),
            'products'        => $missing,
        ]);
    }

    /**
     * Get missing WPML translations
     */
    private function get_missing_wpml($target_lang, $post_type, $limit) {
        global $wpdb;

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $this->get_missing_luwipress($target_lang, $post_type, $limit);
        }

        $default_lang = self::get_default_language();

        // Find default-language posts that either:
        // 1. Have no translation record for the target language, OR
        // 2. Have a translation record but the translated post is not published
        // source_language_code IS NULL = original post (not a translation)
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND t.language_code = %s
               AND t.element_type = CONCAT('post_', %s)
               AND t.source_language_code IS NULL
               AND t.trid NOT IN (
                   SELECT tr.trid FROM {$wpdb->prefix}icl_translations tr
                   JOIN {$wpdb->posts} tp ON tr.element_id = tp.ID
                   WHERE tr.language_code = %s
                     AND tr.element_type = CONCAT('post_', %s)
                     AND tp.post_status = 'publish'
               )
             ORDER BY p.post_date DESC
             LIMIT %d",
            $post_type, $default_lang, $post_type, $target_lang, $post_type, $limit
        ));

        $missing = [];
        foreach ($products as $p) {
            $missing[] = [
                'product_id' => $p->ID,
                'name'       => $p->post_title,
                'permalink'  => get_permalink($p->ID),
            ];
        }

        return rest_ensure_response([
            'target_language'    => $target_lang,
            'translation_plugin' => 'wpml',
            'count'              => count($missing),
            'products'           => $missing,
        ]);
    }

    /**
     * Get missing Polylang translations
     */
    private function get_missing_polylang($target_lang, $post_type, $limit) {
        if (!function_exists('pll_get_post_translations')) {
            return $this->get_missing_luwipress($target_lang, $post_type, $limit);
        }

        $products = get_posts([
            'post_type'   => $post_type,
            'post_status' => 'publish',
            'numberposts' => $limit * 2,
            'lang'        => pll_default_language(),
        ]);

        $missing = [];
        foreach ($products as $p) {
            $translations = pll_get_post_translations($p->ID);
            if (!isset($translations[$target_lang])) {
                $missing[] = [
                    'product_id' => $p->ID,
                    'name'       => $p->post_title,
                    'permalink'  => get_permalink($p->ID),
                ];
            }
            if (count($missing) >= $limit) {
                break;
            }
        }

        return rest_ensure_response([
            'target_language'    => $target_lang,
            'translation_plugin' => 'polylang',
            'count'              => count($missing),
            'products'           => $missing,
        ]);
    }

    /**
     * Create a translation post and register it with WPML/Polylang.
     *
     * Central method â€” every translation post in the plugin MUST go through here.
     * Handles: WPML, Polylang, and vanilla WordPress.
     *
     * @param int    $source_id   Original post ID.
     * @param string $language    Target language code (e.g. 'fr', 'it', 'es').
     * @param array  $post_data   Array with keys: title, slug, content, excerpt (all optional).
     * @return int|WP_Error       New post ID or WP_Error on failure.
     */
    public function create_translation_post( $source_id, $language, $post_data = array() ) {
        global $wpdb;

        $source_post = get_post( $source_id );
        if ( ! $source_post ) {
            return new \WP_Error( 'invalid_source', 'Source post #' . $source_id . ' not found' );
        }

        $post_type    = $source_post->post_type;
        $element_type = 'post_' . $post_type;

        // Determine translation plugin
        $detector  = LuwiPress_Plugin_Detector::get_instance();
        $lang_info = $detector->detect_translation();
        $plugin    = $lang_info['plugin'] ?? 'none';

        $default_lang = 'none' !== $plugin
            ? self::get_default_language()
            : substr( get_locale(), 0, 2 );

        // â”€â”€ Duplicate lock â”€â”€
        $lock_key = 'luwipress_tpost_' . $source_id . '_' . $language;
        if ( get_transient( $lock_key ) ) {
            return new \WP_Error( 'locked', 'Translation lock active for #' . $source_id . ' â†’ ' . $language );
        }
        set_transient( $lock_key, 1, 60 );

        // â”€â”€ Get WPML trid (if WPML) â”€â”€
        $trid = null;
        if ( 'wpml' === $plugin && defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
            if ( ! $trid ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'no_trid', 'No WPML trid for #' . $source_id );
            }

            // Check if translation already exists
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code = %s AND element_type = %s",
                $trid, $language, $element_type
            ) );
            if ( $existing_id && get_post_status( $existing_id ) ) {
                delete_transient( $lock_key );
                return absint( $existing_id ); // Already exists â€” return existing ID
            }
        }

        // â”€â”€ Create the post via direct DB insert (bypass WPML hooks entirely) â”€â”€
        $title   = $post_data['title']   ?? $source_post->post_title;
        $slug    = $post_data['slug']    ?? sanitize_title( $title ) . '-' . $language;
        $content = $post_data['content'] ?? '';
        $excerpt = $post_data['excerpt'] ?? '';

        $new_id = $wpdb->insert(
            $wpdb->posts,
            array(
                'post_author'  => $source_post->post_author,
                'post_title'   => $title,
                'post_name'    => wp_unique_post_slug( $slug, 0, 'publish', $post_type, 0 ),
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_status'  => 'publish',
                'post_type'    => $post_type,
                'post_date'    => current_time( 'mysql' ),
                'post_date_gmt'     => current_time( 'mysql', true ),
                'post_modified'     => current_time( 'mysql' ),
                'post_modified_gmt' => current_time( 'mysql', true ),
                'comment_status'    => $source_post->comment_status,
                'ping_status'       => $source_post->ping_status,
                'post_parent'       => 0,
                'guid'              => '',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
        );

        if ( ! $new_id ) {
            delete_transient( $lock_key );
            return new \WP_Error( 'insert_failed', 'Failed to insert translation post for #' . $source_id );
        }

        $new_post_id = absint( $wpdb->insert_id );

        // Clean object cache
        clean_post_cache( $new_post_id );

        // NOTE: the guid update (which calls get_permalink) deliberately runs
        // AFTER the translation-plugin registration below. get_permalink() pipes
        // through WPML's URL filters, which resolve AND CACHE the post's language
        // details for the rest of the request; doing that before the
        // icl_translations row exists caches "no language row", and WPML's
        // save_post handler then re-stamps the post as a default-language
        // original with a fresh trid on the next wp_update_post (the 2026-06-10
        // orphan-page bug: 14 orphan "About us" copies on a production site).

        // â”€â”€ Register with translation plugin â”€â”€
        if ( 'wpml' === $plugin && $trid ) {
            // Remove any auto-created WPML record (should not exist since we bypassed hooks)
            $wpdb->delete(
                $wpdb->prefix . 'icl_translations',
                array( 'element_id' => $new_post_id, 'element_type' => $element_type ),
                array( '%d', '%s' )
            );

            // Also remove any record that might occupy this trid+language slot
            $wpdb->delete(
                $wpdb->prefix . 'icl_translations',
                array( 'trid' => $trid, 'language_code' => $language, 'element_type' => $element_type ),
                array( '%d', '%s', '%s' )
            );

            // Register via the OFFICIAL WPML action first -- unlike a direct DB
            // insert it also updates WPML's per-request element-language caches,
            // so a later save_post (e.g. the translated-title wp_update_post in
            // translate_page) cannot re-stamp the post from a stale "no language
            // row" cache read.
            try {
                do_action( 'wpml_set_element_language_details', array(
                    'element_id'           => $new_post_id,
                    'element_type'         => $element_type,
                    'trid'                 => $trid,
                    'language_code'        => $language,
                    'source_language_code' => $default_lang,
                ) );
            } catch ( \Throwable $e ) {
                // WPML_Set_Language::set() can throw (e.g. InvalidArgumentException
                // on duplicate checks). Swallow -- the fallback below writes the row.
                LuwiPress_Logger::log( 'wpml_set_element_language_details threw: ' . $e->getMessage(), 'warning' );
            }

            // Direct-insert fallback for contexts where the action is not wired.
            $landed = $wpdb->get_var( $wpdb->prepare(
                "SELECT translation_id FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $new_post_id, $element_type
            ) );
            if ( ! $landed ) {
                $wpdb->insert(
                    $wpdb->prefix . 'icl_translations',
                    array(
                        'element_type'         => $element_type,
                        'element_id'           => $new_post_id,
                        'trid'                 => $trid,
                        'language_code'        => $language,
                        'source_language_code' => $default_lang,
                    ),
                    array( '%s', '%d', '%d', '%s', '%s' )
                );
                // Raw SQL wrote behind WPML's back. WPML caches element language
                // details in per-request PHP arrays (including NEGATIVE "no row"
                // results); wp_cache_delete and 'wpml_cache_clear' do NOT touch
                // them -- reload() is the only effective invalidation. Without it
                // WPML's save_post re-stamps this post as a default-language
                // original with a fresh trid on the next wp_update_post.
                global $wpml_post_translations;
                if ( isset( $wpml_post_translations ) && is_object( $wpml_post_translations ) && method_exists( $wpml_post_translations, 'reload' ) ) {
                    $wpml_post_translations->reload();
                }
            }

            // Force WPML to re-read the icl_translations row we just wrote. Without
            // these cache flushes the next apply_filters('wpml_post_language_details')
            // can return a stale "this is an EN original" answer (taken from before
            // our insert), which downstream code then trusts to mean "this post is a
            // valid translation source" — exactly the cascade-duplication bug where
            // a freshly-created IT/FR translation gets re-translated to other langs
            // on the next cron tick.
            wp_cache_delete( $new_post_id, 'wpml-element-language-details' );
            wp_cache_delete( $new_post_id, 'wpml-element-language-code' );
            wp_cache_delete( 'all', 'wpml-language-details' );
            do_action( 'wpml_cache_clear' );

            // Verify the row really landed with the correct language_code -- WPML save_post
            // hooks can run after our direct insert and overwrite the row in some cases.
            // If the persisted row doesn't match what we wrote, force-correct it.
            $verify = $wpdb->get_row( $wpdb->prepare(
                "SELECT language_code, source_language_code, trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $new_post_id, $element_type
            ) );

            if ( $verify && ( $verify->language_code !== $language || $verify->source_language_code !== $default_lang || (int) $verify->trid !== (int) $trid ) ) {
                // WPML hook overwrote our row -- force-correct it instead of deleting the
                // post (the post itself is fine, only the icl_translations metadata is
                // wrong). trid drift included: without it a wrong-trid row skipped repair
                // and fell into the catastrophic delete below.
                $wpdb->update(
                    $wpdb->prefix . 'icl_translations',
                    array(
                        'language_code'        => $language,
                        'source_language_code' => $default_lang,
                        'trid'                 => $trid,
                    ),
                    array( 'element_id' => $new_post_id, 'element_type' => $element_type ),
                    array( '%s', '%s', '%d' ),
                    array( '%d', '%s' )
                );
                // Raw SQL again -- WPML's in-memory element cache is now stale; only
                // reload() invalidates it (the wp_cache group below does not exist).
                global $wpml_post_translations;
                if ( isset( $wpml_post_translations ) && is_object( $wpml_post_translations ) && method_exists( $wpml_post_translations, 'reload' ) ) {
                    $wpml_post_translations->reload();
                }
                wp_cache_delete( $new_post_id, 'wpml-element-language-details' );
                LuwiPress_Logger::log(
                    sprintf( 'WPML row force-corrected for #%d → %s (was %s)', $new_post_id, $language, $verify->language_code ),
                    'warning'
                );
                $verify = $wpdb->get_row( $wpdb->prepare(
                    "SELECT language_code, source_language_code, trid FROM {$wpdb->prefix}icl_translations
                     WHERE element_id = %d AND element_type = %s",
                    $new_post_id, $element_type
                ) );
            }

            if ( ! $verify || $verify->language_code !== $language || (int) $verify->trid !== (int) $trid ) {
                // Catastrophic failure -- trash and abort
                wp_delete_post( $new_post_id, true );
                delete_transient( $lock_key );
                LuwiPress_Logger::log(
                    sprintf( 'CRITICAL: WPML registration failed for translation of #%d -> %s. Post deleted.', $source_id, $language ),
                    'error'
                );
                return new \WP_Error( 'wpml_failed', 'WPML registration failed -- translation post deleted' );
            }

        } elseif ( 'polylang' === $plugin && function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $new_post_id, $language );
            $translations = pll_get_post_translations( $source_id );
            $translations[ $language ] = $new_post_id;
            pll_save_post_translations( $translations );
        }

        // â”€â”€ Copy featured image â”€â”€
        $thumb_id = get_post_thumbnail_id( $source_id );
        if ( $thumb_id ) {
            update_post_meta( $new_post_id, '_thumbnail_id', $thumb_id );
        }

        // Update guid -- AFTER registration so WPML's permalink filters resolve
        // (and cache) the CORRECT language details for the new post.
        $wpdb->update(
            $wpdb->posts,
            array( 'guid' => get_permalink( $new_post_id ) ?: ( home_url( '/?p=' . $new_post_id ) ) ),
            array( 'ID' => $new_post_id ),
            array( '%s' ),
            array( '%d' )
        );
        clean_post_cache( $new_post_id );

        // Stamp the source/language relationship so auto-cleanup can RE-STAMP the WPML
        // row instead of just deleting it when WPML hooks corrupt language_code. Without
        // this we'd loop forever: WPML mis-stamps -> we delete -> missing-list shows
        // source as needing translation -> we translate again -> WPML mis-stamps again.
        update_post_meta( $new_post_id, '_luwipress_translation_source', absint( $source_id ) );
        update_post_meta( $new_post_id, '_luwipress_translation_language', sanitize_text_field( $language ) );

        delete_transient( $lock_key );

        LuwiPress_Logger::log(
            sprintf( 'Translation post created: #%d (%s) from #%d [%s] via %s',
                $new_post_id, strtoupper( $language ), $source_id, $post_type, $plugin ),
            'info'
        );

        return $new_post_id;
    }

    /**
     * Re-assert the WPML registration of a translation post we created.
     *
     * WPML's save_post handler can re-stamp a freshly created translation post
     * as a default-language original with a FRESH trid when its per-request
     * element-language cache predates our icl_translations insert (the
     * 2026-06-10 orphan-page bug). Call this after any wp_update_post() on a
     * translation post to verify the row still matches the expected
     * (language, trid, source) and force-correct it when it drifted.
     *
     * Safe by design: it never steals a (trid, language) slot that a DIFFERENT
     * living post occupies -- in that case it logs and bails.
     *
     * @param int    $post_id   Translation post ID (the post we created/updated).
     * @param int    $source_id Source (default-language) post ID.
     * @param string $language  Target language code the post must be stamped as.
     * @return bool  True when the registration is verified healthy (or fixed).
     */
    public function ensure_translation_registration( $post_id, $source_id, $language ) {
        global $wpdb;

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return false;
        }

        $post_id   = absint( $post_id );
        $source_id = absint( $source_id );
        $post      = get_post( $post_id );
        if ( ! $post || ! $post_id || ! $source_id ) {
            return false;
        }

        $element_type = 'post_' . $post->post_type;
        $default_lang = self::get_default_language();
        $trid         = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
        if ( ! $trid ) {
            return false;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT translation_id, language_code, source_language_code, trid
             FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s",
            $post_id, $element_type
        ) );

        if ( $row && $row->language_code === $language
            && (int) $row->trid === (int) $trid
            && $row->source_language_code === $default_lang ) {
            return true; // Healthy -- nothing to do.
        }

        // Never steal a slot a DIFFERENT living post legitimately occupies.
        $occupant = $wpdb->get_var( $wpdb->prepare(
            "SELECT element_id FROM {$wpdb->prefix}icl_translations
             WHERE trid = %d AND language_code = %s AND element_type = %s",
            $trid, $language, $element_type
        ) );
        if ( $occupant && (int) $occupant !== $post_id ) {
            // Trash/auto-draft occupants are NOT living -- treating them as alive
            // would leave the fresh translation mis-stamped as an EN original.
            $occupant_status = get_post_status( (int) $occupant );
            if ( in_array( $occupant_status, array( 'publish', 'draft', 'private', 'pending', 'future' ), true ) ) {
                LuwiPress_Logger::log( sprintf(
                    'ensure_translation_registration: trid %d/%s already held by living post #%d -- not re-stamping #%d',
                    $trid, $language, (int) $occupant, $post_id
                ), 'warning' );
                return false;
            }
            // Dead occupant (deleted/trashed post) -- clear the stale row first.
            $wpdb->delete(
                $wpdb->prefix . 'icl_translations',
                array( 'trid' => $trid, 'language_code' => $language, 'element_type' => $element_type ),
                array( '%d', '%s', '%s' )
            );
        }

        // Official action keeps WPML's per-request caches coherent.
        try {
            do_action( 'wpml_set_element_language_details', array(
                'element_id'           => $post_id,
                'element_type'         => $element_type,
                'trid'                 => $trid,
                'language_code'        => $language,
                'source_language_code' => $default_lang,
            ) );
        } catch ( \Throwable $e ) {
            // WPML_Set_Language::set() can throw on duplicate checks; the SQL
            // fallback below converges the row either way.
            LuwiPress_Logger::log( 'wpml_set_element_language_details threw: ' . $e->getMessage(), 'warning' );
        }

        // Verify; force-correct via SQL when the action did not land.
        $verify = $wpdb->get_row( $wpdb->prepare(
            "SELECT language_code, source_language_code, trid
             FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s",
            $post_id, $element_type
        ) );
        if ( ! $verify ) {
            $wpdb->insert(
                $wpdb->prefix . 'icl_translations',
                array(
                    'element_type'         => $element_type,
                    'element_id'           => $post_id,
                    'trid'                 => $trid,
                    'language_code'        => $language,
                    'source_language_code' => $default_lang,
                ),
                array( '%s', '%d', '%d', '%s', '%s' )
            );
        } elseif ( $verify->language_code !== $language
            || (int) $verify->trid !== (int) $trid
            || $verify->source_language_code !== $default_lang ) {
            $wpdb->update(
                $wpdb->prefix . 'icl_translations',
                array(
                    'language_code'        => $language,
                    'source_language_code' => $default_lang,
                    'trid'                 => $trid,
                ),
                array( 'element_id' => $post_id, 'element_type' => $element_type ),
                array( '%s', '%s', '%d' ),
                array( '%d', '%s' )
            );
        }

        // If the official action did not land we wrote via raw SQL above, and
        // WPML's in-memory per-request element cache still holds the stale
        // state. reload() is the only effective invalidation -- the wp_cache
        // groups below don't exist in WPML; only the 'wpml_cache_clear' action
        // is plausibly consumed by third-party cache layers.
        global $wpml_post_translations;
        if ( isset( $wpml_post_translations ) && is_object( $wpml_post_translations ) && method_exists( $wpml_post_translations, 'reload' ) ) {
            $wpml_post_translations->reload();
        }
        wp_cache_delete( $post_id, 'wpml-element-language-details' );
        wp_cache_delete( $post_id, 'wpml-element-language-code' );
        wp_cache_delete( 'all', 'wpml-language-details' );
        do_action( 'wpml_cache_clear' );

        // Final verification -- the SQL fallback can fail silently (e.g. unique
        // key collision on (trid, language_code) with a WPML placeholder row).
        // Honour the docblock contract: only report success when the row really
        // matches.
        $final = $wpdb->get_row( $wpdb->prepare(
            "SELECT language_code, source_language_code, trid
             FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s",
            $post_id, $element_type
        ) );
        if ( ! $final || $final->language_code !== $language
            || (int) $final->trid !== (int) $trid
            || $final->source_language_code !== $default_lang ) {
            LuwiPress_Logger::log( sprintf(
                'ensure_translation_registration FAILED for #%d -> %s (trid %d): row is %s',
                $post_id, $language, $trid,
                $final ? sprintf( '%s/trid %s', $final->language_code, $final->trid ) : 'missing'
            ), 'error' );
            return false;
        }

        LuwiPress_Logger::log( sprintf(
            'WPML registration re-asserted for #%d -> %s (trid %d) after post update%s',
            $post_id, $language, $trid,
            $row ? sprintf( ' (row had drifted to %s/trid %s)', $row->language_code, $row->trid ) : ' (row was missing)'
        ), 'warning' );

        return true;
    }

    /**
     * Create WPML translation
     */
    private function create_wpml_translation( $product_id, $language, $translated ) {
        global $wpdb;

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            LuwiPress_Logger::log( 'WPML not active, cannot save translation', 'warning' );
            return;
        }

        $post_obj     = get_post( $product_id );
        $post_type    = $post_obj ? $post_obj->post_type : 'product';
        $element_type = 'post_' . $post_type;

        $trid = apply_filters( 'wpml_element_trid', null, $product_id, $element_type );
        if ( ! $trid ) {
            LuwiPress_Logger::log( 'No WPML trid for ' . $post_type . ' #' . $product_id . ' (element_type: ' . $element_type . ')', 'warning' );
            return;
        }

        $default_lang  = self::get_default_language();
        $translated_id = apply_filters( 'wpml_object_id', $product_id, $post_type, false, $language );

        // Determine WPML language of the post we were handed. If the caller passed a post whose
        // WPML language already matches the target (e.g. retranslating the EN copy itself from a
        // different source), `wpml_object_id` returns the same id and the downstream branches skip
        // the update. Detect that case and rewrite the EN copy in place.
        $post_language = self::get_post_wpml_language( $product_id ) ?: '';

        LuwiPress_Logger::log( 'WPML save: source=#' . $product_id . ' trid=' . $trid . ' lang=' . $language . ' post_lang=' . ( $post_language ?: '?' ) . ' translated_id=' . ( $translated_id ?: 'null' ) . ' name_len=' . strlen( $translated['name'] ?? '' ) . ' desc_len=' . strlen( $translated['description'] ?? '' ), 'info' );

        // Check if source is an Elementor page
        $is_elementor = LuwiPress_Elementor::is_elementor_page( $product_id );
        // Skip old copy_elementor_translated when chunked translation will handle it
        $is_chunked = get_post_meta( $product_id, '_luwipress_elementor_chunked', true );

        // Self-retranslate: the passed post IS the target-language copy (e.g. EN copy of a product
        // whose content was corrupted, being rewritten in place from a cleaner source like ES).
        if ( $translated_id && $translated_id === $product_id && $post_language === $language ) {
            $existing_post = get_post( $product_id );
            $needs_slug    = $existing_post && ( is_numeric( $existing_post->post_name ) || empty( $existing_post->post_name ) );
            $update_data   = array(
                'ID'           => $product_id,
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'] ?? '',
                'post_status'  => 'publish',
            );
            if ( $needs_slug && ! empty( $translated['name'] ) ) {
                $update_data['post_name'] = ! empty( $translated['slug'] ) ? sanitize_title( $translated['slug'] ) : sanitize_title( $translated['name'] );
            }
            $self_result = wp_update_post( $update_data, true );
            if ( is_wp_error( $self_result ) ) {
                LuwiPress_Logger::log( 'WPML self-update FAILED: #' . $product_id . ' â€” ' . $self_result->get_error_message(), 'error' );
            } else {
                LuwiPress_Logger::log( 'WPML self-update: #' . $product_id . ' (' . strtoupper( $language ) . ') content_len=' . strlen( $translated['description'] ?? '' ), 'info' );
            }
            if ( $is_elementor && ! $is_chunked ) {
                $this->copy_elementor_translated( $product_id, $product_id, $translated );
            }
            clean_post_cache( $product_id );
            return;
        }

        if ( $translated_id && $translated_id !== $product_id ) {
            // â”€â”€ Update existing translation â”€â”€
            $existing_post = get_post( $translated_id );
            $needs_slug = $existing_post && ( is_numeric( $existing_post->post_name ) || empty( $existing_post->post_name ) );
            $update_data = array(
                'ID'           => $translated_id,
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'] ?? '',
                'post_status'  => 'publish',
            );
            if ( $needs_slug && ! empty( $translated['name'] ) ) {
                $update_data['post_name'] = ! empty( $translated['slug'] ) ? sanitize_title( $translated['slug'] ) : sanitize_title( $translated['name'] );
            }
            $result = wp_update_post( $update_data, true );

            if ( is_wp_error( $result ) ) {
                LuwiPress_Logger::log( 'WPML update FAILED: #' . $translated_id . ' â€” ' . $result->get_error_message(), 'error' );
            } else {
                LuwiPress_Logger::log( 'WPML translation updated: #' . $translated_id . ' (' . strtoupper( $language ) . ') title="' . mb_substr( $translated['name'], 0, 50 ) . '" content_len=' . strlen( $translated['description'] ?? '' ), 'info' );
            }

            // â”€â”€ Elementor: copy _elementor_data and replace text widgets â”€â”€
            // Skip if chunked translation is active (it handles Elementor data separately)
            if ( $is_elementor && ! $is_chunked ) {
                $this->copy_elementor_translated( $product_id, $translated_id, $translated );
            }

            $target_id = $translated_id;

            // â”€â”€ Ensure images match original â”€â”€
            if ( 'product' === $post_type ) {
                $this->copy_product_images( $product_id, $translated_id );
            } else {
                // Copy featured image for non-product posts (blog, pages)
                $thumb_id = get_post_thumbnail_id( $product_id );
                if ( $thumb_id ) {
                    set_post_thumbnail( $translated_id, $thumb_id );
                }
            }
        } else {
            // â”€â”€ Create new translation post via centralized method â”€â”€
            $new_id = $this->create_translation_post( $product_id, $language, array(
                'title'   => $translated['name'],
                'slug'    => $translated['slug'] ?? '',
                'content' => $translated['description'],
                'excerpt' => $translated['short_description'] ?? '',
            ) );

            if ( is_wp_error( $new_id ) ) {
                LuwiPress_Logger::log( 'Translation creation failed: ' . $new_id->get_error_message(), 'error' );
                return;
            }

            $target_id = $new_id;

            // â”€â”€ Elementor: copy structure with translated text â”€â”€
            if ( $is_elementor && ! $is_chunked ) {
                $this->copy_elementor_translated( $product_id, $new_id, $translated );
            }

            // â”€â”€ Copy WooCommerce-specific meta & taxonomies (products only) â”€â”€
            if ( 'product' === $post_type ) {
                $wc_meta_keys = array(
                    '_price', '_regular_price', '_sale_price', '_sku',
                    '_stock', '_stock_status', '_manage_stock', '_backorders',
                    '_weight', '_length', '_width', '_height',
                    '_virtual', '_downloadable', '_sold_individually',
                    '_tax_status', '_tax_class',
                    '_thumbnail_id', '_product_image_gallery',
                    '_upsell_ids', '_crosssell_ids',
                    '_product_attributes', '_default_attributes',
                    '_purchase_note', '_product_url', '_button_text',
                    'total_sales', '_wc_average_rating', '_wc_review_count',
                );
                foreach ( $wc_meta_keys as $key ) {
                    $val = get_post_meta( $product_id, $key, true );
                    if ( '' !== $val && false !== $val ) {
                        update_post_meta( $new_id, $key, $val );
                    }
                }

                $type_terms = wp_get_object_terms( $product_id, 'product_type', array( 'fields' => 'slugs' ) );
                if ( ! empty( $type_terms ) && ! is_wp_error( $type_terms ) ) {
                    wp_set_object_terms( $new_id, $type_terms, 'product_type' );
                }

                $visibility = wp_get_object_terms( $product_id, 'product_visibility', array( 'fields' => 'slugs' ) );
                if ( ! empty( $visibility ) && ! is_wp_error( $visibility ) ) {
                    wp_set_object_terms( $new_id, $visibility, 'product_visibility' );
                }

                $this->copy_wpml_taxonomy_translations( $product_id, $new_id, $language, 'product_cat' );
                $this->copy_wpml_taxonomy_translations( $product_id, $new_id, $language, 'product_tag' );
            }

        }

        // â”€â”€ Save SEO meta (Rank Math / Yoast) â”€â”€
        if ( $target_id && ( ! empty( $translated['meta_title'] ) || ! empty( $translated['meta_description'] ) ) ) {
            $detector = LuwiPress_Plugin_Detector::get_instance();
            $seo      = $detector->detect_seo();

            if ( ! empty( $seo['meta_keys']['title'] ) && ! empty( $translated['meta_title'] ) ) {
                update_post_meta( $target_id, $seo['meta_keys']['title'], sanitize_text_field( $translated['meta_title'] ) );
            }
            if ( ! empty( $seo['meta_keys']['description'] ) && ! empty( $translated['meta_description'] ) ) {
                update_post_meta( $target_id, $seo['meta_keys']['description'], sanitize_text_field( $translated['meta_description'] ) );
            }
            // Rank Math focus keyword
            if ( ! empty( $translated['focus_keyword'] ) && ! empty( $seo['meta_keys']['focus_keyword'] ) ) {
                update_post_meta( $target_id, $seo['meta_keys']['focus_keyword'], sanitize_text_field( $translated['focus_keyword'] ) );
            }
        }

        // ── Save translated FAQ schema to the target post (ISSUE-032) ──
        // _luwipress_faq is the AEO FAQPage source. Without this write,
        // FR/IT/ES product pages render an empty FAQ tab and FAQPage JSON-LD
        // even when the AI produced a perfectly good translation set.
        if ( $target_id && ! empty( $translated['faq'] ) && is_array( $translated['faq'] ) ) {
            update_post_meta( $target_id, '_luwipress_faq', $translated['faq'] );
        }
    }

    /**
     * AJAX: Fix images for all existing WPML translations.
     * Copies _thumbnail_id and _product_image_gallery from original to all translated products.
     */
    /**
     * AJAX: Fix category assignments for all WPML translated products.
     * Re-assigns each translated product to the correct translated category.
     */
    public function ajax_fix_category_assignments() {
        check_ajax_referer( 'luwipress_fix_categories', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        global $wpdb;
        $default_lang = self::get_default_language();

        // Get all original posts/products/pages
        $originals = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.element_id AS post_id, t.trid, p.post_type
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.element_type IN ('post_product', 'post_post', 'post_page')
               AND t.language_code = %s
               AND t.source_language_code IS NULL
               AND p.post_status = 'publish'",
            $default_lang
        ) );

        $fixed = 0;
        foreach ( $originals as $row ) {
            $translations = $wpdb->get_results( $wpdb->prepare(
                "SELECT element_id, language_code FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code != %s AND element_id IS NOT NULL",
                $row->trid, $default_lang
            ) );

            // Determine which taxonomies to copy based on post type
            $taxonomies = array();
            if ( 'product' === $row->post_type ) {
                $taxonomies = array( 'product_cat', 'product_tag' );
            } elseif ( 'post' === $row->post_type ) {
                $taxonomies = array( 'category', 'post_tag' );
            }

            foreach ( $translations as $tr ) {
                foreach ( $taxonomies as $tax ) {
                    $this->copy_wpml_taxonomy_translations( $row->post_id, $tr->element_id, $tr->language_code, $tax );
                }
                $fixed++;
            }
        }

        LuwiPress_Logger::log( 'Category assignments fixed for ' . $fixed . ' translated posts/products', 'info' );
        wp_send_json_success( array( 'fixed' => $fixed ) );
    }

    public function ajax_fix_translation_images() {
        check_ajax_referer( 'luwipress_fix_images', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        global $wpdb;
        $default_lang = self::get_default_language();

        // Get all default-language posts, pages, and products
        $originals = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.element_id AS post_id, t.trid, p.post_type
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.element_type IN ('post_product', 'post_post', 'post_page')
               AND t.language_code = %s
               AND t.source_language_code IS NULL
               AND p.post_status = 'publish'",
            $default_lang
        ) );

        $fixed = 0;
        foreach ( $originals as $row ) {
            $source_thumb = get_post_thumbnail_id( $row->post_id );
            if ( ! $source_thumb ) {
                continue;
            }

            $translations = $wpdb->get_results( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code != %s AND element_id IS NOT NULL",
                $row->trid, $default_lang
            ) );

            foreach ( $translations as $tr ) {
                $tr_thumb = get_post_thumbnail_id( $tr->element_id );
                if ( ! $tr_thumb || $tr_thumb !== $source_thumb ) {
                    set_post_thumbnail( $tr->element_id, $source_thumb );
                    $fixed++;
                }
                // Also copy product gallery for WC products
                if ( 'product' === $row->post_type ) {
                    $this->copy_product_images( $row->post_id, $tr->element_id );
                }
            }
        }

        wp_send_json_success( array( 'fixed' => $fixed ) );
    }

    /**
     * Translate an Elementor page by chunking long widget content.
     *
     * Instead of sending the entire page content as one AI call (which fails
     * JSON parse on long content), this method:
     * 1. Extracts translatable text from each widget in _elementor_data
     * 2. Splits long HTML content (>3000 chars) into chunks at <h2>/<h3> boundaries
     * 3. Translates each chunk with dispatch() (plain HTML, not JSON)
     * 4. Writes translated text directly to the target post's _elementor_data
     *
     * @param int    $source_id   Source post ID.
     * @param int    $target_id   Target (translated) post ID.
     * @param string $source_lang Source language name (e.g. 'Turkish').
     * @param string $target_lang Target language name (e.g. 'French').
     * @return true|WP_Error
     */
    private function translate_elementor_chunked( $source_id, $target_id, $source_lang, $target_lang ) {
        $raw_data = get_post_meta( $source_id, '_elementor_data', true );
        if ( empty( $raw_data ) ) {
            return new WP_Error( 'no_elementor', 'No Elementor data for source post #' . $source_id );
        }

        $data = is_string( $raw_data ) ? json_decode( $raw_data, true ) : $raw_data;
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'parse_error', 'Failed to parse Elementor JSON for #' . $source_id );
        }

        // Copy Elementor structure to target first
        update_post_meta( $target_id, '_elementor_edit_mode', 'builder' );
        $page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );
        if ( $page_settings ) {
            update_post_meta( $target_id, '_elementor_page_settings', $page_settings );
        }

        // Translatable text keys grouped by type
        $title_keys = array( 'title', 'heading_title', 'ekit_heading_title', 'ekit_heading_focused_title' );
        $content_keys = array( 'editor', 'tab_content', 'description', 'description_text', 'ekit_heading_extra_title', 'ekit_heading_description' );
        $short_keys = array( 'ekit_heading_sub_title', 'button_text', 'alert_title', 'text' );
        $all_keys = array_merge( $title_keys, $content_keys, $short_keys );

        // Walk the element tree, translate widget texts, rebuild data
        $data = $this->walk_and_translate_elements( $data, $all_keys, $title_keys, $content_keys, $source_lang, $target_lang );

        // Save translated Elementor data to target post
        update_post_meta( $target_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

        // Clear CSS cache
        delete_post_meta( $target_id, '_elementor_css' );
        delete_post_meta( $target_id, '_elementor_page_assets' );

        LuwiPress_Logger::log( 'Elementor chunked translation completed: #' . $source_id . ' â†’ #' . $target_id . ' (' . $target_lang . ')', 'info' );

        return true;
    }

    /**
     * Recursively walk Elementor elements and translate text settings.
     *
     * @param array  $elements     Elementor element tree.
     * @param array  $all_keys     All translatable setting keys.
     * @param array  $title_keys   Keys that contain titles (short text).
     * @param array  $content_keys Keys that contain long content (HTML).
     * @param string $source_lang  Source language name.
     * @param string $target_lang  Target language name.
     * @return array Modified element tree.
     */
    private function walk_and_translate_elements( array $elements, $all_keys, $title_keys, $content_keys, $source_lang, $target_lang ) {
        foreach ( $elements as &$element ) {
            if ( ! empty( $element['settings'] ) && ! empty( $element['widgetType'] ) ) {
                foreach ( $all_keys as $key ) {
                    if ( empty( $element['settings'][ $key ] ) || ! is_string( $element['settings'][ $key ] ) ) {
                        continue;
                    }

                    $original = $element['settings'][ $key ];
                    // Skip very short strings (not translatable)
                    if ( strlen( strip_tags( $original ) ) < 5 ) {
                        continue;
                    }

                    // Strip leading <h1> from ekit_heading_extra_title if widget has a separate title
                    if ( 'ekit_heading_extra_title' === $key && ! empty( $element['settings']['ekit_heading_title'] ) ) {
                        $original = preg_replace( '/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', $original, 1 );
                    }

                    // Long content â†’ chunk and translate
                    if ( in_array( $key, $content_keys, true ) && strlen( $original ) > 3000 ) {
                        $translated = $this->translate_html_chunked( $original, $source_lang, $target_lang );
                    } else {
                        // Short text (titles, buttons, etc.) â†’ single AI call
                        $translated = $this->translate_html_single( $original, $source_lang, $target_lang );
                    }

                    if ( ! is_wp_error( $translated ) && ! empty( $translated ) ) {
                        $element['settings'][ $key ] = $translated;
                    } else {
                        LuwiPress_Logger::log(
                            'Elementor chunk translation failed for key=' . $key . ' widget=' . ( $element['id'] ?? '?' ) . ': ' . ( is_wp_error( $translated ) ? $translated->get_error_message() : 'empty' ),
                            'warning'
                        );
                    }
                }
            }

            // Recurse into children
            if ( ! empty( $element['elements'] ) ) {
                $element['elements'] = $this->walk_and_translate_elements( $element['elements'], $all_keys, $title_keys, $content_keys, $source_lang, $target_lang );
            }
        }

        return $elements;
    }

    /**
     * Translate a long HTML string by splitting into chunks at heading boundaries.
     *
     * @param string $html        HTML content to translate.
     * @param string $source_lang Source language name.
     * @param string $target_lang Target language name.
     * @return string|WP_Error Translated HTML or error.
     */
    private function translate_html_chunked( $html, $source_lang, $target_lang ) {
        $chunks = $this->split_html_by_headings( $html, 3000 );

        LuwiPress_Logger::log(
            'Elementor chunked: ' . count( $chunks ) . ' chunks from ' . strlen( $html ) . ' chars',
            'info'
        );

        $translated_chunks = array();
        foreach ( $chunks as $i => $chunk ) {
            $result = $this->translate_html_single( $chunk, $source_lang, $target_lang );
            if ( is_wp_error( $result ) ) {
                LuwiPress_Logger::log( 'Chunk ' . $i . ' translation failed: ' . $result->get_error_message(), 'warning' );
                // Keep original chunk on failure rather than breaking the whole page
                $translated_chunks[] = $chunk;
            } else {
                $translated_chunks[] = $result;
            }
        }

        return implode( '', $translated_chunks );
    }

    /**
     * Translate a single HTML string via AI (plain HTML response, not JSON).
     *
     * @param string $html        HTML content.
     * @param string $source_lang Source language name.
     * @param string $target_lang Target language name.
     * @return string|WP_Error Translated HTML or error.
     */
    private function translate_html_single( $html, $source_lang, $target_lang ) {
        // NOTE: The Elementor HTML path stays on the LLM for now. DeepL supports
        // tag_handling=html and could serve this too (follow-up) — the DeepL
        // engine toggle currently only routes the product/page body fields in
        // request_translation(), not Elementor widget chunks.
        $prompt   = LuwiPress_Prompts::elementor_html_translation( $html, $source_lang, $target_lang );
        $messages = LuwiPress_AI_Engine::build_messages( $prompt );

        $max_tokens = max( 2048, intval( strlen( $html ) / 2 ) );
        $max_tokens = min( $max_tokens, 16000 );

        $result = LuwiPress_AI_Engine::dispatch( 'translation-pipeline', $messages, array(
            'max_tokens' => $max_tokens,
            'timeout'    => 120,
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $translated = $result['content'] ?? '';

        // Strip any accidental code fences the AI might add
        $translated = preg_replace( '/^```(?:html)?\s*/i', '', $translated );
        $translated = preg_replace( '/\s*```\s*$/', '', $translated );

        return trim( $translated );
    }

    /**
     * Split HTML content into chunks at <h2> or <h3> boundaries.
     * Each chunk stays under $max_chars. If a single section exceeds $max_chars,
     * it is included as-is (the AI can handle slightly larger chunks).
     *
     * @param string $html      Full HTML content.
     * @param int    $max_chars Target maximum characters per chunk.
     * @return array Array of HTML string chunks.
     */
    private function split_html_by_headings( $html, $max_chars = 3000 ) {
        // Split at <h2> or <h3> tags, keeping the delimiter
        $parts = preg_split( '/(?=<h[23][^>]*>)/i', $html );

        if ( empty( $parts ) || count( $parts ) <= 1 ) {
            // No headings found â€” split by paragraphs instead
            $parts = preg_split( '/(?=<p[^>]*>)/i', $html );
        }

        if ( empty( $parts ) || count( $parts ) <= 1 ) {
            // Still can't split â€” return as single chunk
            return array( $html );
        }

        $chunks  = array();
        $current = '';

        foreach ( $parts as $part ) {
            if ( strlen( $current ) + strlen( $part ) > $max_chars && ! empty( $current ) ) {
                $chunks[] = $current;
                $current  = $part;
            } else {
                $current .= $part;
            }
        }

        if ( ! empty( $current ) ) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Handle Elementor page translation.
     * Copies _elementor_data from source, then replaces ALL text content
     * in the JSON with translated text. This preserves Elementor layout
     * while showing translated content.
     */
    private function copy_elementor_translated( $source_id, $target_id, $translated ) {
        $raw_data = get_post_meta( $source_id, '_elementor_data', true );
        if ( empty( $raw_data ) ) {
            return;
        }

        // Copy Elementor structure
        update_post_meta( $target_id, '_elementor_edit_mode', 'builder' );

        // Copy page settings
        $page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );
        if ( $page_settings ) {
            update_post_meta( $target_id, '_elementor_page_settings', $page_settings );
        }

        // Parse Elementor data
        $data = is_string( $raw_data ) ? json_decode( $raw_data, true ) : $raw_data;
        if ( ! is_array( $data ) ) {
            update_post_meta( $target_id, '_elementor_data', $raw_data );
            return;
        }

        // Replace text content in all widgets with translated description
        $translated_desc = $translated['description'] ?? '';
        if ( ! empty( $translated_desc ) ) {
            // Walk the element tree and replace text in all widget types
            $data = $this->replace_elementor_texts( $data, $translated_desc, $translated['name'] ?? '' );
        }

        // Save modified Elementor data
        update_post_meta( $target_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

        // Clear CSS cache
        delete_post_meta( $target_id, '_elementor_css' );
        delete_post_meta( $target_id, '_elementor_page_assets' );

        LuwiPress_Logger::log( 'Elementor data translated for #' . $target_id, 'info' );
    }

    /**
     * Replace text content in Elementor element tree with translated content.
     * Walks recursively through sections > columns > widgets and replaces
     * known text settings (title, editor, description, etc.)
     */
    private function replace_elementor_texts( array $elements, $translated_content, $translated_title = '' ) {
        // Text setting keys found in various Elementor widgets
        $text_keys = array(
            // Standard Elementor
            'title', 'editor', 'description', 'text', 'tab_content',
            'tab_title', 'button_text', 'testimonial_content',
            'testimonial_name', 'testimonial_job', 'alert_title',
            'alert_description', 'heading_title', 'description_text',
            'title_text', 'inner_text', 'prefix', 'suffix',
            // ElementsKit heading widget
            'ekit_heading_title', 'ekit_heading_sub_title',
            'ekit_heading_extra_title', 'ekit_heading_description',
            'ekit_heading_focused_title',
        );

        // Title keys â€” get translated title
        $title_keys = array(
            'title', 'heading_title', 'ekit_heading_title', 'ekit_heading_focused_title',
        );

        // Content keys â€” get translated description (long content)
        $content_keys = array(
            'editor', 'tab_content', 'description', 'description_text',
            'ekit_heading_extra_title', 'ekit_heading_description',
        );

        foreach ( $elements as &$element ) {
            // Process widget settings
            if ( ! empty( $element['settings'] ) && ! empty( $element['widgetType'] ) ) {
                foreach ( $text_keys as $key ) {
                    if ( ! empty( $element['settings'][ $key ] ) && is_string( $element['settings'][ $key ] ) ) {
                        $original = $element['settings'][ $key ];
                        // Skip very short strings (likely not translatable content)
                        if ( strlen( strip_tags( $original ) ) < 3 ) {
                            continue;
                        }

                        // Title keys â†’ use translated title
                        if ( in_array( $key, $title_keys, true ) && ! empty( $translated_title ) ) {
                            $element['settings'][ $key ] = $translated_title;
                            continue;
                        }

                        // Content keys â†’ use translated description
                        if ( in_array( $key, $content_keys, true ) && ! empty( $translated_content ) ) {
                            $element['settings'][ $key ] = $translated_content;
                            $translated_content = '';
                            continue;
                        }
                    }
                }
            }

            // Recurse into children
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->replace_elementor_texts( $element['elements'], $translated_content, $translated_title );
                // If translated_content was consumed by a child, mark it empty
                $translated_content = '';
            }
        }
        unset( $element );

        return $elements;
    }

    /**
     * Copy product images from original to translated product.
     * Simply copies _thumbnail_id and _product_image_gallery meta.
     */
    private function copy_product_images( $source_id, $target_id ) {
        $thumb_id = get_post_meta( $source_id, '_thumbnail_id', true );
        if ( $thumb_id ) {
            update_post_meta( $target_id, '_thumbnail_id', $thumb_id );
        }
        $gallery = get_post_meta( $source_id, '_product_image_gallery', true );
        if ( ! empty( $gallery ) ) {
            update_post_meta( $target_id, '_product_image_gallery', $gallery );
        }
    }

    /**
     * @deprecated Use copy_product_images() instead.
     * Share product images (thumbnail + gallery) across WPML languages.
     * WPML may look for translated attachment IDs â€” this ensures the original
     * attachments are registered for the target language so images display correctly.
     */
    private function wpml_share_product_images( $source_id, $target_id, $language ) {
        global $wpdb;
        $default_lang = self::get_default_language();
        $attachment_ids = array();

        // Collect thumbnail
        $thumb_id = get_post_meta( $source_id, '_thumbnail_id', true );
        if ( $thumb_id ) {
            $attachment_ids[] = (int) $thumb_id;
        }

        // Collect gallery images
        $gallery = get_post_meta( $source_id, '_product_image_gallery', true );
        if ( ! empty( $gallery ) ) {
            $gallery_ids = array_map( 'intval', explode( ',', $gallery ) );
            $attachment_ids = array_merge( $attachment_ids, $gallery_ids );
        }

        $attachment_ids = array_unique( array_filter( $attachment_ids ) );

        foreach ( $attachment_ids as $att_id ) {
            // Check if this attachment already has a WPML translation for target language
            $translated_att = apply_filters( 'wpml_object_id', $att_id, 'attachment', false, $language );

            if ( ! $translated_att || $translated_att === $att_id ) {
                // Register the original attachment as its own translation for target language
                $att_trid = apply_filters( 'wpml_element_trid', null, $att_id, 'post_attachment' );
                if ( $att_trid ) {
                    do_action( 'wpml_set_element_language_details', array(
                        'element_id'           => $att_id,
                        'element_type'         => 'post_attachment',
                        'trid'                 => $att_trid,
                        'language_code'        => $language,
                        'source_language_code' => null,
                    ) );

                    // SQL fallback if WPML action didn't fire
                    $att_linked = $wpdb->get_var( $wpdb->prepare(
                        "SELECT translation_id FROM {$wpdb->prefix}icl_translations
                         WHERE element_id = %d AND element_type = 'post_attachment' AND language_code = %s",
                        $att_id, $language
                    ) );
                    if ( ! $att_linked ) {
                        $wpdb->insert(
                            $wpdb->prefix . 'icl_translations',
                            array(
                                'element_type'         => 'post_attachment',
                                'element_id'           => $att_id,
                                'trid'                 => $att_trid,
                                'language_code'        => $language,
                                'source_language_code' => null,
                            ),
                            array( '%s', '%d', '%d', '%s', '%s' )
                        );
                    }
                }
            }

            // If WPML created a different attachment ID for this language, update meta
            $final_att_id = apply_filters( 'wpml_object_id', $att_id, 'attachment', false, $language );
            if ( $final_att_id && $final_att_id !== $att_id ) {
                // Update thumbnail if this was the thumbnail
                if ( (int) $thumb_id === $att_id ) {
                    update_post_meta( $target_id, '_thumbnail_id', $final_att_id );
                }
            }
        }

        // Re-map gallery IDs for translated product
        if ( ! empty( $gallery ) ) {
            $gallery_ids = array_map( 'intval', explode( ',', $gallery ) );
            $translated_gallery = array();
            foreach ( $gallery_ids as $gid ) {
                $translated_gid = apply_filters( 'wpml_object_id', $gid, 'attachment', true, $language );
                $translated_gallery[] = $translated_gid ?: $gid;
            }
            update_post_meta( $target_id, '_product_image_gallery', implode( ',', $translated_gallery ) );
        }
    }

    /**
     * Copy taxonomy terms with WPML translation linking.
     * If a translated term exists in WPML, use it.
     * If not, auto-create a term translation linked to the original via WPML.
     */
    private function copy_wpml_taxonomy_translations( $source_id, $target_id, $language, $taxonomy ) {
        $terms = wp_get_object_terms( $source_id, $taxonomy );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return;
        }

        $default_lang = self::get_default_language();
        $translated_term_ids = array();

        foreach ( $terms as $term ) {
            // Check if WPML already has a translation for this term
            $translated_term_id = apply_filters( 'wpml_object_id', $term->term_id, $taxonomy, false, $language );

            if ( $translated_term_id && $translated_term_id !== $term->term_id ) {
                $translated_term_ids[] = (int) $translated_term_id;
            } else {
                // No translation â€” auto-create one so each language has its own term
                $new_term_id = $this->auto_create_wpml_term_translation( $term, $taxonomy, $language, $default_lang );
                if ( $new_term_id ) {
                    $translated_term_ids[] = $new_term_id;
                } else {
                    // Fallback to original if creation fails
                    $translated_term_ids[] = (int) $term->term_id;
                }
            }
        }

        if ( ! empty( $translated_term_ids ) ) {
            wp_set_object_terms( $target_id, $translated_term_ids, $taxonomy );
        }
    }

    /**
     * Auto-create a WPML term translation.
     * Creates a new term with the original name (placeholder) and links it via WPML.
     * The taxonomy translation workflow can later update the name to the real translation.
     *
     * @return int|false New term ID or false on failure.
     */
    private function auto_create_wpml_term_translation( $original_term, $taxonomy, $language, $default_lang ) {
        global $wpdb;

        $element_type = 'tax_' . $taxonomy;

        // Get trid of original term
        $trid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s",
            $original_term->term_id, $element_type
        ) );

        if ( ! $trid ) {
            return false;
        }

        // Double-check no translation already exists (race condition guard)
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT element_id FROM {$wpdb->prefix}icl_translations
             WHERE trid = %d AND language_code = %s AND element_type = %s",
            $trid, $language, $element_type
        ) );

        if ( $existing ) {
            return (int) $existing;
        }

        // Resolve parent: if original term has a parent, find or create its translation
        $parent = 0;
        if ( $original_term->parent > 0 ) {
            $parent_translated = apply_filters( 'wpml_object_id', $original_term->parent, $taxonomy, false, $language );
            if ( $parent_translated && $parent_translated !== $original_term->parent ) {
                $parent = (int) $parent_translated;
            }
        }

        // Create the term with original name as placeholder
        $slug = $original_term->slug . '-' . $language;
        $new_term = wp_insert_term( $original_term->name, $taxonomy, array(
            'slug'   => $slug,
            'parent' => $parent,
        ) );

        if ( is_wp_error( $new_term ) ) {
            // Slug conflict â€” try with random suffix
            $new_term = wp_insert_term( $original_term->name, $taxonomy, array(
                'slug'   => $slug . '-' . wp_rand( 100, 999 ),
                'parent' => $parent,
            ) );
            if ( is_wp_error( $new_term ) ) {
                return false;
            }
        }

        $new_term_id = $new_term['term_id'];

        // Link to WPML translation group (with SQL fallback)
        do_action( 'wpml_set_element_language_details', array(
            'element_id'           => $new_term_id,
            'element_type'         => $element_type,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $default_lang,
        ) );

        // Verify and fallback
        global $wpdb;
        $linked = $wpdb->get_var( $wpdb->prepare(
            "SELECT translation_id FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s",
            $new_term_id, $element_type
        ) );

        if ( ! $linked ) {
            $wpdb->insert(
                $wpdb->prefix . 'icl_translations',
                array(
                    'element_type'         => $element_type,
                    'element_id'           => $new_term_id,
                    'trid'                 => $trid,
                    'language_code'        => $language,
                    'source_language_code' => $default_lang,
                ),
                array( '%s', '%d', '%d', '%s', '%s' )
            );
        }

        return $new_term_id;
    }

    /**
     * Create or update Polylang translation.
     * Mirrors WPML create logic: copies product meta, images, taxonomies, SEO meta.
     */
    private function create_polylang_translation($product_id, $language, $translated) {
        if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
            LuwiPress_Logger::log('Polylang API functions not available', 'warning');
            return;
        }

        $translations = pll_get_post_translations($product_id);
        $target_id = null;

        if (isset($translations[$language])) {
            // â”€â”€ Update existing translation â”€â”€
            wp_update_post([
                'ID'           => $translations[$language],
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'] ?? '',
                'post_status'  => 'publish',
            ]);
            $target_id = $translations[$language];

            LuwiPress_Logger::log(
                sprintf('Polylang translation updated: #%d (%s)', $target_id, strtoupper($language)),
                'info',
                ['original_id' => $product_id, 'translated_id' => $target_id, 'language' => $language]
            );
        } else {
            // â”€â”€ Create new translation post â”€â”€
            $original = get_post($product_id);
            if (!$original) {
                return;
            }

            $new_id = wp_insert_post([
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'] ?? '',
                'post_type'    => $original->post_type,
                'post_status'  => $original->post_status,
                'post_author'  => $original->post_author,
            ]);

            if (is_wp_error($new_id)) {
                LuwiPress_Logger::log('Polylang: failed to create translation: ' . $new_id->get_error_message(), 'error');
                return;
            }

            // Assign language and link to original
            pll_set_post_language($new_id, $language);
            $translations[$language] = $new_id;
            pll_save_post_translations($translations);

            $target_id = $new_id;

            // â”€â”€ Copy WooCommerce product meta (whitelist approach) â”€â”€
            $wc_meta_keys = array(
                '_price', '_regular_price', '_sale_price', '_sku',
                '_stock', '_stock_status', '_manage_stock', '_backorders',
                '_weight', '_length', '_width', '_height',
                '_virtual', '_downloadable', '_sold_individually',
                '_tax_status', '_tax_class',
                '_thumbnail_id', '_product_image_gallery',
                '_upsell_ids', '_crosssell_ids',
                '_product_attributes', '_default_attributes',
                '_purchase_note', '_product_url', '_button_text',
                'total_sales', '_wc_average_rating', '_wc_review_count',
            );
            foreach ($wc_meta_keys as $key) {
                $val = get_post_meta($product_id, $key, true);
                if ('' !== $val && false !== $val) {
                    update_post_meta($new_id, $key, $val);
                }
            }

            // â”€â”€ Copy product type taxonomy â”€â”€
            $type_terms = wp_get_object_terms($product_id, 'product_type', ['fields' => 'slugs']);
            if (!empty($type_terms) && !is_wp_error($type_terms)) {
                wp_set_object_terms($new_id, $type_terms, 'product_type');
            }

            // â”€â”€ Copy product visibility â”€â”€
            $visibility = wp_get_object_terms($product_id, 'product_visibility', ['fields' => 'slugs']);
            if (!empty($visibility) && !is_wp_error($visibility)) {
                wp_set_object_terms($new_id, $visibility, 'product_visibility');
            }

            // â”€â”€ Copy product categories and tags â”€â”€
            foreach (['product_cat', 'product_tag'] as $taxonomy) {
                $terms = wp_get_object_terms($product_id, $taxonomy, ['fields' => 'ids']);
                if (!empty($terms) && !is_wp_error($terms)) {
                    wp_set_object_terms($new_id, $terms, $taxonomy);
                }
            }

            // Force publish (some hooks may reset to draft)
            wp_update_post(['ID' => $new_id, 'post_status' => 'publish']);

            LuwiPress_Logger::log(
                sprintf('Polylang translation created: #%d (%s) from #%d â€” "%s"', $new_id, strtoupper($language), $product_id, $translated['name']),
                'info',
                ['original_id' => $product_id, 'translated_id' => $new_id, 'language' => $language]
            );
        }

        // â”€â”€ Copy product images (thumbnail + gallery) â”€â”€
        if ($target_id) {
            $this->copy_product_images($product_id, $target_id);
        }

        // â”€â”€ Save SEO meta via Plugin Detector â”€â”€
        if ($target_id && (!empty($translated['meta_title']) || !empty($translated['meta_description']))) {
            $detector = LuwiPress_Plugin_Detector::get_instance();
            $seo_data = [];
            if (!empty($translated['meta_title'])) {
                $seo_data['title'] = $translated['meta_title'];
            }
            if (!empty($translated['meta_description'])) {
                $seo_data['description'] = $translated['meta_description'];
            }
            if (!empty($translated['focus_keyword'])) {
                $seo_data['focus_keyword'] = $translated['focus_keyword'];
            }
            $detector->set_seo_meta($target_id, $seo_data);
        }

        // ── Save translated FAQ schema to the target post (ISSUE-032) ──
        // Mirrors the WPML branch: persist translated FAQ to target's
        // _luwipress_faq so the AEO FAQPage emits in the target language.
        if ( $target_id && ! empty( $translated['faq'] ) && is_array( $translated['faq'] ) ) {
            update_post_meta( $target_id, '_luwipress_faq', $translated['faq'] );
        }
    }

    // â”€â”€â”€ TAXONOMY TRANSLATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * GET /translation/taxonomy-missing â€” Return missing taxonomy terms for clients to translate.
     * This does NOT trigger a webhook â€” it just returns the data.
     */
    public function get_missing_taxonomy_terms_api($request) {
        $taxonomy = $request->get_param('taxonomy');
        $target_languages_str = $request->get_param('target_languages');
        $limit = min($request->get_param('limit'), 200);

        $target_languages = array_map('trim', explode(',', $target_languages_str));
        $source_language = self::get_default_language();

        if (!taxonomy_exists($taxonomy)) {
            return new WP_Error('invalid_taxonomy', 'Taxonomy not found: ' . $taxonomy, ['status' => 400]);
        }

        $terms = $this->get_missing_taxonomy_terms($taxonomy, $target_languages, $source_language, $limit);

        return rest_ensure_response([
            'taxonomy'         => $taxonomy,
            'source_language'  => $source_language,
            'target_languages' => $target_languages,
            'count'            => count($terms),
            'terms'            => $terms,
        ]);
    }

    /**
     * POST /translation/taxonomy â€” Send untranslated terms to the AI engine for translation
     */
    public function request_taxonomy_translation($request) {
        $taxonomy         = $request->get_param('taxonomy');
        $target_languages = $request->get_param('target_languages');
        $limit            = min($request->get_param('limit'), 200);

        if (is_string($target_languages)) {
            $target_languages = array_map('trim', explode(',', $target_languages));
        }

        if (!taxonomy_exists($taxonomy)) {
            return new WP_Error('invalid_taxonomy', 'Taxonomy not found: ' . $taxonomy, ['status' => 400]);
        }

        $source_language = self::get_default_language();

        // Get untranslated terms
        $missing_terms = $this->get_missing_taxonomy_terms($taxonomy, $target_languages, $source_language, $limit);

        if (empty($missing_terms)) {
            return rest_ensure_response([
                'status'  => 'nothing_to_translate',
                'message' => 'All terms are already translated.',
            ]);
        }

        // Async path: anything past 1 chunk worth of work goes to LuwiPress_Job_Queue.
        // 1 chunk = 25 terms (~10s of AI). Multi-chunk or multi-lang work risks hitting
        // the sync HTTP timeout, so queue it. Single-chunk single-lang work stays sync
        // for snappy small-batch UX.
        $needs_queue = ( count( $missing_terms ) > 25 ) || ( count( $target_languages ) > 1 && count( $missing_terms ) > 10 );
        if ( $needs_queue && class_exists( 'LuwiPress_Job_Queue' ) ) {
            return $this->queue_taxonomy_translation_job( $taxonomy, $missing_terms, $target_languages, $source_language );
        }

        $payload = [
            'taxonomy'         => $taxonomy,
            'source_language'  => $source_language,
            'target_languages' => $target_languages,
            'terms'            => $missing_terms,
        ];

        // Translate directly via AI Engine for each language
        $lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian' );
        $source_name = $lang_names[ $source_language ] ?? ucfirst( $source_language );

        $all_translations = array();
        foreach ( $target_languages as $lang ) {
            $target_name = $lang_names[ $lang ] ?? ucfirst( $lang );

            // Build terms array for prompt
            $terms_for_prompt = array();
            foreach ( $missing_terms as $term ) {
                $terms_for_prompt[] = array(
                    'term_id' => $term['term_id'] ?? 0,
                    'name'    => $term['name'] ?? '',
                    'slug'    => $term['slug'] ?? '',
                );
            }

            $prompt   = LuwiPress_Prompts::taxonomy_translation( $terms_for_prompt, $taxonomy, $source_name, $target_name );
            $messages = LuwiPress_AI_Engine::build_messages( $prompt );
            $ai_result = LuwiPress_AI_Engine::dispatch_json( 'translation-pipeline', $messages, array(
                'max_tokens' => 2000,
            ) );

            if ( is_wp_error( $ai_result ) ) {
                LuwiPress_Logger::log( 'Taxonomy translation failed for ' . $lang, 'error', array( 'taxonomy' => $taxonomy ) );
                continue;
            }

            // Ensure result is an array of translations
            $translated = is_array( $ai_result ) && isset( $ai_result[0] ) ? $ai_result : ( $ai_result['translations'] ?? array() );

            // AI doesn't reliably echo back which target language it translated for.
            // The loop knows â€” stamp every item so the callback's empty($language) guard doesn't silently drop them.
            foreach ( $translated as &$tr_item ) {
                $tr_item['language'] = $lang;
            }
            unset( $tr_item );

            $all_translations = array_merge( $all_translations, $translated );
        }

        // Feed into existing callback handler
        $saved_count   = 0;
        $save_errors   = array();
        $sample_item   = ! empty( $all_translations ) ? $all_translations[0] : null;
        if ( ! empty( $all_translations ) ) {
            $callback_request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/taxonomy-callback' );
            $callback_request->set_body_params( array(
                'taxonomy'     => $taxonomy,
                'translations' => $all_translations,
            ) );
            $callback_response = $this->handle_taxonomy_callback( $callback_request );
            if ( ! is_wp_error( $callback_response ) ) {
                $callback_data = is_object( $callback_response ) && method_exists( $callback_response, 'get_data' ) ? $callback_response->get_data() : array();
                $saved_count   = absint( $callback_data['saved'] ?? 0 );
                $save_errors   = (array) ( $callback_data['errors'] ?? array() );
            } else {
                $save_errors[] = 'callback wp_error: ' . $callback_response->get_error_message();
            }
        }

        // Diagnostic log so support can see why saved=0 happened (sample translation shape + first 3 errors).
        LuwiPress_Logger::log(
            sprintf( 'Taxonomy translation: %s -- sent %d, saved %d', $taxonomy, count( $all_translations ), $saved_count ),
            $saved_count > 0 ? 'info' : 'warning',
            array(
                'taxonomy'      => $taxonomy,
                'languages'     => $target_languages,
                'sent'          => count( $all_translations ),
                'saved'         => $saved_count,
                'sample_keys'   => $sample_item ? array_keys( $sample_item ) : array(),
                'sample_item'   => $sample_item,
                'first_errors'  => array_slice( $save_errors, 0, 3 ),
            )
        );

        return rest_ensure_response( array(
            'status'           => 'completed',
            'taxonomy'         => $taxonomy,
            'target_languages' => $target_languages,
            'terms_sent'       => count( $missing_terms ),
            'saved'            => $saved_count,
            'errors'           => array_slice( $save_errors, 0, 5 ),
            'sample_keys'      => $sample_item ? array_keys( $sample_item ) : array(),
        ) );
    }

    /**
     * POST /translation/taxonomy-callback â€” Receive translated taxonomy terms from async AI pipeline
     */
    public function handle_taxonomy_callback($request) {
        // Read from JSON body OR form-encoded body OR REST params -- internal callers use
        // set_body_params() which lands in body_params, not json_params. get_param() walks
        // all sources, which is what we want for both external HTTP callbacks and internal
        // dispatch from request_taxonomy_translation().
        $taxonomy     = sanitize_text_field( $request->get_param( 'taxonomy' ) ?? '' );
        $translations = $request->get_param( 'translations' );
        if ( ! is_array( $translations ) ) {
            $translations = array();
        }

        if (empty($taxonomy) || empty($translations)) {
            return new WP_Error('invalid_data', 'taxonomy and translations array required', ['status' => 400]);
        }

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return new WP_Error('no_wpml', 'WPML required for taxonomy translation', ['status' => 400]);
        }

        $saved = 0;
        $errors = [];

        // 3.1.42-hotfix3 (BUG-013): silent fail elimination. Every skip path now
        // emits a structured error with a reason field so failed entries surface
        // why they failed in the response. Previously the silent `continue` on
        // missing fields produced empty error reasons, making post_tag failures
        // impossible to debug.
        $skipped = 0;
        foreach ($translations as $item) {
            $term_id  = absint($item['term_id'] ?? 0);
            $language = sanitize_text_field($item['language'] ?? '');
            $name     = sanitize_text_field($item['name'] ?? '');
            $slug     = sanitize_title($item['slug'] ?? $name);

            if (!$term_id || empty($language) || empty($name)) {
                $reasons = array();
                if (!$term_id)         { $reasons[] = 'missing_term_id'; }
                if (empty($language))  { $reasons[] = 'missing_language'; }
                if (empty($name))      { $reasons[] = 'missing_name'; }
                $errors[] = array(
                    'term_id' => $term_id,
                    'language' => $language,
                    'reason' => 'skipped: ' . implode(',', $reasons),
                );
                $skipped++;
                continue;
            }

            $result = $this->save_wpml_taxonomy_translation($term_id, $taxonomy, $language, $name, $slug);
            if (is_wp_error($result)) {
                $errors[] = array(
                    'term_id'  => $term_id,
                    'language' => $language,
                    'reason'   => 'save_failed: ' . $result->get_error_message(),
                    'code'     => $result->get_error_code(),
                );
            } else {
                $saved++;
            }
        }

        LuwiPress_Logger::log(sprintf('Taxonomy translations saved: %d of %d for %s', $saved, count($translations), $taxonomy), 'info');

        return rest_ensure_response([
            'status' => 'saved',
            'saved'  => $saved,
            'errors' => $errors,
        ]);
    }

    /**
     * Fix Elementor-rendered translated blog posts.
     *
     * For translated posts (non-product), removes _elementor_edit_mode so
     * WordPress renders post_content instead of English _elementor_data.
     * Also updates the post title if a translated title is stored in meta.
     *
     * POST /translation/fix-elementor
     *   { "post_ids": "123,456" } or { "post_ids": "all", "language": "fr" }
     */
    public function fix_elementor_translated_posts( $request ) {
        $post_ids_param = sanitize_text_field( $request->get_param( 'post_ids' ) ?: 'all' );
        $language       = sanitize_text_field( $request->get_param( 'language' ) ?: '' );
        $fixed  = array();
        $errors = array();

        if ( 'all' === $post_ids_param ) {
            // Find all WPML translated posts that have _elementor_edit_mode
            if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
                return new WP_Error( 'no_wpml', 'WPML required', array( 'status' => 400 ) );
            }

            global $wpdb;
            $default_lang = self::get_default_language();

            $query = "SELECT DISTINCT p.ID, t.language_code
                      FROM {$wpdb->posts} p
                      JOIN {$wpdb->prefix}icl_translations t
                        ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
                      WHERE p.post_type = 'post'
                        AND p.post_status = 'publish'
                        AND t.language_code != %s
                        AND t.source_language_code IS NOT NULL";
            $args = array( $default_lang );

            if ( $language ) {
                $query .= " AND t.language_code = %s";
                $args[] = $language;
            }

            $rows = $wpdb->get_results( $wpdb->prepare( $query, $args ) );
            $post_ids = wp_list_pluck( $rows, 'ID' );
        } else {
            $post_ids = array_filter( array_map( 'absint', explode( ',', $post_ids_param ) ) );
        }

        // Switch WPML to all languages so get_post works for any language
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            do_action( 'wpml_switch_language', 'all' );
        }

        foreach ( $post_ids as $pid ) {
            // Use get_post() to get a proper WP_Post object
            $post = get_post( $pid );
            if ( $post ) {
                $source_id = null;
                $post_lang = null;

                // Find source post via WPML
                if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                    $post_type    = $post->post_type;
                    $element_type = 'post_' . $post_type;
                    $trid         = apply_filters( 'wpml_element_trid', null, $pid, $element_type );
                    if ( $trid ) {
                        global $wpdb;
                        $source_id = $wpdb->get_var( $wpdb->prepare(
                            "SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid = %d AND source_language_code IS NULL",
                            $trid
                        ) );
                        $post_lang = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $pid, 'element_type' => $element_type ) );
                    }
                }

                $info = array( 'post_id' => $pid, 'title' => $post->post_title );

                // Copy featured image from source post if missing
                if ( ! has_post_thumbnail( $pid ) && $source_id ) {
                    $thumb = get_post_thumbnail_id( $source_id );
                    if ( $thumb ) {
                        set_post_thumbnail( $pid, $thumb );
                        $info['thumbnail_copied'] = true;
                    }
                }

                // Fix broken slug â€” if numeric, regenerate from title
                if ( preg_match( '/^\d+$/', $post->post_name ) && ! empty( $post->post_title ) ) {
                    $new_slug = sanitize_title( $post->post_title );
                    wp_update_post( array( 'ID' => $pid, 'post_name' => $new_slug ) );
                    $info['slug_fixed'] = $new_slug;
                }

                $fixed[] = $info;
            }

            // Clear Elementor CSS cache
            delete_post_meta( $pid, '_elementor_css' );
            delete_post_meta( $pid, '_elementor_page_assets' );
        }

        LuwiPress_Logger::log( 'Elementor fix: removed edit mode from ' . count( $fixed ) . ' translated posts', 'info' );

        return rest_ensure_response( array(
            'status' => 'fixed',
            'count'  => count( $fixed ),
            'posts'  => $fixed,
            'errors' => $errors,
        ) );
    }

    /**
     * Get terms missing translation for given languages (WPML only).
     */
    private function get_missing_taxonomy_terms($taxonomy, $target_languages, $source_language, $limit) {
        global $wpdb;

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return [];
        }

        $element_type = 'tax_' . $taxonomy;
        $terms = [];

        // Get original terms registered in WPML
        $originals = $wpdb->get_results($wpdb->prepare(
            "SELECT t.trid, t.element_id
             FROM {$wpdb->prefix}icl_translations t
             WHERE t.element_type = %s
               AND t.language_code = %s
               AND t.source_language_code IS NULL",
            $element_type, $source_language
        ));

        // Check WPML-registered terms for missing translations
        foreach ($originals as $orig) {
            $term = get_term(absint($orig->element_id), $taxonomy);
            if (!$term || is_wp_error($term)) {
                continue;
            }

            $missing_langs = [];
            foreach ($target_languages as $lang) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE trid = %d AND language_code = %s AND element_type = %s",
                    $orig->trid, $lang, $element_type
                ));
                if (!$existing) {
                    $missing_langs[] = $lang;
                }
            }

            if (!empty($missing_langs)) {
                $terms[] = [
                    'term_id'           => $term->term_id,
                    'name'              => $term->name,
                    'slug'              => $term->slug,
                    'description'       => $term->description,
                    'missing_languages' => $missing_langs,
                ];
            }

            if (count($terms) >= $limit) {
                break;
            }
        }

        return $terms;
    }

    /**
     * Register a taxonomy term in WPML as an original-language term.
     * Uses direct SQL insert as fallback when WPML action hooks are not available in REST context.
     */
    private function register_term_in_wpml($term_id, $taxonomy, $language) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        global $wpdb;
        $element_type = 'tax_' . $taxonomy;

        // Check if already registered
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = %s",
            $term_id, $element_type
        ));

        if ($existing) {
            return;
        }

        // Try WPML action first
        do_action('wpml_set_element_language_details', [
            'element_id'           => $term_id,
            'element_type'         => $element_type,
            'trid'                 => false,
            'language_code'        => $language,
            'source_language_code' => null,
        ]);

        // Verify it worked
        $check = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = %s",
            $term_id, $element_type
        ));

        if ($check) {
            return;
        }

        // WPML action failed (common in REST context) â€” direct SQL insert
        // Get next trid
        $max_trid = (int) $wpdb->get_var("SELECT MAX(trid) FROM {$wpdb->prefix}icl_translations");
        $new_trid = $max_trid + 1;

        $wpdb->insert(
            $wpdb->prefix . 'icl_translations',
            [
                'element_type'         => $element_type,
                'element_id'           => $term_id,
                'trid'                 => $new_trid,
                'language_code'        => $language,
                'source_language_code' => null,
            ],
            ['%s', '%d', '%d', '%s', '%s']
        );
    }

    /**
     * Create a WPML taxonomy term translation.
     */
    private function save_wpml_taxonomy_translation($original_term_id, $taxonomy, $language, $name, $slug) {
        global $wpdb;

        // Verify original term exists in WordPress
        $original_term = get_term($original_term_id, $taxonomy);
        if (!$original_term || is_wp_error($original_term)) {
            return new WP_Error('term_not_found', 'Original term #' . $original_term_id . ' not found');
        }

        $element_type = 'tax_' . $taxonomy;
        $default_lang = self::get_default_language();

        // Get the trid of the original term
        $trid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s",
            $original_term_id, $element_type
        ));

        // If not registered in WPML, register it now and re-query
        if (!$trid) {
            $this->register_term_in_wpml($original_term_id, $taxonomy, $default_lang);
            $trid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $original_term_id, $element_type
            ));
        }

        if (!$trid) {
            return new WP_Error('no_trid', 'Could not register term #' . $original_term_id . ' in WPML');
        }

        // Check if translation already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT element_id FROM {$wpdb->prefix}icl_translations
             WHERE trid = %d AND language_code = %s AND element_type = %s",
            $trid, $language, $element_type
        ));

        if ($existing) {
            // Update existing term
            wp_update_term(absint($existing), $taxonomy, [
                'name' => $name,
                'slug' => $slug,
            ]);
            return true;
        }

        // Get original term's parent for hierarchy
        $original_term = get_term($original_term_id, $taxonomy);
        $parent = 0;
        if ($original_term && $original_term->parent > 0) {
            // Try to find translated parent
            $parent_trid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $original_term->parent, $element_type
            ));
            if ($parent_trid) {
                $translated_parent = $wpdb->get_var($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE trid = %d AND language_code = %s AND element_type = %s",
                    $parent_trid, $language, $element_type
                ));
                if ($translated_parent) {
                    $parent = absint($translated_parent);
                }
            }
        }

        // Create new term
        $new_term = wp_insert_term($name, $taxonomy, [
            'slug'   => $slug,
            'parent' => $parent,
        ]);

        if (is_wp_error($new_term)) {
            // If slug conflict, try with language suffix
            $new_term = wp_insert_term($name, $taxonomy, [
                'slug'   => $slug . '-' . $language,
                'parent' => $parent,
            ]);
            if (is_wp_error($new_term)) {
                return $new_term;
            }
        }

        $new_term_id = $new_term['term_id'];

        // Link to WPML translation group â€” try action first, SQL fallback
        do_action('wpml_set_element_language_details', [
            'element_id'           => $new_term_id,
            'element_type'         => $element_type,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $default_lang,
        ]);

        // Verify link was created
        $linked = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = %s",
            $new_term_id, $element_type
        ));

        if (!$linked) {
            // SQL fallback for REST context
            $wpdb->insert(
                $wpdb->prefix . 'icl_translations',
                [
                    'element_type'         => $element_type,
                    'element_id'           => $new_term_id,
                    'trid'                 => $trid,
                    'language_code'        => $language,
                    'source_language_code' => $default_lang,
                ],
                ['%s', '%d', '%d', '%s', '%s']
            );
        }

        return true;
    }

    /**
     * AJAX: Clean orphan WPML translation records.
     *
     * Removes icl_translations rows where:
     * - Taxonomy terms: trid has no matching original (source_language_code IS NULL) row
     * - Posts: element_id does not exist in wp_posts
     * - Terms: element_id does not exist in wp_term_taxonomy
     *
     * Also deletes the actual orphan WP posts/terms if they have no original.
     */
    public function ajax_clean_orphan_translations() {
        check_ajax_referer( 'luwipress_clean_orphans', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        $dry_run = ! empty( $_POST['dry_run'] ) || empty( $_POST['confirmed'] );

        global $wpdb;
        $icl = $wpdb->prefix . 'icl_translations';
        $terms_removed = 0;
        $posts_removed = 0;

        // â”€â”€ 1. Orphan taxonomy translations: trid has no original â”€â”€
        $orphan_terms = $wpdb->get_results(
            "SELECT t.translation_id, t.element_id, t.element_type, t.trid, t.language_code 
             FROM {$icl} t 
             LEFT JOIN {$icl} o ON t.trid = o.trid 
               AND t.element_type = o.element_type 
               AND o.source_language_code IS NULL 
             WHERE t.element_type LIKE 'tax_%'
               AND t.source_language_code IS NOT NULL
               AND o.trid IS NULL"
        );

        foreach ( $orphan_terms as $row ) {
            if ( ! $dry_run ) {
                $taxonomy = str_replace( 'tax_', '', $row->element_type );
                if ( term_exists( (int) $row->element_id, $taxonomy ) ) {
                    wp_delete_term( (int) $row->element_id, $taxonomy );
                }
                $wpdb->delete( $icl, array( 'translation_id' => $row->translation_id ), array( '%d' ) );
            }
            $terms_removed++;
        }

        // â”€â”€ 2. Orphan post translations: element_id not in wp_posts â”€â”€
        $orphan_posts = $wpdb->get_results(
            "SELECT t.translation_id, t.element_id, t.element_type
             FROM {$icl} t 
             LEFT JOIN {$wpdb->posts} p ON t.element_id = p.ID 
             WHERE t.element_type LIKE 'post_%'
               AND p.ID IS NULL"
        );

        foreach ( $orphan_posts as $row ) {
            if ( ! $dry_run ) {
                $wpdb->delete( $icl, array( 'translation_id' => $row->translation_id ), array( '%d' ) );
            }
            $posts_removed++;
        }

        // â”€â”€ 3. Orphan term translations: element_id not in wp_term_taxonomy â”€â”€
        $orphan_term_records = $wpdb->get_results(
            "SELECT t.translation_id, t.element_id
             FROM {$icl} t 
             LEFT JOIN {$wpdb->term_taxonomy} tt ON t.element_id = tt.term_id 
             WHERE t.element_type LIKE 'tax_%'
               AND tt.term_id IS NULL"
        );

        foreach ( $orphan_term_records as $row ) {
            if ( ! $dry_run ) {
                $wpdb->delete( $icl, array( 'translation_id' => $row->translation_id ), array( '%d' ) );
            }
            $terms_removed++;
        }

        $total = $terms_removed + $posts_removed;

        if ( $dry_run ) {
            wp_send_json_success( array(
                'dry_run' => true,
                'terms'   => $terms_removed,
                'posts'   => $posts_removed,
                'total'   => $total,
                'message' => sprintf( 'Found %d orphans (%d terms, %d posts). Click again to clean.', $total, $terms_removed, $posts_removed ),
            ) );
            return;
        }

        LuwiPress_Logger::log(
            sprintf( 'Orphan cleanup: %d terms, %d posts removed from icl_translations', $terms_removed, $posts_removed ),
            $total > 0 ? 'info' : 'debug',
            array( 'terms_removed' => $terms_removed, 'posts_removed' => $posts_removed )
        );

        wp_send_json_success( array(
            'terms_removed' => $terms_removed,
            'posts_removed' => $posts_removed,
            'removed'       => $total,
            'total'         => $total,
            'message'       => sprintf( 'Cleaned %d orphan record(s) (%d terms, %d posts).', $total, $terms_removed, $posts_removed ),
        ) );
    }

    // AJAX: Get missing items for a post type + language

    public function ajax_get_missing_items() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Force no-cache on this AJAX response. LiteSpeed (and some hosting WAFs) cache
        // admin-ajax responses by URL+nonce when no explicit no-store is set, which is
        // the root cause of "coverage shifts but UI keeps showing the old missing-list"
        // mismatches. nocache_headers() emits Cache-Control + Pragma + Expires; the LS
        // header is the explicit edge-cache bypass.
        if ( ! headers_sent() ) {
            nocache_headers();
            header( 'X-LiteSpeed-Cache-Control: no-cache' );
        }

        $lang      = sanitize_text_field( $_POST['language'] ?? '' );
        $post_type = sanitize_text_field( $_POST['post_type'] ?? 'product' );
        $limit     = absint( $_POST['limit'] ?? 500 );

        // WPML admin-context language switcher can scope queries to the current admin
        // language (e.g. user is viewing in EN, switcher narrows source SQL to EN-only
        // when admin asks for "missing translations"). Force "all" so source SQL stays
        // language-neutral and matches the public REST behaviour exactly.
        if ( has_action( 'wpml_switch_language' ) ) {
            do_action( 'wpml_switch_language', 'all' );
        }

        $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/missing-all' );
        $request->set_param( 'target_languages', $lang );
        $request->set_param( 'post_type', $post_type );
        $request->set_param( 'limit', min( $limit, 500 ) );

        $response = $this->get_missing_translations_all( $request );
        $data     = $response->get_data();

        $items = array();
        foreach ( ( $data['items'] ?? $data['products'] ?? array() ) as $item ) {
            $items[] = array(
                'id'    => $item['post_id'] ?? $item['product_id'],
                'title' => $item['name'],
            );
        }

        // Diagnostic log for the recurring "X missing in DB but unreachable" mismatch:
        // when coverage and fetcher disagree, this surfaces the per-call evidence so we
        // can decide if it's WPML language scope, post_status drift, or a fetcher edge case.
        LuwiPress_Logger::log(
            sprintf( 'get_missing_items: post_type=%s lang=%s -> %d items', $post_type, $lang, count( $items ) ),
            'debug',
            array(
                'returned_ids' => wp_list_pluck( $items, 'id' ),
                'limit'        => $limit,
            )
        );

        wp_send_json_success( array(
            'items' => $items,
            'total' => count( $items ),
        ) );
    }

    // â”€â”€â”€ AJAX: Translate a single post â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function ajax_translate_single() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $lang    = sanitize_text_field( $_POST['language'] ?? '' );

        if ( ! $post_id || ! $lang ) {
            wp_send_json_error( 'Missing post_id or language' );
        }

        // Verify post exists
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post #' . $post_id . ' not found' );
        }

        // CRITICAL guard: refuse to translate a post that is itself a translation. Without
        // this, a stale UI item or a cascade-duplicate row in icl_translations can pass a
        // non-EN post_id here, and we then "translate it to FR/IT/ES" -- producing more
        // duplicates. The legit source post must have language_code = default AND
        // source_language_code IS NULL.
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            global $wpdb;
            $default_lang = self::get_default_language();
            $element_type = 'post_' . $post->post_type;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT language_code, source_language_code, trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $post_id, $element_type
            ) );
            if ( $row && $row->language_code !== $default_lang ) {
                wp_send_json_error( sprintf(
                    'Post #%d is registered as %s, not %s -- refusing to use it as a translation source.',
                    $post_id, $row->language_code, $default_lang
                ) );
            }
            if ( $row && $row->source_language_code !== null ) {
                wp_send_json_error( sprintf(
                    'Post #%d is itself a translation (source_language_code=%s) -- refusing to retranslate.',
                    $post_id, $row->source_language_code
                ) );
            }
            // Also block: if this trid has another EN-source row that's older, this one
            // is a cascade duplicate and must not produce more translations.
            if ( $row ) {
                $older_sibling = $wpdb->get_var( $wpdb->prepare(
                    "SELECT t.element_id FROM {$wpdb->prefix}icl_translations t
                     JOIN {$wpdb->posts} p ON t.element_id = p.ID
                     WHERE t.trid = %d AND t.element_type = %s
                       AND t.language_code = %s AND t.source_language_code IS NULL
                       AND t.element_id != %d
                     ORDER BY p.post_date ASC LIMIT 1",
                    $row->trid, $element_type, $default_lang, $post_id
                ) );
                if ( $older_sibling ) {
                    wp_send_json_error( sprintf(
                        'Post #%d shares trid %d with older EN source #%d -- cascade duplicate, refusing to translate. Run Fix Orphans.',
                        $post_id, $row->trid, $older_sibling
                    ) );
                }
            }
        }

        // For Elementor pages: translate INLINE (synchronously) so the Translation
        // Manager's per-item AJAX loop drives real, visible progress instead of
        // handing the work to wp-cron. wp-cron is traffic-dependent and routinely
        // leaves jobs "queued" indefinitely, which reads as a stuck queue and makes
        // operators distrust / avoid Translations. The chunked translator (3.12.2)
        // plus hard-split long-HTML (3.12.3) keep every AI call short, so a single
        // page completes within the AJAX request. On any failure we fall back to the
        // background cron job so the work is never lost.
        if ( LuwiPress_Elementor::is_elementor_page( $post_id ) && class_exists( 'LuwiPress_Elementor' ) ) {
            @set_time_limit( 0 );
            if ( function_exists( 'ignore_user_abort' ) ) {
                @ignore_user_abort( true );
            }
            update_post_meta( $post_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'   => 'translating',
                'language' => $lang,
                'started'  => current_time( 'mysql' ),
            ) ) );

            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->translate_page( $post_id, $lang );

            if ( is_wp_error( $result ) ) {
                // Another run already holds the entry lock -- do NOT requeue (a
                // queued duplicate would re-run a full AI pass after the active
                // run finishes). Report in_progress and let the operator poll.
                if ( 'translation_in_progress' === $result->get_error_code() ) {
                    wp_send_json_success( array(
                        'post_id' => $post_id,
                        'title'   => $post->post_title,
                        'status'  => 'in_progress',
                    ) );
                }
                LuwiPress_Logger::log( 'AJAX Elementor inline translate failed, falling back to background: ' . $result->get_error_message(), 'warning', array(
                    'post_id' => $post_id, 'lang' => $lang, 'code' => $result->get_error_code(),
                ) );
                wp_schedule_single_event( time(), 'luwipress_elementor_translate_single', array( $post_id, $lang ) );
                spawn_cron();
                wp_send_json_success( array(
                    'post_id' => $post_id,
                    'title'   => $post->post_title,
                    'status'  => 'queued',
                ) );
                return;
            }

            update_post_meta( $post_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'   => 'completed',
                'language' => $lang,
                'finished' => current_time( 'mysql' ),
            ) ) );
            wp_send_json_success( array(
                'post_id' => $post_id,
                'title'   => $post->post_title,
                'status'  => 'completed',
            ) );
        }

        $request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/request' );
        $request->set_param( 'post_id', $post_id );
        $request->set_param( 'target_languages', array( $lang ) );

        $result = $this->request_translation( $request );

        if ( is_wp_error( $result ) ) {
            LuwiPress_Logger::log( 'AJAX translate_single failed: ' . $result->get_error_message(), 'error', array(
                'post_id' => $post_id, 'lang' => $lang, 'code' => $result->get_error_code(),
            ) );
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
                'post_id' => $post_id,
            ) );
        }

        $result_data = is_object( $result ) && method_exists( $result, 'get_data' ) ? $result->get_data() : $result;

        wp_send_json_success( array(
            'post_id' => $post_id,
            'title'   => $post->post_title,
            'status'  => $result_data['status'] ?? 'completed',
        ) );
    }

    // â”€â”€â”€ AJAX: Translate taxonomy batch â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function ajax_translate_taxonomy_batch() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $taxonomy  = sanitize_text_field( $_POST['taxonomy'] ?? '' );
        $languages = sanitize_text_field( $_POST['languages'] ?? '' );

        if ( ! $taxonomy || ! $languages ) {
            wp_send_json_error( 'Missing taxonomy or languages' );
        }

        $request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/taxonomy' );
        $request->set_param( 'taxonomy', $taxonomy );
        $request->set_param( 'target_languages', $languages );
        $request->set_param( 'limit', 200 );

        $result      = $this->request_taxonomy_translation( $request );
        $result_data = is_object( $result ) && method_exists( $result, 'get_data' ) ? $result->get_data() : $result;

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'status'      => $result_data['status'] ?? 'completed',
            'terms_sent'  => $result_data['terms_sent'] ?? 0,
            'saved'       => $result_data['saved'] ?? 0,
            'errors'      => $result_data['errors'] ?? array(),
            'sample_keys' => $result_data['sample_keys'] ?? array(),
            // Async path: when batch is large, request_taxonomy_translation queues it and
            // returns job_id + total_units so UI can poll progress instead of waiting.
            'job_id'      => $result_data['job_id'] ?? null,
            'total_units' => $result_data['total_units'] ?? 0,
            'total_terms' => $result_data['total_terms'] ?? 0,
        ) );
    }

    // AJAX: Poll translation progress for background jobs

    public function ajax_translation_progress() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Force no-cache (LiteSpeed admin-ajax cache class of bug -- already documented
        // for ajax_get_missing_items, same fix here for symmetry).
        if ( ! headers_sent() ) {
            nocache_headers();
            header( 'X-LiteSpeed-Cache-Control: no-cache' );
        }

        // Each progress poll is also a cron heartbeat. The pre-existing JS-side
        // wp-cron.php fetch with mode:no-cors is unreliable on hosts where LiteSpeed
        // intercepts that path; the server-side LuwiPress_Job_Queue::nudge_cron does
        // spawn_cron() + a real loopback POST that those hosts honour.
        if ( class_exists( 'LuwiPress_Job_Queue' ) ) {
            LuwiPress_Job_Queue::nudge_cron();
        } elseif ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }

        $post_ids = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
        if ( empty( $post_ids ) ) {
            wp_send_json_error( 'Missing post_ids' );
        }

        $results = array();
        foreach ( $post_ids as $pid ) {
            $raw = get_post_meta( $pid, '_luwipress_translation_status', true );
            if ( $raw ) {
                $data = json_decode( $raw, true );
                $data['post_id'] = $pid;
                $data['title']   = esc_html( get_the_title( $pid ) );
                $results[]       = $data;
            }
        }

        wp_send_json_success( $results );
    }

    // â”€â”€â”€ AJAX: Get missing taxonomy terms â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function ajax_get_missing_terms() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $taxonomy  = sanitize_text_field( $_POST['taxonomy'] ?? '' );
        $languages = sanitize_text_field( $_POST['languages'] ?? '' );
        if ( ! $taxonomy || ! $languages ) {
            wp_send_json_error( 'Missing taxonomy or languages' );
        }

        $target_langs    = array_map( 'trim', explode( ',', $languages ) );
        $source_language = self::get_default_language();
        $terms           = $this->get_missing_taxonomy_terms( $taxonomy, $target_langs, $source_language, 500 );

        // Flatten: one item per term+language pair for per-item progress
        $items = array();
        foreach ( $terms as $term ) {
            foreach ( $term['missing_languages'] as $lang ) {
                $items[] = array(
                    'term_id' => $term['term_id'],
                    'name'    => $term['name'],
                    'lang'    => $lang,
                );
            }
        }

        wp_send_json_success( array( 'items' => $items, 'total' => count( $items ) ) );
    }

    // â”€â”€â”€ AJAX: Translate a single taxonomy term â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function ajax_translate_single_term() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $term_id  = absint( $_POST['term_id'] ?? 0 );
        $taxonomy = sanitize_text_field( $_POST['taxonomy'] ?? '' );
        $language = sanitize_text_field( $_POST['language'] ?? '' );
        if ( ! $term_id || ! $taxonomy || ! $language ) {
            wp_send_json_error( 'Missing term_id, taxonomy, or language' );
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            wp_send_json_error( 'Term not found' );
        }

        $source_language = self::get_default_language();
        $lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese', 'ko' => 'Korean' );
        $source_name = $lang_names[ $source_language ] ?? ucfirst( $source_language );
        $target_name = $lang_names[ $language ] ?? ucfirst( $language );

        // Translate term name via AI
        $prompt = sprintf(
            'Translate the following %s taxonomy term name from %s to %s. Return ONLY the translated term name, nothing else. Term: "%s"',
            $taxonomy, $source_name, $target_name, $term->name
        );
        $messages  = LuwiPress_AI_Engine::build_messages( $prompt );
        $ai_result = LuwiPress_AI_Engine::dispatch( 'taxonomy-translation', $messages, array( 'max_tokens' => 256 ) );

        if ( is_wp_error( $ai_result ) ) {
            wp_send_json_error( $ai_result->get_error_message() );
        }

        // dispatch() returns array { content, input_tokens, ... }, not a bare string.
        $ai_text = (string) ( $ai_result['content'] ?? '' );
        $translated_name = sanitize_text_field( trim( $ai_text, ' "\'.' ) );
        if ( empty( $translated_name ) ) {
            wp_send_json_error( 'AI returned empty translation' );
        }

        $translated_slug = sanitize_title( $translated_name );

        // Save via WPML
        $result = $this->save_wpml_taxonomy_translation( $term_id, $taxonomy, $language, $translated_name, $translated_slug );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'term_id'   => $term_id,
            'name'      => esc_html( $translated_name ),
            'language'  => $language,
        ) );
    }

    // â”€â”€â”€ AJAX: Re-translate broken translations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function ajax_retranslate_broken() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        global $wpdb;
        $default_lang = self::get_default_language();

        // Find translated posts/pages with empty title OR numeric slug
        // SAFE: only post, page, product â€” never nav_menu_item or other types
        $safe_types = apply_filters( 'luwipress_translatable_post_types', array( 'post', 'page', 'product', 'lwp_vendor' ) );
        $type_ph    = implode( ',', array_fill( 0, count( $safe_types ), '%s' ) );
        $sql        = sprintf(
            "SELECT p.ID, p.post_title, p.post_name, p.post_type, t.trid, t.language_code
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
             WHERE t.source_language_code IS NOT NULL
               AND t.language_code != %%s
               AND p.post_type IN (%s)
               AND p.post_status IN ('publish','draft','private')
               AND (p.post_title = '' OR p.post_name = '' OR p.post_name REGEXP '^[0-9]+$')
             LIMIT 100",
            $type_ph
        );
        $broken = $wpdb->get_results(
            $wpdb->prepare( $sql, array_merge( $safe_types, array( $default_lang ) ) )
        );

        if ( empty( $broken ) ) {
            wp_send_json_success( array( 'fixed' => 0, 'message' => 'No broken translations found.' ) );
        }

        // FIX broken posts in-place: find source, queue re-translation via cron
        $fixed = 0;
        $queued = 0;
        foreach ( $broken as $row ) {
            // Find source post for this translation
            $source_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND source_language_code IS NULL",
                $row->trid
            ) );

            if ( ! $source_id ) {
                continue;
            }

            $source = get_post( absint( $source_id ) );
            if ( ! $source ) {
                continue;
            }

            // Fix 1: If title empty, copy source title as placeholder
            if ( empty( $row->post_title ) ) {
                wp_update_post( array( 'ID' => $row->ID, 'post_title' => $source->post_title ) );
            }

            // Fix 2: If slug is numeric OR empty, generate from current title
            if ( LuwiPress_Elementor::slug_needs_repair( $row->post_name ) ) {
                $title_for_slug = ! empty( $row->post_title ) ? $row->post_title : $source->post_title;
                wp_update_post( array( 'ID' => $row->ID, 'post_name' => sanitize_title( $title_for_slug ) . '-' . $row->language_code ) );
            }

            $fixed++;

            // Queue Elementor re-translation via cron (non-destructive â€” overwrites _elementor_data)
            if ( class_exists( 'LuwiPress_Elementor' ) && LuwiPress_Elementor::is_elementor_page( $source_id ) ) {
                // 90s spacing (not 1s) -- wp-cron dequeues events before running them;
                // batched heavy units in one spawn are lost when PHP dies mid-batch.
                wp_schedule_single_event( time() + ( $queued * 90 ), 'luwipress_elementor_translate_single', array( absint( $source_id ), $row->language_code ) );
                $queued++;
            }
        }

        if ( $queued > 0 ) {
            spawn_cron();
        }

        LuwiPress_Logger::log( sprintf( 'Re-translate broken: %d fixed, %d queued for re-translation', $fixed, $queued ), 'info' );

        wp_send_json_success( array(
            'fixed'   => $fixed,
            'queued'  => $queued,
            'message' => sprintf( '%d broken posts fixed. %d queued for Elementor re-translation via background jobs.', $fixed, $queued ),
        ) );
    }

    // â”€â”€â”€ AJAX: Sync WPML menus from default language â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function ajax_sync_wpml_menus() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $result = $this->sync_wpml_menus();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( array(
            'message' => $result['message'],
            'synced'  => $result['synced'],
            'created' => $result['created'] ?? 0,
        ) );
    }

    /**
     * Sync WPML menu STRUCTURE from the default language to every translated
     * menu. Shared by the admin AJAX button and the menu_sync_wpml MCP tool.
     *
     * @return array|WP_Error { synced:int, message:string } or WP_Error when WPML inactive.
     */
    public function sync_wpml_menus() {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return new WP_Error( 'wpml_inactive', 'WPML not active' );
        }

        $default_lang = self::get_default_language();
        $target_langs = apply_filters( 'wpml_active_languages', array() );

        // Get all menus in the default language
        $menus = wp_get_nav_menus();
        $synced  = 0; // menus whose items were (re)synced from source
        $created = 0; // translated menu terms created from scratch

        foreach ( $menus as $menu ) {
            // Check if this menu belongs to default language
            $menu_lang = apply_filters( 'wpml_element_language_code', null, array(
                'element_id'   => $menu->term_id,
                'element_type' => 'tax_nav_menu',
            ) );
            if ( $menu_lang && $menu_lang !== $default_lang ) {
                continue;
            }

            $menu_items = wp_get_nav_menu_items( $menu->term_id );
            if ( empty( $menu_items ) ) {
                continue;
            }
            $source_count = count( $menu_items );

            // Ensure the source menu is registered with WPML as a SOURCE element
            // so it owns a translation group (trid). On sites where menus were
            // never opened in WPML's Menu Sync screen, the source menu has no
            // trid yet and every translated lookup below returns the source id —
            // which is exactly why the old sync was a no-op. Register it first.
            $menu_trid = apply_filters( 'wpml_element_trid', null, $menu->term_id, 'tax_nav_menu' );
            if ( ! $menu_trid ) {
                do_action( 'wpml_set_element_language_details', array(
                    'element_id'           => (int) $menu->term_id,
                    'element_type'         => 'tax_nav_menu',
                    'trid'                 => false,
                    'language_code'        => $default_lang,
                    'source_language_code' => null,
                ) );
                $menu_trid = apply_filters( 'wpml_element_trid', null, $menu->term_id, 'tax_nav_menu' );
                if ( ! $menu_trid ) {
                    LuwiPress_Logger::log( sprintf(
                        'Menu sync: could not establish a WPML translation group for "%s" (#%d) — is "Navigation Menus" translatable in WPML settings?',
                        $menu->name, $menu->term_id
                    ), 'warning' );
                    continue;
                }
            }

            // For each target language: create the translated menu if missing,
            // then sync its items from the source.
            foreach ( $target_langs as $lang_code => $lang_info ) {
                if ( $lang_code === $default_lang ) {
                    continue;
                }

                $translated_menu_id = apply_filters( 'wpml_object_id', $menu->term_id, 'nav_menu', false, $lang_code );
                $has_translation    = ( $translated_menu_id && (int) $translated_menu_id !== (int) $menu->term_id );

                if ( ! $has_translation ) {
                    // CREATE the translated menu term and pair it to the source
                    // trid. This is the step WPML's "WP Menus Sync" screen does
                    // and the old code skipped — without it there is no menu for
                    // the theme (or label translator) to fill, so the site falls
                    // back to the default language on every translated page.
                    $new_name = $menu->name . ' (' . strtoupper( $lang_code ) . ')';
                    $new_menu_id = wp_create_nav_menu( $new_name );
                    if ( is_wp_error( $new_menu_id ) ) {
                        // Name clash from a prior partial run — try to reuse it.
                        $existing = get_term_by( 'name', $new_name, 'nav_menu' );
                        if ( $existing ) {
                            $new_menu_id = (int) $existing->term_id;
                        } else {
                            LuwiPress_Logger::log( sprintf(
                                'Menu sync: failed to create %s menu for "%s": %s',
                                $lang_code, $menu->name, $new_menu_id->get_error_message()
                            ), 'warning' );
                            continue;
                        }
                    }

                    // Pair the new menu term to the source's translation group.
                    do_action( 'wpml_set_element_language_details', array(
                        'element_id'           => (int) $new_menu_id,
                        'element_type'         => 'tax_nav_menu',
                        'trid'                 => (int) $menu_trid,
                        'language_code'        => $lang_code,
                        'source_language_code' => $default_lang,
                    ) );
                    $translated_menu_id = (int) $new_menu_id;
                    $created++;
                    LuwiPress_Logger::log( sprintf(
                        'Menu sync: created %s translation of "%s" (#%d -> #%d) and paired to trid %d',
                        $lang_code, $menu->name, $menu->term_id, $new_menu_id, $menu_trid
                    ), 'info' );
                }

                // Sync items from source -> translated menu when the translated
                // one is missing items (freshly created, or drifted).
                // Copy items from source -> translated menu when it is missing
                // items (freshly created, or drifted). We copy them OURSELVES:
                // wpml_sync_custom_element is a no-op for menu items on current
                // WPML versions (it pairs the term but never populates the items
                // — confirmed on flybydeniz: translated menus stayed at 0 items).
                $translated_items = wp_get_nav_menu_items( $translated_menu_id );
                $existing_count   = is_array( $translated_items ) ? count( $translated_items ) : 0;

                if ( $existing_count < $source_count ) {
                    $copied = $this->copy_menu_items_to_translation(
                        $menu->term_id, $menu_items, $translated_menu_id, $lang_code, $default_lang
                    );
                    if ( $copied > 0 ) {
                        $synced++;
                    }
                    LuwiPress_Logger::log( sprintf(
                        'Menu sync: %s (%s -> %s) — source: %d items, translated had: %d, copied: %d',
                        $menu->name, $default_lang, $lang_code, $source_count, $existing_count, $copied
                    ), 'info' );
                }
            }
        }

        if ( $created > 0 || $synced > 0 ) {
            return array(
                'synced'  => $synced,
                'created' => $created,
                'message' => sprintf(
                    '%d translated menu(s) created, %d menu(s) item-synced. Run "Translate Menus" next to fill the labels.',
                    $created, $synced
                ),
            );
        }
        return array(
            'synced'  => 0,
            'created' => 0,
            'message' => 'All menus are already in sync. If a translated menu still looks wrong, check that "Navigation Menus" is translatable in WPML → Settings.',
        );
    }

    /**
     * Copy a source menu's items into a translated menu, preserving hierarchy
     * and pairing each new nav_menu_item to its source via WPML so that
     * apply_filters( 'wpml_object_id', $source_item_id, 'nav_menu_item', false, $lang )
     * resolves later (translate_wpml_menus() depends on this).
     *
     * Idempotent: skips any source item that already has a translated
     * counterpart in $lang, so a half-finished prior run resumes cleanly and a
     * complete run is a no-op. Never deletes — re-pairing/labels survive.
     *
     * WPML orphan guard (feedback_menu_add_item_wpml_orphan.md, 2026-05-17):
     * wp_update_nav_menu_item() calls wp_set_object_terms($item,$menu,'nav_menu')
     * internally; WPML silently drops that relationship unless the CURRENT
     * language context matches the target menu's language. So we switch language
     * BEFORE creating each item, restore after, and verify attachment.
     *
     * @param int    $source_menu_id     Default-language menu term_id.
     * @param array  $source_items       Result of wp_get_nav_menu_items() (parents-before-children).
     * @param int    $translated_menu_id Target translated menu term_id (already paired to the source trid).
     * @param string $lang               Target language code.
     * @param string $default_lang       Default language code.
     * @return int Number of items newly created in the translated menu.
     */
    private function copy_menu_items_to_translation( $source_menu_id, $source_items, $translated_menu_id, $lang, $default_lang ) {
        if ( empty( $source_items ) || ! is_array( $source_items ) ) {
            return 0;
        }

        // source nav_menu_item db_id => new translated nav_menu_item id.
        $id_map  = array();
        $created = 0;

        // Remember/restore the global language context (orphan guard).
        $restore_lang = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : null;
        do_action( 'wpml_switch_language', $lang );

        $position = 0;
        foreach ( $source_items as $src ) {
            $position++;
            $src_id = (int) ( $src->db_id ?? $src->ID ?? 0 );
            if ( ! $src_id ) {
                continue;
            }

            // Idempotency: already translated in this language? Record for child
            // parent-mapping and skip creation.
            $existing = apply_filters( 'wpml_object_id', $src_id, 'nav_menu_item', false, $lang );
            if ( $existing && (int) $existing !== $src_id ) {
                $id_map[ $src_id ] = (int) $existing;
                continue;
            }

            // Parent remap: source parent db_id -> new translated item id.
            $src_parent    = isset( $src->menu_item_parent ) ? (int) $src->menu_item_parent : 0;
            $mapped_parent = ( $src_parent && isset( $id_map[ $src_parent ] ) ) ? (int) $id_map[ $src_parent ] : 0;

            $type   = isset( $src->type )      ? (string) $src->type   : 'custom';
            $object = isset( $src->object )    ? (string) $src->object : '';
            $obj_id = isset( $src->object_id ) ? (int) $src->object_id : 0;
            $title  = isset( $src->title )     ? (string) $src->title  : '';
            $url    = isset( $src->url )       ? (string) $src->url    : '';

            // Hyphenated menu-item-* keys are what wp_update_nav_menu_item()
            // expects (wp-includes/nav-menu.php). status=publish is required.
            $args = array(
                'menu-item-status'    => 'publish',
                'menu-item-parent-id' => $mapped_parent,
                'menu-item-position'  => $position,
                // Carry the source label so translate_wpml_menus() has a
                // post_title to overwrite (object items get the exact translated
                // title; custom links / overrides get AI-translated).
                'menu-item-title'     => $title,
            );

            if ( 'taxonomy' === $type && $obj_id && $object ) {
                $tr_obj = apply_filters( 'wpml_object_id', $obj_id, $object, false, $lang );
                $args['menu-item-type']      = 'taxonomy';
                $args['menu-item-object']    = $object;
                $args['menu-item-object-id'] = $tr_obj ? (int) $tr_obj : $obj_id;
            } elseif ( 'post_type' === $type && $obj_id && $object ) {
                $tr_obj = apply_filters( 'wpml_object_id', $obj_id, $object, false, $lang );
                $args['menu-item-type']      = 'post_type';
                $args['menu-item-object']    = $object;
                $args['menu-item-object-id'] = $tr_obj ? (int) $tr_obj : $obj_id;
            } else {
                // Custom link: copy title + url as-is (URL translation is a
                // separate concern; label translation happens later).
                $args['menu-item-type']   = 'custom';
                $args['menu-item-object'] = 'custom';
                $args['menu-item-url']    = $url;
            }

            $new_id = wp_update_nav_menu_item( (int) $translated_menu_id, 0, $args );
            if ( is_wp_error( $new_id ) || ! $new_id ) {
                LuwiPress_Logger::log( sprintf(
                    'Menu item copy: failed to create %s item for source #%d ("%s"): %s',
                    $lang, $src_id, $title,
                    is_wp_error( $new_id ) ? $new_id->get_error_message() : 'no id returned'
                ), 'warning' );
                continue;
            }
            $new_id = (int) $new_id;
            $id_map[ $src_id ] = $new_id;
            $created++;

            // WPML pairing: tie the new item to the source item's trid so
            // wpml_object_id resolves later. element_type = post_nav_menu_item.
            $src_trid = apply_filters( 'wpml_element_trid', null, $src_id, 'post_nav_menu_item' );
            if ( ! $src_trid ) {
                do_action( 'wpml_set_element_language_details', array(
                    'element_id'           => $src_id,
                    'element_type'         => 'post_nav_menu_item',
                    'trid'                 => false,
                    'language_code'        => $default_lang,
                    'source_language_code' => null,
                ) );
                $src_trid = apply_filters( 'wpml_element_trid', null, $src_id, 'post_nav_menu_item' );
            }
            if ( $src_trid ) {
                do_action( 'wpml_set_element_language_details', array(
                    'element_id'           => $new_id,
                    'element_type'         => 'post_nav_menu_item',
                    'trid'                 => (int) $src_trid,
                    'language_code'        => $lang,
                    'source_language_code' => $default_lang,
                ) );
            } else {
                LuwiPress_Logger::log( sprintf(
                    'Menu item copy: could not establish trid for source item #%d — %s item #%d created but unpaired (label pass will skip it).',
                    $src_id, $lang, $new_id
                ), 'warning' );
            }
        }

        // Restore language context.
        if ( null !== $restore_lang ) {
            do_action( 'wpml_switch_language', $restore_lang );
        }

        // Verify attachment (orphan guard): API success is necessary but not
        // sufficient on WPML sites — count items actually attached.
        if ( $created > 0 ) {
            $attached       = wp_get_nav_menu_items( (int) $translated_menu_id );
            $attached_count = is_array( $attached ) ? count( $attached ) : 0;
            if ( $attached_count < count( $id_map ) ) {
                LuwiPress_Logger::log( sprintf(
                    'Menu item copy WARNING: %s menu #%d shows %d attached but %d expected — possible WPML orphan (language-context mismatch).',
                    $lang, $translated_menu_id, $attached_count, count( $id_map )
                ), 'warning' );
            } else {
                LuwiPress_Logger::log( sprintf(
                    'Menu item copy: %s menu #%d — created %d, %d total attached.',
                    $lang, $translated_menu_id, $created, $attached_count
                ), 'info' );
            }
        }

        return $created;
    }

    // â”€â”€â”€ AJAX: AI-translate WPML menu item LABELS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    //
    // Sync Menus (above) only copies menu STRUCTURE to translated menus — it
    // never translates the navigation labels, so custom-link items (and any
    // item with a custom label override) stay in the source language on FR/IT/
    // ES menus. This walks every default-language menu's items, finds each
    // translated counterpart (WPML pairing), and sets a correct label:
    //   • post/term items with a plain (non-overridden) label → the EXACT
    //     translated object title (no AI — stays consistent with the term/post
    //     translation);
    //   • custom-link items, label overrides, or items whose object has no
    //     translation → AI-translated (batched per menu+language).
    // Fail-safe: a failed AI batch leaves those labels untouched (never blanks
    // a menu). Run "Sync Menus" first so the translated items exist.

    public function ajax_translate_wpml_menus() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $result = $this->translate_wpml_menus();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    /**
     * AI-translate WPML menu item labels for every translated menu. Shared by
     * the admin AJAX button and the menu_translate_wpml MCP tool. See the long
     * note above ajax_translate_wpml_menus' original location for the
     * object-title-vs-AI label resolution rules.
     *
     * @return array|WP_Error { updated:int, needs_sync:int, message:string } or WP_Error when WPML inactive.
     */
    public function translate_wpml_menus() {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return new WP_Error( 'wpml_inactive', 'WPML not active' );
        }

        $default_lang = self::get_default_language();
        $target_langs = apply_filters( 'wpml_active_languages', array() );
        $lang_names   = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese', 'ko' => 'Korean' );
        $source_name  = $lang_names[ $default_lang ] ?? ucfirst( $default_lang );

        $menus      = wp_get_nav_menus();
        $updated    = 0;
        $ai_batches = 0;
        $needs_sync = 0;
        $checked    = 0;

        foreach ( $menus as $menu ) {
            $menu_lang = apply_filters( 'wpml_element_language_code', null, array(
                'element_id'   => $menu->term_id,
                'element_type' => 'tax_nav_menu',
            ) );
            if ( $menu_lang !== $default_lang ) {
                continue;
            }
            $source_items = wp_get_nav_menu_items( $menu->term_id );
            if ( empty( $source_items ) ) {
                continue;
            }

            foreach ( $target_langs as $lang_code => $info ) {
                if ( $lang_code === $default_lang ) {
                    continue;
                }
                $target_name        = $lang_names[ $lang_code ] ?? ucfirst( $lang_code );
                $translated_menu_id = apply_filters( 'wpml_object_id', $menu->term_id, 'nav_menu', false, $lang_code );
                if ( ! $translated_menu_id || (int) $translated_menu_id === (int) $menu->term_id ) {
                    $needs_sync++; // whole translated menu missing — Sync Menus first.
                    continue;
                }

                $assign      = array(); // titem_id => label (exact translated-object titles, no AI)
                $ai_titems   = array(); // parallel arrays for the AI batch
                $ai_strings  = array();

                foreach ( $source_items as $sitem ) {
                    $titem_id = apply_filters( 'wpml_object_id', $sitem->db_id, 'nav_menu_item', false, $lang_code );
                    if ( ! $titem_id || (int) $titem_id === (int) $sitem->db_id ) {
                        $needs_sync++; // this item has no translated counterpart yet.
                        continue;
                    }
                    $checked++;

                    $type      = (string) get_post_meta( $sitem->db_id, '_menu_item_type', true );
                    $src_label = trim( (string) $sitem->title );
                    $object    = (string) $sitem->object;
                    $obj_id    = (int) get_post_meta( $sitem->db_id, '_menu_item_object_id', true );

                    $src_obj_title = '';
                    $tr_obj_title  = '';
                    if ( 'taxonomy' === $type && $obj_id ) {
                        $st = get_term( $obj_id, $object );
                        if ( $st && ! is_wp_error( $st ) ) {
                            $src_obj_title = (string) $st->name;
                        }
                        $tobj = apply_filters( 'wpml_object_id', $obj_id, $object, false, $lang_code );
                        if ( $tobj ) {
                            $tt = get_term( (int) $tobj, $object );
                            if ( $tt && ! is_wp_error( $tt ) ) {
                                $tr_obj_title = (string) $tt->name;
                            }
                        }
                    } elseif ( 'post_type' === $type && $obj_id ) {
                        $src_obj_title = (string) get_the_title( $obj_id );
                        $tobj = apply_filters( 'wpml_object_id', $obj_id, $object, false, $lang_code );
                        if ( $tobj ) {
                            $tr_obj_title = (string) get_the_title( (int) $tobj );
                        }
                    }

                    $is_override = ( 'custom' === $type )
                        || ( '' !== $src_obj_title && $src_label !== trim( $src_obj_title ) )
                        || ( '' === $src_obj_title && 'custom' !== $type );

                    if ( ! $is_override && '' !== $tr_obj_title ) {
                        // Plain post/term item with a translated object → use the exact
                        // translated title (consistent with the object's translation).
                        $assign[ (int) $titem_id ] = $tr_obj_title;
                    } elseif ( '' !== $src_label ) {
                        // Custom link, label override, or untranslated object → AI.
                        $ai_titems[]  = (int) $titem_id;
                        $ai_strings[] = $src_label;
                    }
                }

                if ( ! empty( $ai_strings ) ) {
                    $ai_batches++;
                    $translated = $this->ai_translate_label_batch( $ai_strings, $source_name, $target_name );
                    foreach ( $ai_titems as $i => $titem_id ) {
                        $t = isset( $translated[ $i ] ) ? $translated[ $i ] : null;
                        if ( $t ) {
                            $assign[ $titem_id ] = $t;
                        }
                    }
                }

                foreach ( $assign as $titem_id => $label ) {
                    $cur = (string) get_post_field( 'post_title', $titem_id );
                    if ( trim( $cur ) !== trim( (string) $label ) ) {
                        wp_update_post( array( 'ID' => (int) $titem_id, 'post_title' => (string) $label ) );
                        $updated++;
                    }
                }

                // ── Custom-link URL translation ───────────────────────────
                // Custom-link items store an absolute URL that WPML never
                // rewrites, so on /fr/ they still point at the default-language
                // page (e.g. "/flights/" or "/product-category/tours/"). Resolve
                // each translated custom item's URL to the current language:
                // a URL that maps to a post/term uses the translated object's
                // permalink; a plain internal path gets the language prefix.
                // taxonomy/post_type items derive their URL from the (already
                // translated) object, so they are skipped here.
                foreach ( $source_items as $sitem ) {
                    if ( 'custom' !== (string) get_post_meta( $sitem->db_id, '_menu_item_type', true ) ) {
                        continue;
                    }
                    $titem_id = apply_filters( 'wpml_object_id', $sitem->db_id, 'nav_menu_item', false, $lang_code );
                    if ( ! $titem_id || (int) $titem_id === (int) $sitem->db_id ) {
                        continue;
                    }
                    $src_url = (string) get_post_meta( $sitem->db_id, '_menu_item_url', true );
                    if ( '' === $src_url ) {
                        continue;
                    }
                    $new_url = $this->translate_menu_item_url( $src_url, $lang_code, $default_lang );
                    if ( $new_url && $new_url !== $src_url ) {
                        $cur_url = (string) get_post_meta( (int) $titem_id, '_menu_item_url', true );
                        if ( $cur_url !== $new_url ) {
                            update_post_meta( (int) $titem_id, '_menu_item_url', esc_url_raw( $new_url ) );
                            $updated++;
                        }
                    }
                }
            }
        }

        LuwiPress_Logger::log( sprintf(
            'Menu label translation: %d label(s) updated across %d item(s), %d AI batch(es)%s',
            $updated, $checked, $ai_batches, $needs_sync ? "; {$needs_sync} item(s)/menu(s) need structural sync first" : ''
        ), 'info' );

        if ( 0 === $updated && 0 === $needs_sync ) {
            $msg = 'All menu labels are already translated.';
        } else {
            $msg = sprintf( '%d menu label(s) translated.', $updated );
            if ( $needs_sync ) {
                $msg .= ' Some items have no translated counterpart yet — run "Sync Menus" first, then re-run.';
            }
        }
        return array( 'message' => $msg, 'updated' => $updated, 'needs_sync' => $needs_sync );
    }

    /**
     * Translate a custom-link menu URL to the current language.
     *
     * Custom-link items store an absolute URL that WPML never auto-rewrites.
     * Two cases:
     *   1. The URL resolves to a post/page/product/term → use the TRANSLATED
     *      object's permalink (correct slug + language prefix, e.g.
     *      /product-category/tours/ → /fr/product-category/visites/).
     *   2. A plain internal path with no resolvable object → prefix with the
     *      language code via WPML's converter (e.g. /flights/ → /fr/flights/).
     * External URLs (different host) are returned unchanged.
     *
     * @param string $url          Source absolute URL.
     * @param string $lang         Target language code.
     * @param string $default_lang Default language code.
     * @return string Translated URL (or original when nothing to change).
     */
    private function translate_menu_item_url( $url, $lang, $default_lang ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return $url;
        }

        // Only touch internal URLs.
        $home = home_url();
        $home_host = wp_parse_url( $home, PHP_URL_HOST );
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );
        if ( $url_host && $home_host && strtolower( $url_host ) !== strtolower( $home_host ) ) {
            return $url; // external link — leave alone.
        }

        // SAFETY RULE: only rewrite when a REAL translation of the target
        // exists. Blindly prefixing /<lang>/ to an untranslated page produces
        // a 404 (WPML has no page there). When there is no translated target
        // we return the source URL unchanged — it 301s to the default language,
        // which is a graceful fallback, not a broken link. (Regression fix:
        // 3.14.3's eager prefixing 404'd /fr/online-application/ etc.)

        // Case 1: URL maps to a post/page/product. Use the translated permalink
        // ONLY when a distinct translation exists.
        $post_id = url_to_postid( $url );
        if ( $post_id ) {
            $tr_post = apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), false, $lang );
            if ( $tr_post && (int) $tr_post !== (int) $post_id ) {
                $perma = get_permalink( (int) $tr_post );
                if ( $perma ) {
                    return $perma;
                }
            }
            return $url; // no real translation — leave as-is (301s to default).
        }

        // Case 2: URL maps to a term archive (e.g. /product-category/tours/).
        // Resolve the term from the path and, if it has a real translation,
        // use the translated term link (correct prefix AND translated slug).
        $term = $this->resolve_term_from_url( $url );
        if ( $term ) {
            $tr_term = apply_filters( 'wpml_object_id', $term->term_id, $term->taxonomy, false, $lang );
            if ( $tr_term && (int) $tr_term !== (int) $term->term_id ) {
                $tlink = get_term_link( (int) $tr_term, $term->taxonomy );
                if ( ! is_wp_error( $tlink ) ) {
                    return $tlink;
                }
            }
            return $url; // no translated term — leave as-is.
        }

        // Unresolvable internal path with no known translated target: do NOT
        // prefix (would 404). Leave the source URL — graceful 301 to default.
        return $url;
    }

    /**
     * Best-effort resolve a WordPress term (category, product_cat, tag…) from a
     * front-end archive URL. Returns the WP_Term or null. Used by
     * translate_menu_item_url() to avoid prefixing untranslated archives.
     *
     * @param string $url Archive URL.
     * @return \WP_Term|null
     */
    private function resolve_term_from_url( $url ) {
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        if ( '' === $path ) {
            return null;
        }
        $segments = explode( '/', $path );
        $slug     = end( $segments ); // last segment is the term slug.
        if ( '' === $slug ) {
            return null;
        }
        // Probe the taxonomies most likely to back a menu archive link.
        $taxonomies = array( 'product_cat', 'category', 'product_tag', 'post_tag' );
        foreach ( $taxonomies as $tax ) {
            if ( ! taxonomy_exists( $tax ) ) {
                continue;
            }
            // get_term_by() returns WP_Term|false|null (never WP_Error).
            $term = get_term_by( 'slug', $slug, $tax );
            if ( $term instanceof WP_Term ) {
                return $term;
            }
        }
        return null;
    }

    /**
     * AI-translate a list of short navigation labels in ONE call. Returns an
     * array the same length as $labels (translated strings; null for any that
     * could not be parsed). A failed batch returns all-null so the caller
     * leaves the original labels untouched — never blanks a menu.
     *
     * @param string[] $labels
     * @param string   $source_name
     * @param string   $target_name
     * @return array<int,string|null>
     */
    private function ai_translate_label_batch( $labels, $source_name, $target_name ) {
        $labels = array_values( $labels );
        $count  = count( $labels );
        if ( 0 === $count ) {
            return array();
        }
        $numbered = '';
        foreach ( $labels as $i => $l ) {
            $numbered .= ( $i + 1 ) . '. ' . $l . "\n";
        }
        $prompt = sprintf(
            "Translate these website navigation menu labels from %s to %s. Keep each translation short and natural for a navigation menu (no trailing punctuation, no quotes). Return ONLY a JSON array of strings in the SAME ORDER and with EXACTLY %d items, nothing else.\n\nLabels:\n%s",
            $source_name, $target_name, $count, $numbered
        );
        $messages = LuwiPress_AI_Engine::build_messages( array( 'user' => $prompt ) );
        $res      = LuwiPress_AI_Engine::dispatch( 'translation-pipeline', $messages, array( 'max_tokens' => 1024, 'timeout' => 60 ) );
        if ( is_wp_error( $res ) ) {
            return array_fill( 0, $count, null );
        }
        $text   = (string) ( $res['content'] ?? '' );
        $parsed = LuwiPress_AI_Engine::extract_json( $text );
        if ( ! is_array( $parsed ) || count( $parsed ) !== $count ) {
            return array_fill( 0, $count, null );
        }
        $out = array();
        foreach ( array_values( $parsed ) as $s ) {
            $out[] = is_string( $s ) ? sanitize_text_field( trim( $s, " \"'." ) ) : null;
        }
        return $out;
    }

    // â”€â”€â”€ AJAX: Stop active cron translations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function ajax_stop_translations() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_ids = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
        if ( empty( $post_ids ) ) {
            wp_send_json_error( 'No post_ids' );
        }

        $stopped = 0;
        foreach ( $post_ids as $pid ) {
            $raw = get_post_meta( $pid, '_luwipress_translation_status', true );
            if ( ! $raw ) {
                continue;
            }
            $st = json_decode( $raw, true );
            if ( $st && in_array( $st['status'] ?? '', array( 'queued', 'translating' ), true ) ) {
                // Clear the status â€” cron job will still run but won't find queued status
                delete_post_meta( $pid, '_luwipress_translation_status' );

                // Unschedule the cron event if still pending
                $lang = $st['language'] ?? '';
                if ( $lang ) {
                    wp_clear_scheduled_hook( 'luwipress_elementor_translate_single', array( $pid, $lang ) );
                }
                $stopped++;
            }
        }

        LuwiPress_Logger::log( sprintf( 'Translation stopped: %d jobs cancelled', $stopped ), 'info' );

        wp_send_json_success( array(
            'stopped' => $stopped,
            'message' => sprintf( '%d translation(s) stopped. You can resume with Translate All.', $stopped ),
        ) );
    }

    // â”€â”€â”€ REST: Fix excerpts â€” extract from Elementor widget text â”€â”€â”€â”€â”€â”€â”€â”€

    public function rest_fix_excerpts( $request ) {
        global $wpdb;

        $posts = $wpdb->get_results(
            "SELECT p.ID, p.post_type
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_elementor_data'
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post', 'page')
               AND (p.post_excerpt = '' OR p.post_excerpt IS NULL)
               AND pm.meta_value != ''
             LIMIT 200"
        );

        $fixed = 0;
        $fixed_ids = array();
        foreach ( $posts as $row ) {
            $raw = get_post_meta( $row->ID, '_elementor_data', true );
            if ( empty( $raw ) ) continue;

            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) continue;

            $excerpt = '';
            $this->walk_for_excerpt( $data, $excerpt );

            if ( ! empty( $excerpt ) ) {
                wp_update_post( array( 'ID' => $row->ID, 'post_excerpt' => $excerpt ) );
                $fixed++;
                $fixed_ids[] = $row->ID;
            }
        }

        LuwiPress_Logger::log( sprintf( 'Fix excerpts (REST): %d posts updated', $fixed ), 'info' );
        return rest_ensure_response( array(
            'fixed'     => $fixed,
            'fixed_ids' => $fixed_ids,
            'message'   => sprintf( '%d excerpts extracted from Elementor content.', $fixed ),
        ) );
    }

    // â”€â”€â”€ AJAX: Fix excerpts â€” extract from Elementor widget text â”€â”€â”€â”€â”€â”€â”€â”€

    public function ajax_fix_excerpts() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;

        // Find published posts/pages with empty excerpt that have _elementor_data
        $posts = $wpdb->get_results(
            "SELECT p.ID, p.post_type
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_elementor_data'
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post', 'page')
               AND (p.post_excerpt = '' OR p.post_excerpt IS NULL)
               AND pm.meta_value != ''
             LIMIT 200"
        );

        $fixed = 0;
        foreach ( $posts as $row ) {
            $raw = get_post_meta( $row->ID, '_elementor_data', true );
            if ( empty( $raw ) ) {
                continue;
            }

            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) {
                continue;
            }

            // Walk elements to find first text content
            $excerpt = '';
            $this->walk_for_excerpt( $data, $excerpt );

            if ( ! empty( $excerpt ) ) {
                wp_update_post( array(
                    'ID'           => $row->ID,
                    'post_excerpt' => $excerpt,
                ) );
                $fixed++;
            }
        }

        LuwiPress_Logger::log( sprintf( 'Fix excerpts: %d posts updated', $fixed ), 'info' );
        wp_send_json_success( array(
            'fixed'   => $fixed,
            'message' => sprintf( '%d excerpts extracted from Elementor content.', $fixed ),
        ) );
    }

    private function walk_for_excerpt( $elements, &$excerpt ) {
        if ( ! empty( $excerpt ) ) {
            return;
        }
        foreach ( $elements as $el ) {
            $settings = $el['settings'] ?? array();
            // Check text-editor widget
            foreach ( array( 'editor', 'ekit_heading_extra_title', 'description_text' ) as $field ) {
                if ( ! empty( $settings[ $field ] ) && strlen( strip_tags( $settings[ $field ] ) ) > 50 ) {
                    $excerpt = wp_trim_words( wp_strip_all_tags( $settings[ $field ] ), 30, '...' );
                    return;
                }
            }
            if ( ! empty( $el['elements'] ) ) {
                $this->walk_for_excerpt( $el['elements'], $excerpt );
            }
        }
    }

    // â”€â”€â”€ AJAX: Fix orphan translations â€” set correct source_language_code â”€

    public function ajax_fix_orphan_translations() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        global $wpdb;
        $default_lang = self::get_default_language();
        $fixed = 0;

        // â”€â”€ Type 1: Non-EN posts registered as originals (source_language_code IS NULL, lang != EN) â”€â”€
        $type1 = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.translation_id, t.element_id, t.language_code, t.trid, p.post_title
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.source_language_code IS NULL
               AND t.language_code != %s
               AND t.element_type LIKE 'post_%%'
               AND p.post_type IN ('post', 'page', 'product')
             LIMIT 200",
            $default_lang
        ) );
        foreach ( $type1 as $row ) {
            $has_source = $wpdb->get_var( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code = %s AND source_language_code IS NULL",
                $row->trid, $default_lang
            ) );
            if ( $has_source ) {
                $wpdb->update( $wpdb->prefix . 'icl_translations',
                    array( 'source_language_code' => $default_lang ),
                    array( 'translation_id' => $row->translation_id ) );
                $fixed++;
            }
        }

        // â”€â”€ Type 2: Posts registered as EN originals but their trid is a lonely group â”€â”€
        // These are FR/IT/ES translations that got their own trid with language_code=EN
        // Detect: EN original in a trid where NO other translations exist + title is non-English
        $type2 = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.translation_id, t.element_id, t.trid, p.post_title, p.post_name
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.language_code = %s
               AND t.source_language_code IS NULL
               AND t.element_type LIKE 'post_%%'
               AND p.post_type IN ('post', 'page', 'product')
               AND p.post_status = 'publish'
             LIMIT 500",
            $default_lang
        ) );
        foreach ( $type2 as $row ) {
            // Count how many translations this trid has
            $trid_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE trid = %d",
                $row->trid
            ) );
            // If this trid has only 1 entry (just this post, no translations)
            // AND the title looks non-English, it's likely an orphan
            if ( $trid_count <= 1 ) {
                $title = mb_strtolower( $row->post_title );
                $is_foreign = preg_match( '/[Ã Ã¢Ã¤Ã©Ã¨ÃªÃ«Ã¯Ã®Ã´Ã¹Ã»Ã¼Ã§Ã±Ã¡Ã­Ã³ÃºÃ¬Ã²Ã¼]/u', $title )
                    || preg_match( '/\b(della|degli|delle|nella|sono|tutto|ogni|comme|tout|sur le|acheter|pour|avec|dans|entre|une|thÃ©rap|dÃ©couvr|tambour|flÃ»te|tambiÃ©n|guÃ­a|cÃ³mo|instrumentos|terapia|poder|viaje|encanto|fascino|tecniche|strumenti|accordare|persiano|gioco)\b/u', $title );
                if ( $is_foreign ) {
                    // Delete this orphan WPML record â€” the post itself stays but won't appear in EN list
                    $wpdb->delete( $wpdb->prefix . 'icl_translations', array( 'translation_id' => $row->translation_id ) );
                    $fixed++;
                    LuwiPress_Logger::log( sprintf( 'Orphan fixed (Type 2): deleted WPML record for #%d "%s" (lonely EN trid=%d)',
                        $row->element_id, mb_substr( $row->post_title, 0, 40 ), $row->trid ), 'info' );
                }
            }
        }

        // Type 3: Sibling-rank orphan -- a trid where TWO rows have source_language_code IS NULL
        // (one is the legit EN source, the other is a non-EN post wrongly stamped as "I am also
        // an original"). This is exactly the "8 missing in DB but unreachable" case: Coverage SQL
        // skips these (source_language_code IS NULL filter on the count side), but the post does
        // exist in the trid, so the missing-fetcher sees "translation present" and won't list it.
        // Result: phantom missing, no way to translate it from the UI. Fix: stamp the non-EN row
        // with source_language_code = EN so it becomes a proper translation.
        $type3 = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.translation_id, t.element_id, t.language_code, t.trid
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.source_language_code IS NULL
               AND t.language_code != %s
               AND t.element_type LIKE 'post_%%'
               AND p.post_type IN ('post', 'page', 'product')
               AND p.post_status IN ('publish','draft','private')
               AND t.trid IN (
                   SELECT trid FROM (
                       SELECT trid FROM {$wpdb->prefix}icl_translations
                       WHERE source_language_code IS NULL
                         AND element_type LIKE 'post_%%'
                       GROUP BY trid
                       HAVING COUNT(*) > 1
                   ) AS multi_origin_trids
               )
             LIMIT 500",
            $default_lang
        ) );
        foreach ( $type3 as $row ) {
            // Verify there's a real EN source in the same trid before stamping.
            $has_en_source = $wpdb->get_var( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code = %s AND source_language_code IS NULL
                   AND translation_id != %d
                 LIMIT 1",
                $row->trid, $default_lang, $row->translation_id
            ) );
            if ( $has_en_source ) {
                $wpdb->update( $wpdb->prefix . 'icl_translations',
                    array( 'source_language_code' => $default_lang ),
                    array( 'translation_id' => $row->translation_id ) );
                $fixed++;
                LuwiPress_Logger::log( sprintf( 'Orphan fixed (Type 3): stamped #%d (%s) as translation of EN source in trid=%d',
                    $row->element_id, $row->language_code, $row->trid ), 'info' );
            }
        }

        // Type 4: Cascade-duplicate -- a trid with multiple EN-tagged rows where ALL of them
        // currently have source_language_code IS NULL (none are valid translations). The first/
        // oldest row is the legit EN source; every later row is the byproduct of a runaway
        // create_translation_post -> WPML hook race -> language_code overwritten -> appeared
        // again in missing-list -> got "translated" again loop. The actual non-EN content is
        // already in the post body (these were originally IT/FR/ES translations), so we just
        // delete the icl_translations row -- the post itself becomes a stand-alone untranslated
        // copy and the operator can decide manually whether to keep it.
        $cascade_groups = $wpdb->get_results( $wpdb->prepare(
            "SELECT trid, element_type, COUNT(*) AS cnt
             FROM {$wpdb->prefix}icl_translations
             WHERE source_language_code IS NULL
               AND language_code = %s
               AND element_type LIKE 'post_%%'
             GROUP BY trid, element_type
             HAVING COUNT(*) > 1",
            $default_lang
        ) );
        foreach ( $cascade_groups as $group ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT t.translation_id, t.element_id
                 FROM {$wpdb->prefix}icl_translations t
                 JOIN {$wpdb->posts} p ON t.element_id = p.ID
                 WHERE t.trid = %d
                   AND t.element_type = %s
                   AND t.language_code = %s
                   AND t.source_language_code IS NULL
                 ORDER BY p.post_date ASC, t.translation_id ASC",
                $group->trid, $group->element_type, $default_lang
            ) );
            if ( count( $rows ) <= 1 ) { continue; }
            // Keep the first (oldest) row as the legit EN source. Drop the WPML record
            // for every later row so they stop appearing in the missing-list.
            $kept = array_shift( $rows );
            foreach ( $rows as $extra ) {
                $wpdb->delete(
                    $wpdb->prefix . 'icl_translations',
                    array( 'translation_id' => $extra->translation_id ),
                    array( '%d' )
                );
                $fixed++;
                LuwiPress_Logger::log(
                    sprintf( 'Orphan fixed (Type 4 cascade): removed WPML record for #%d (kept #%d as legit EN source in trid=%d)',
                        $extra->element_id, $kept->element_id, $group->trid ),
                    'warning'
                );
            }
        }

        LuwiPress_Logger::log( sprintf( 'Fix orphan translations: %d fixed', $fixed ), 'info' );
        wp_send_json_success( array(
            'fixed'   => $fixed,
            'message' => sprintf( '%d orphan translations fixed (source_language_code set to %s).', $fixed, $default_lang ),
        ) );
    }

    // Async taxonomy translation -- delegates to LuwiPress_Job_Queue.
    // Worker fires per chunk: { lang: 'fr', terms: [...] }

    private function queue_taxonomy_translation_job( $taxonomy, $missing_terms, $target_languages, $source_language ) {
        $chunk_size  = 25;
        $term_chunks = array_chunk( $missing_terms, $chunk_size );
        $chunks = array();
        foreach ( $term_chunks as $chunk ) {
            foreach ( $target_languages as $lang ) {
                $chunks[] = array( 'lang' => $lang, 'terms' => array_values( $chunk ) );
            }
        }

        $job = LuwiPress_Job_Queue::enqueue( 'taxonomy_translation', array(
            'chunks' => $chunks,
            'meta'   => array(
                'taxonomy'        => $taxonomy,
                'source_language' => $source_language,
                'target_languages' => $target_languages,
                'total_terms'     => count( $missing_terms ),
            ),
        ) );

        if ( is_wp_error( $job ) ) {
            return $job;
        }

        return rest_ensure_response( array(
            'status'      => 'queued',
            'job_id'      => $job['job_id'],
            'taxonomy'    => $taxonomy,
            'total_terms' => count( $missing_terms ),
            'total_units' => $job['total_units'],
            'message'     => sprintf( 'Queued %d translations across %d chunks. Poll job status with job_id.', count( $missing_terms ) * count( $target_languages ), $job['total_units'] ),
        ) );
    }

    /**
     * Worker for one taxonomy-translation chunk. Called by LuwiPress_Job_Queue::cron_dispatch.
     * Returns: [ 'sent' => N, 'saved' => N, 'errors' => [...] ]
     */
    public function jq_taxonomy_translation_worker( $chunk_payload, $meta, $job_id ) {
        $lang     = $chunk_payload['lang']  ?? '';
        $terms    = $chunk_payload['terms'] ?? array();
        $taxonomy = $meta['taxonomy']        ?? '';
        $source_language = $meta['source_language'] ?? self::get_default_language();

        if ( ! $lang || ! $taxonomy || empty( $terms ) ) {
            return array( 'sent' => 0, 'saved' => 0, 'errors' => array( 'invalid chunk payload (lang/taxonomy/terms missing)' ) );
        }

        $lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese', 'ko' => 'Korean' );
        $source_name = $lang_names[ $source_language ] ?? ucfirst( $source_language );
        $target_name = $lang_names[ $lang ] ?? ucfirst( $lang );

        $terms_for_prompt = array();
        foreach ( $terms as $t ) {
            $terms_for_prompt[] = array(
                'term_id' => $t['term_id'] ?? 0,
                'name'    => $t['name']    ?? '',
                'slug'    => $t['slug']    ?? '',
            );
        }

        // Token budget scales with chunk size: ~80-100 tokens per term entry in the JSON response.
        $max_tokens = max( 1024, count( $terms ) * 100 );

        $prompt   = LuwiPress_Prompts::taxonomy_translation( $terms_for_prompt, $taxonomy, $source_name, $target_name );
        $messages = LuwiPress_AI_Engine::build_messages( $prompt );
        $ai_result = LuwiPress_AI_Engine::dispatch_json( 'translation-pipeline', $messages, array(
            'max_tokens' => $max_tokens,
        ) );

        $sent  = count( $terms );
        $saved = 0;
        $errors = array();

        if ( is_wp_error( $ai_result ) ) {
            $errors[] = sprintf( '%s ai err: %s', $lang, $ai_result->get_error_message() );
            return array( 'sent' => $sent, 'saved' => 0, 'errors' => $errors );
        }

        $translated = is_array( $ai_result ) && isset( $ai_result[0] ) ? $ai_result : ( $ai_result['translations'] ?? array() );
        foreach ( $translated as &$tr_item ) {
            $tr_item['language'] = $lang;
        }
        unset( $tr_item );

        if ( empty( $translated ) ) {
            return array( 'sent' => $sent, 'saved' => 0, 'errors' => array( $lang . ' ai returned no translations' ) );
        }

        $callback_request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/taxonomy-callback' );
        $callback_request->set_body_params( array(
            'taxonomy'     => $taxonomy,
            'translations' => $translated,
        ) );
        $callback_response = $this->handle_taxonomy_callback( $callback_request );

        if ( is_wp_error( $callback_response ) ) {
            $errors[] = 'callback wp_error: ' . $callback_response->get_error_message();
        } else {
            $callback_data = is_object( $callback_response ) && method_exists( $callback_response, 'get_data' ) ? $callback_response->get_data() : array();
            $saved         = absint( $callback_data['saved'] ?? 0 );
            $cb_errors     = (array) ( $callback_data['errors'] ?? array() );
            $errors        = array_merge( $errors, array_slice( $cb_errors, 0, 3 ) );
        }

        return array( 'sent' => $sent, 'saved' => $saved, 'errors' => $errors );
    }

    /**
     * Stop-word signatures used by the language-drift heuristic.
     * Each list is the ~50 most-frequent function words (articles, prepositions,
     * conjunctions, pronouns, common verbs) — words that are extremely improbable
     * to leak across languages. We deliberately exclude proper nouns, brand names,
     * and tech vocabulary that survives translation untouched.
     *
     * Word boundaries are matched as `\b<word>\b` (lowercase, unicode-aware) so
     * "the" doesn't false-match "thesaurus".
     */
    private static function get_stop_word_signatures() {
        return array(
            'en'    => array( 'the','and','for','with','that','this','from','have','are','was','were','will','would','could','should','about','their','there','which','what','when','where','your','our','its','into','than','then','also','just','some','any','all','more','most','other','these','those','being','been','only','very','such','through','between','because','while','after','before','during','over','under','same','each','many','much','use','used','using','like','still','well','make','made','can','may','must','not','but','out','off','one','two','three','first','second','last','new' ),
            'es'    => array( 'el','la','los','las','un','una','unos','unas','de','del','y','o','en','con','por','para','que','se','su','sus','le','les','lo','este','esta','estos','estas','ese','esa','eso','esos','esas','aquel','como','cuando','donde','muy','más','pero','sin','sobre','entre','también','solo','sólo','desde','hasta','hacia','porque','mientras','después','antes','durante','siempre','nunca','todo','todos','toda','todas','otro','otra','otros','otras','mismo','misma','tan','tanto','poco','mucho','algunos','algunas','no','sí','ya','aún','hay','ser','está','están','sido','siendo','fue','era','eran','tiene','tienen','tenía','puede','pueden','debe','deben','hace','hacen','dijo','dice','así' ),
            'it'    => array( 'il','lo','la','i','gli','le','un','uno','una','di','del','dello','della','dei','degli','delle','e','ed','o','ma','che','non','con','per','tra','fra','su','in','da','al','alla','ai','agli','alle','dal','dalla','dai','sul','sulla','sui','questo','questa','questi','queste','quello','quella','quelli','quelle','suo','sua','suoi','sue','loro','come','quando','dove','molto','più','anche','solo','ancora','ogni','tutto','tutti','tutta','tutte','altro','altri','altra','altre','stesso','stessa','così','sempre','mai','poi','già','dopo','prima','durante','perché','mentre','essere','è','sono','era','erano','stato','stata','stati','state','avere','ha','hanno','aveva','fare','sì','no' ),
            'fr'    => array( 'le','la','les','un','une','des','du','de','et','ou','que','qui','quoi','dont','où','dans','sur','sous','avec','sans','pour','par','vers','entre','chez','contre','depuis','pendant','avant','après','ce','cet','cette','ces','mon','ma','mes','ton','ta','tes','son','sa','ses','notre','votre','leur','leurs','plus','moins','très','aussi','aussi','encore','toujours','jamais','déjà','tout','tous','toute','toutes','autre','autres','même','mêmes','aux','car','mais','donc','alors','quand','comment','pourquoi','parce','si','non','oui','être','est','sont','était','étaient','été','avoir','ai','as','avons','avez','ont','avait','faire','fait','fais' ),
            'de'    => array( 'der','die','das','den','dem','des','ein','eine','einen','einem','einer','und','oder','aber','denn','sondern','dass','wenn','weil','obwohl','während','bevor','nachdem','seit','bis','von','vom','aus','mit','bei','nach','zu','zum','zur','für','um','ohne','gegen','durch','über','unter','vor','hinter','neben','zwischen','ist','sind','war','waren','sein','wird','werden','wurde','wurden','hat','haben','hatte','hatten','kann','können','muss','müssen','soll','sollen','will','wollen','mag','mögen','dies','diese','dieser','dieses','jene','jener','jenes','noch','schon','auch','nur','sehr','mehr','nicht','kein','keine','eigen','selbst' ),
            'tr'    => array( 've','veya','ile','için','ama','fakat','ancak','çünkü','eğer','ki','de','da','ya','bir','bu','şu','o','bunu','şunu','onu','bunlar','şunlar','onlar','her','bazı','tüm','birçok','az','çok','daha','en','gibi','kadar','sonra','önce','arasında','üzerinde','altında','yanında','içinde','dışında','olarak','olmak','olan','oldu','olur','olacak','var','yok','değil','mi','mı','mu','mü','ise','iken','nasıl','ne','neden','niye','niçin','hangi','kim','nerede','nereye','ne zaman','şimdi','sonra','önce','şöyle','böyle','öyle' ),
            'nl'    => array( 'de','het','een','en','of','maar','want','dat','die','dit','deze','dat','dat','wat','welk','welke','in','op','aan','met','voor','door','om','tot','van','uit','bij','naar','over','onder','tussen','tijdens','sinds','tot','zonder','tegen','is','zijn','was','waren','heeft','hebben','had','hadden','wordt','worden','werd','werden','kan','kunnen','moet','moeten','zal','zullen','wil','willen','niet','geen','wel','ook','nog','al','meer','minder','veel','weinig','altijd','nooit','soms','vaak','heel','erg','zeer','elke','elk','andere','ander','zelf','toch' ),
            'pt-pt' => array( 'o','a','os','as','um','uma','uns','umas','de','do','da','dos','das','e','ou','que','para','por','com','sem','em','no','na','nos','nas','este','esta','estes','estas','esse','essa','esses','essas','aquele','aquela','aqueles','aquelas','seu','sua','seus','suas','meu','minha','meus','minhas','teu','tua','teus','tuas','nosso','nossa','nossos','nossas','vosso','vossa','vossos','vossas','muito','mais','menos','também','já','ainda','sempre','nunca','assim','como','quando','onde','porque','mas','se','não','sim','é','são','foi','eram','sido','estar','está','estão','estava','ter','tem','têm','tinha','fazer','faz','fez' ),
            'ru'    => array( 'и','в','не','на','я','быть','он','с','что','а','по','это','она','этот','к','но','они','мы','как','из','у','который','то','за','свой','что','весь','год','ты','когда','вы','такой','же','уже','для','до','же','можно','от','очень','все','если','время','лет','есть','со','о','еще','один','чтобы','без','здесь','бы','без','между','через','под','над','при','этот','эта','эти' ),
            'ar'    => array( 'في','من','إلى','على','عن','مع','بين','أن','إن','لا','ما','هذا','هذه','ذلك','تلك','هؤلاء','أولئك','الذي','التي','الذين','اللاتي','كان','يكون','هو','هي','هم','نحن','أنت','أنتم','كل','بعض','أي','حيث','عند','بعد','قبل','أيضا','كذلك','لكن','أو','ثم','حتى','كي','لكي','إذا','لو','قد','لقد','لم','لن','ليس','ليست','هناك','هنا','بل','إلا','غير' ),
        );
    }

    /**
     * Score a body of text against an expected target language.
     *
     * Returns a float in [0..1] where 1.0 = pure target-language and 0.0 = pure
     * source-language (or unknown). Below ~0.45 the body has more source-language
     * stop words than target — treat as drifted.
     *
     * @param string $text         Body content (HTML stripped before scoring).
     * @param string $target_lang  Expected language code (es, it, fr, ...).
     * @param string $source_lang  Source/default language code (typically en).
     * @return array { float score, int target_hits, int source_hits, int word_count }
     */
    public static function score_text_language( $text, $target_lang, $source_lang = 'en' ) {
        $clean = wp_strip_all_tags( (string) $text );
        $clean = preg_replace( '/[\x{00A0}\s]+/u', ' ', $clean );
        $clean = function_exists( 'mb_strtolower' ) ? mb_strtolower( $clean, 'UTF-8' ) : strtolower( $clean );

        // Tokenise on non-letter boundaries (Unicode-aware).
        $tokens = preg_split( '/[^\p{L}\p{N}\-]+/u', $clean, -1, PREG_SPLIT_NO_EMPTY );
        $word_count = is_array( $tokens ) ? count( $tokens ) : 0;
        if ( $word_count === 0 ) {
            return array( 'score' => 1.0, 'target_hits' => 0, 'source_hits' => 0, 'word_count' => 0 );
        }

        $signatures = self::get_stop_word_signatures();
        $target_set = isset( $signatures[ $target_lang ] ) ? array_flip( $signatures[ $target_lang ] ) : array();
        $source_set = isset( $signatures[ $source_lang ] ) ? array_flip( $signatures[ $source_lang ] ) : array();

        $target_hits = 0;
        $source_hits = 0;
        foreach ( $tokens as $tok ) {
            if ( isset( $target_set[ $tok ] ) ) { $target_hits++; }
            if ( isset( $source_set[ $tok ] ) ) { $source_hits++; }
        }

        $denom = $target_hits + $source_hits;
        // No stop words from either side → can't score (likely lists / numbers / proper-noun heavy).
        // Returning 1.0 (clean) here biases toward false negatives, which is correct: don't queue
        // an AI rewrite based on insufficient evidence.
        $score = ( $denom > 0 ) ? ( $target_hits / $denom ) : 1.0;

        return array(
            'score'       => round( $score, 3 ),
            'target_hits' => $target_hits,
            'source_hits' => $source_hits,
            'word_count'  => $word_count,
        );
    }

    /**
     * GET /translation/language-drift — Translated posts whose body is still in
     * the source language. Walks every translation post for the requested types
     * and languages, scores each body, returns those below the threshold.
     */
    public function get_language_drift( $request ) {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return new WP_Error( 'no_wpml', 'WPML is required for language-drift detection.', array( 'status' => 400 ) );
        }
        global $wpdb;

        $post_type    = sanitize_text_field( $request->get_param( 'post_type' ) ?: 'post' );
        $limit        = min( max( 1, absint( $request->get_param( 'limit' ) ) ), 1000 );
        $threshold    = (float) $request->get_param( 'threshold' );
        if ( $threshold <= 0 || $threshold >= 1 ) { $threshold = 0.45; }
        $min_words    = max( 5, absint( $request->get_param( 'min_words' ) ) );
        $default_lang = self::get_default_language();
        $element_type = 'post_' . $post_type;

        $languages = $request->get_param( 'languages' );
        if ( is_string( $languages ) ) {
            $languages = array_map( 'trim', explode( ',', $languages ) );
        }
        $languages = array_values( array_filter( array_map( 'sanitize_text_field', (array) $languages ) ) );
        if ( empty( $languages ) ) {
            $detector  = LuwiPress_Plugin_Detector::get_instance();
            $detected  = $detector->detect_translation();
            $languages = array_values( array_diff( (array) ( $detected['active_languages'] ?? array() ), array( $default_lang ) ) );
        }
        if ( empty( $languages ) ) {
            return new WP_Error( 'no_languages', 'No target languages provided and none detected.', array( 'status' => 400 ) );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.element_id AS translation_id,
                    t.language_code,
                    t.trid,
                    src_t.element_id AS source_id,
                    src_p.post_title  AS source_title,
                    p.post_title      AS translation_title,
                    p.post_modified_gmt AS translation_modified
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p
               ON p.ID = t.element_id AND p.post_status = 'publish'
             JOIN {$wpdb->prefix}icl_translations src_t
               ON src_t.trid = t.trid
              AND src_t.element_type = t.element_type
              AND src_t.source_language_code IS NULL
             JOIN {$wpdb->posts} src_p
               ON src_p.ID = src_t.element_id AND src_p.post_status = 'publish'
             WHERE t.element_type = %s
               AND t.source_language_code IS NOT NULL
               AND t.language_code IN ('" . implode( "','", array_map( static function( $l ) { return (string) esc_sql( $l ); }, $languages ) ) . "')
             ORDER BY p.post_modified_gmt DESC
             LIMIT %d",
            $element_type, $limit * count( $languages )
        ) );

        $items     = array();
        $skipped   = array( 'low_signal' => 0, 'clean' => 0 );
        $by_lang   = array_fill_keys( $languages, 0 );

        foreach ( $rows as $row ) {
            $tid        = absint( $row->translation_id );
            $lang       = (string) $row->language_code;
            $tpost      = get_post( $tid );
            if ( ! $tpost ) { continue; }

            // Pull the actual body. Elementor pages need the rendered text from
            // _elementor_data, not post_content (which is often empty).
            $body = (string) $tpost->post_content;
            if ( class_exists( 'LuwiPress_Elementor' ) && LuwiPress_Elementor::is_elementor_page( $tid ) ) {
                $elementor_text = self::extract_elementor_text( $tid );
                if ( strlen( $elementor_text ) > strlen( $body ) ) {
                    $body = $elementor_text;
                }
            }

            $score = self::score_text_language( $body, $lang, $default_lang );

            if ( $score['word_count'] < $min_words ) {
                $skipped['low_signal']++;
                continue;
            }
            if ( $score['score'] >= $threshold ) {
                $skipped['clean']++;
                continue;
            }

            $by_lang[ $lang ] = ( $by_lang[ $lang ] ?? 0 ) + 1;
            $items[] = array(
                'source_id'        => absint( $row->source_id ),
                'source_title'     => (string) $row->source_title,
                'translation_id'   => $tid,
                'translation_title'=> (string) $row->translation_title,
                'language'         => $lang,
                'score'            => $score['score'],
                'target_hits'      => $score['target_hits'],
                'source_hits'      => $score['source_hits'],
                'word_count'       => $score['word_count'],
                'edit_url'         => admin_url( 'post.php?post=' . $tid . '&action=edit' ),
                'permalink'        => get_permalink( $tid ),
            );

            if ( count( $items ) >= $limit ) { break; }
        }

        return rest_ensure_response( array(
            'post_type'      => $post_type,
            'languages'      => $languages,
            'threshold'      => $threshold,
            'min_words'      => $min_words,
            'count'          => count( $items ),
            'count_per_lang' => $by_lang,
            'skipped'        => $skipped,
            'items'          => $items,
        ) );
    }

    /**
     * Extract human-readable text from an Elementor page's _elementor_data.
     * Walks the JSON tree and concatenates the editor / heading / text settings.
     * Used by the language-drift detector — post_content is often empty for
     * Elementor pages so the bare post_content body would always score 1.0.
     */
    public static function extract_elementor_text( $post_id ) {
        $data = get_post_meta( $post_id, '_elementor_data', true );
        if ( empty( $data ) ) { return ''; }
        if ( is_string( $data ) ) {
            $decoded = json_decode( $data, true );
            $data = is_array( $decoded ) ? $decoded : array();
        }
        if ( ! is_array( $data ) ) { return ''; }

        $bag = array();
        $walk = function ( $node ) use ( &$walk, &$bag ) {
            if ( ! is_array( $node ) ) { return; }
            if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
                foreach ( array( 'title', 'heading', 'text', 'editor', 'description', 'content', 'caption', 'tab_title', 'item_title', 'item_description' ) as $k ) {
                    if ( isset( $node['settings'][ $k ] ) && is_string( $node['settings'][ $k ] ) ) {
                        $bag[] = $node['settings'][ $k ];
                    }
                }
                if ( isset( $node['settings']['tabs'] ) && is_array( $node['settings']['tabs'] ) ) {
                    foreach ( $node['settings']['tabs'] as $tab ) {
                        if ( is_array( $tab ) ) {
                            foreach ( array( 'tab_title', 'tab_content', 'item_title', 'item_description' ) as $k ) {
                                if ( isset( $tab[ $k ] ) && is_string( $tab[ $k ] ) ) {
                                    $bag[] = $tab[ $k ];
                                }
                            }
                        }
                    }
                }
            }
            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
                foreach ( $node['elements'] as $child ) { $walk( $child ); }
            }
        };
        foreach ( $data as $top ) { $walk( $top ); }
        return implode( ' ', $bag );
    }

    /**
     * POST /translation/force-retranslate — Bypass /translation/missing-all gating.
     * Caller supplies an explicit post_ids whitelist (typically from /translation/language-drift)
     * plus the target languages to overwrite. Each (post, lang) tuple is dispatched
     * via request_translation, which already updates in-place via create_wpml_translation
     * when a translation already exists.
     *
     * The post_ids argument MUST be source-language post IDs. If the caller has
     * translation IDs (e.g. from language-drift's `translation_id` field), the
     * handler resolves them to their default-language source via WPML trid lookup.
     */
    public function force_retranslate( $request ) {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return new WP_Error( 'no_wpml', 'WPML is required.', array( 'status' => 400 ) );
        }

        $post_ids = $request->get_param( 'post_ids' );
        if ( is_string( $post_ids ) ) {
            $post_ids = array_map( 'trim', explode( ',', $post_ids ) );
        }
        $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );

        $languages = $request->get_param( 'languages' );
        if ( is_string( $languages ) ) {
            $languages = array_map( 'trim', explode( ',', $languages ) );
        }
        $languages = array_values( array_filter( array_map( 'sanitize_text_field', (array) $languages ) ) );

        if ( empty( $post_ids ) ) {
            return new WP_Error( 'missing_ids', 'post_ids is required (array or comma-separated).', array( 'status' => 400 ) );
        }
        if ( empty( $languages ) ) {
            return new WP_Error( 'missing_languages', 'languages is required (array or comma-separated).', array( 'status' => 400 ) );
        }

        $default_lang = self::get_default_language();
        $async        = (bool) $request->get_param( 'async' );

        // Resolve every passed ID to its DEFAULT-language source. If the caller passed
        // a translation_id (the broken ES copy), walk WPML's trid back to the EN source.
        $resolved = array();
        foreach ( $post_ids as $pid ) {
            $post = get_post( $pid );
            if ( ! $post ) { continue; }
            $lang_code = self::get_post_wpml_language( $pid );
            if ( $lang_code === $default_lang ) {
                $resolved[ $pid ] = $pid;
                continue;
            }
            // Translation post → resolve back to source via trid.
            $element_type = 'post_' . $post->post_type;
            $trid = apply_filters( 'wpml_element_trid', null, $pid, $element_type );
            if ( ! $trid ) { continue; }
            $translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
            if ( is_array( $translations ) && isset( $translations[ $default_lang ]->element_id ) ) {
                $resolved[ (int) $translations[ $default_lang ]->element_id ] = (int) $translations[ $default_lang ]->element_id;
            }
        }
        $resolved = array_values( $resolved );

        if ( empty( $resolved ) ) {
            return new WP_Error( 'no_resolvable_sources', 'None of the passed post_ids could be resolved to a default-language source.', array( 'status' => 400 ) );
        }

        $work_units = count( $resolved ) * count( $languages );
        $async_threshold = 5;
        $use_async = ( $work_units > $async_threshold ) && $async && function_exists( 'wp_schedule_single_event' );

        $dispatched = 0;
        $errors     = array();

        if ( $use_async ) {
            // Schedule per (post, lang) pair on wp_cron. The handler hooks into the
            // 'luwipress_force_retranslate_single' event registered in __construct.
            foreach ( $resolved as $sid ) {
                foreach ( $languages as $lang ) {
                    update_post_meta( $sid, '_luwipress_force_retranslate_' . $lang, current_time( 'mysql' ) );
                    // Surface the job in the Translation Manager progress banner --
                    // it only reads the _luwipress_translation_status JSON meta
                    // (written by the Elementor path); without this the page shows
                    // "no active translations" while a force-retranslate batch runs.
                    update_post_meta( $sid, '_luwipress_translation_status', wp_json_encode( array(
                        'status'   => 'queued',
                        'language' => $lang,
                        'queued'   => current_time( 'mysql' ),
                    ) ) );
                    // 90s spacing, NOT 1s: wp-cron dequeues an event BEFORE running it,
                    // so when several heavy translation units pile into one cron spawn
                    // and PHP is killed mid-batch, every already-dequeued unit is LOST
                    // silently (tapadum 2026-06-10: 8 of 18 units vanished). Spacing
                    // them out means each spawn picks up ~one unit in a fresh process.
                    wp_schedule_single_event( time() + ( $dispatched * 90 ), 'luwipress_force_retranslate_single', array( $sid, $lang ) );
                    $dispatched++;
                }
            }
            spawn_cron();
        } else {
            // Inline path for small batches. Clear the guard meta on every target
            // translation post first — otherwise the elementor pipeline short-circuits
            // and re-uses the broken English copy.
            foreach ( $resolved as $sid ) {
                self::clear_retranslate_guards( $sid, $languages );
                $sub = new WP_REST_Request( 'POST', '/luwipress/v1/translation/request' );
                $sub->set_param( 'post_id', $sid );
                $sub->set_param( 'target_languages', $languages );
                $result = $this->request_translation( $sub );
                if ( is_wp_error( $result ) ) {
                    $errors[] = '#' . $sid . ': ' . $result->get_error_message();
                } else {
                    $dispatched += count( $languages );
                }
            }
        }

        LuwiPress_Logger::log(
            sprintf( 'Force-retranslate dispatched: %d work units across %d source posts × %d languages (async=%s)',
                $dispatched, count( $resolved ), count( $languages ), $use_async ? 'yes' : 'no' ),
            'info',
            array( 'post_ids' => $resolved, 'languages' => $languages )
        );

        return rest_ensure_response( array(
            'status'       => $use_async ? 'queued' : 'completed',
            'mode'         => $use_async ? 'async' : 'inline',
            'sources'      => count( $resolved ),
            'languages'    => $languages,
            'work_units'   => $work_units,
            'dispatched'   => $dispatched,
            'errors'       => $errors,
        ) );
    }

    /**
     * Clear the "already-translated" guard meta on every target-language copy
     * of $source_id. The Elementor translation pipeline checks this meta and
     * short-circuits when it's set — leaving stale English content untouched.
     * Force-retranslate calls this BEFORE re-dispatching translation so the
     * pipeline actually does the work.
     *
     * @param int   $source_id  Default-language post ID.
     * @param array $languages  Target language codes whose translation copies should be cleared.
     */
    public static function clear_retranslate_guards( $source_id, array $languages ) {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) { return; }
        $post = get_post( $source_id );
        if ( ! $post ) { return; }
        $element_type = 'post_' . $post->post_type;
        $trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
        if ( ! $trid ) { return; }
        $translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
        if ( ! is_array( $translations ) ) { return; }
        foreach ( $languages as $lang ) {
            if ( isset( $translations[ $lang ]->element_id ) ) {
                $tid = (int) $translations[ $lang ]->element_id;
                delete_post_meta( $tid, '_luwipress_elementor_translated' );
                delete_post_meta( $tid, '_luwipress_elementor_chunked' );
                delete_post_meta( $tid, '_luwipress_translation_' . $lang . '_status' );
            }
        }
    }

    /**
     * Cron handler for force-retranslate. Fires per (post_id, lang) pair scheduled
     * by force_retranslate() when the work batch was big enough to push to wp_cron.
     */
    public function cron_force_retranslate_single( $source_id, $language ) {
        $source_id = absint( $source_id );
        $language  = sanitize_text_field( $language );
        if ( ! $source_id || ! $language ) { return; }

        self::clear_retranslate_guards( $source_id, array( $language ) );

        // Progress visibility: the Translation Manager banner reads this JSON meta.
        update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
            'status'   => 'translating',
            'language' => $language,
            'started'  => current_time( 'mysql' ),
        ) ) );

        $sub = new WP_REST_Request( 'POST', '/luwipress/v1/translation/request' );
        $sub->set_param( 'post_id', $source_id );
        $sub->set_param( 'target_languages', array( $language ) );
        $result = $this->request_translation( $sub );

        if ( is_wp_error( $result ) ) {
            LuwiPress_Logger::log(
                'Force-retranslate cron FAILED: #' . $source_id . ' (' . strtoupper( $language ) . ') — ' . $result->get_error_message(),
                'error',
                array( 'source_id' => $source_id, 'language' => $language )
            );
            update_post_meta( $source_id, '_luwipress_force_retranslate_' . $language . '_error', $result->get_error_message() );
            update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'   => 'failed',
                'language' => $language,
                'error'    => $result->get_error_message(),
                'finished' => current_time( 'mysql' ),
            ) ) );
            return;
        }

        delete_post_meta( $source_id, '_luwipress_force_retranslate_' . $language );
        delete_post_meta( $source_id, '_luwipress_force_retranslate_' . $language . '_error' );
        update_post_meta( $source_id, '_luwipress_force_retranslate_' . $language . '_completed', current_time( 'mysql' ) );
        update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
            'status'   => 'completed',
            'language' => $language,
            'finished' => current_time( 'mysql' ),
        ) ) );

        LuwiPress_Logger::log(
            'Force-retranslate cron OK: #' . $source_id . ' (' . strtoupper( $language ) . ')',
            'info',
            array( 'source_id' => $source_id, 'language' => $language )
        );
    }
}
