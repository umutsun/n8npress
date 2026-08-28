<?php
/**
 * LuwiPress Elementor Integration
 *
 * Read, translate, and modify Elementor page content and styles.
 * Parses _elementor_data JSON, provides widget-level operations,
 * and integrates with WPML for page translation.
 *
 * @package    LuwiPress
 * @subpackage Elementor
 * @since      2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LuwiPress_Elementor {

    /* ──────────────────────────── Singleton ──────────────────────────── */

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
        add_action( 'wp_head', array( $this, 'output_global_css' ), 99 );
        add_action( 'wp_head', array( $this, 'enqueue_google_fonts' ), 1 );

        // Background job: translate Elementor pages one at a time via wp_cron
        add_action( 'luwipress_elementor_translate_single', array( $this, 'cron_translate_single' ), 10, 2 );
    }

    /**
     * WP Cron handler: translate a single Elementor page in the background.
     *
     * @param int    $source_id       Source post ID.
     * @param string $target_language Target language code.
     */
    public function cron_translate_single( $source_id, $target_language ) {
        // Increase execution time for background processing
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        // Skip: previously identified as having no translatable text (shortcode-only,
        // pure-template page, etc.). BUG-014 fix — without this guard the cron keeps
        // re-queueing the same empty page on every sweep, wasting tokens and bloating
        // the snapshot list (`elementor-translation` workflow burned ~2.58M tokens/30d
        // in part because of this loop).
        if ( get_post_meta( $source_id, '_luwipress_no_translatable_text', true ) ) {
            // SELF-HEAL: this flag short-circuits genuinely empty (shortcode-only)
            // pages so the cron sweep stops re-queuing them. But an earlier transient
            // AI timeout (cURL error 28) or a buggy prior version could leave it on a
            // page that DOES have content -- which then gets skipped forever ("stuck
            // queue"). Re-verify cheaply (no AI call): if the page actually has
            // extractable text, clear the stale flag and proceed; else honour the skip.
            $recheck = $this->extract_translatable_text( $source_id );
            if ( ! is_wp_error( $recheck ) && ! empty( $recheck ) ) {
                delete_post_meta( $source_id, '_luwipress_no_translatable_text' );
                LuwiPress_Logger::log( sprintf(
                    'Cron: post #%d was flagged no_translatable_text but has %d translatable widget(s) -- clearing stale flag and proceeding (%s).',
                    $source_id, count( $recheck ), $target_language
                ), 'warning' );
            } else {
                LuwiPress_Logger::log( sprintf(
                    'Cron: post #%d confirmed no_translatable_text -- skipping %s translation cycle.',
                    $source_id, $target_language
                ), 'info' );
                return;
            }
        }

        // CRITICAL guard: refuse to translate a post that is itself a translation OR a
        // cascade duplicate. Without this, queued cron jobs from a previous bad state
        // keep producing duplicates even after the UI/REST guards are in place.
        if ( defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'LuwiPress_Translation' ) ) {
            global $wpdb;
            $src_post = get_post( $source_id );
            if ( ! $src_post ) {
                LuwiPress_Logger::log( 'Cron: source post #' . $source_id . ' not found, skipping', 'warning' );
                return;
            }
            $default_lang = LuwiPress_Translation::get_default_language();
            $element_type = 'post_' . $src_post->post_type;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT language_code, source_language_code, trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $source_id, $element_type
            ) );
            if ( $row && ( $row->language_code !== $default_lang || $row->source_language_code !== null ) ) {
                LuwiPress_Logger::log( sprintf(
                    'Cron: refusing to translate #%d -- it is itself a %s translation (source_language_code=%s). Skipping to prevent cascade duplicate.',
                    $source_id, $row->language_code, $row->source_language_code ?? 'NULL'
                ), 'warning' );
                update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
                    'status'   => 'skipped_not_source',
                    'language' => $target_language,
                    'finished' => current_time( 'mysql' ),
                ) ) );
                return;
            }
            if ( $row ) {
                $older_sibling = $wpdb->get_var( $wpdb->prepare(
                    "SELECT t.element_id FROM {$wpdb->prefix}icl_translations t
                     JOIN {$wpdb->posts} p ON t.element_id = p.ID
                     WHERE t.trid = %d AND t.element_type = %s
                       AND t.language_code = %s AND t.source_language_code IS NULL
                       AND t.element_id != %d
                     ORDER BY p.post_date ASC LIMIT 1",
                    $row->trid, $element_type, $default_lang, $source_id
                ) );
                if ( $older_sibling ) {
                    LuwiPress_Logger::log( sprintf(
                        'Cron: #%d is a cascade duplicate (older EN sibling #%d in trid %d). Skipping.',
                        $source_id, $older_sibling, $row->trid
                    ), 'warning' );
                    update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
                        'status'   => 'skipped_cascade_dup',
                        'language' => $target_language,
                        'finished' => current_time( 'mysql' ),
                    ) ) );
                    return;
                }
            }
        }

        // ── Attempt cap ────────────────────────────────────────────────────────
        // Without a ceiling a failing (post, language) pair keeps being retried by
        // whatever re-queues it, and every retry rewrote the live page. Give up
        // loudly after N tries and leave a terminal status behind so the operator
        // can see WHY the job stopped (tapadum, 2026-08-25).
        $attempt_key = self::translation_attempt_meta_key( $target_language );
        $attempts    = (int) get_post_meta( $source_id, $attempt_key, true );
        $max_attempts = (int) apply_filters(
            'luwipress_elementor_translation_max_attempts',
            self::MAX_TRANSLATION_ATTEMPTS,
            $source_id,
            $target_language
        );

        if ( $attempts >= $max_attempts ) {
            update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'   => 'failed_max_attempts',
                'language' => $target_language,
                'attempts' => $attempts,
                'error'    => sprintf( 'Gave up after %d attempts', $attempts ),
                'finished' => current_time( 'mysql' ),
            ) ) );
            LuwiPress_Logger::log( sprintf(
                'Cron: Elementor translation #%d → %s GIVEN UP after %d attempts. Clear %s to retry.',
                $source_id, $target_language, $attempts, $attempt_key
            ), 'error', array( 'post_id' => $source_id, 'language' => $target_language, 'attempts' => $attempts ) );
            return;
        }

        $attempts++;
        update_post_meta( $source_id, $attempt_key, $attempts );

        // Track translation status for progress polling
        update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
            'status'   => 'translating',
            'language' => $target_language,
            'attempt'  => $attempts,
            'started'  => current_time( 'mysql' ),
        ) ) );

        LuwiPress_Logger::log( sprintf(
            'Cron: translating Elementor page #%d → %s (attempt %d/%d)',
            $source_id, $target_language, $attempts, $max_attempts
        ), 'info' );

        try {
            $result = $this->translate_page( $source_id, $target_language );
        } catch ( \Throwable $e ) {
            $result = new \WP_Error( 'exception', $e->getMessage() );
        }

        if ( is_wp_error( $result ) && 'translation_in_progress' === $result->get_error_code() ) {
            // Another run already holds the entry lock -- this duplicate cron job
            // is exactly what the lock exists to absorb. Not a failure: do not
            // write a terminal status (the active run owns completion; the
            // 'translating' stamp above remains accurate while it runs), and do not
            // charge it an attempt -- no AI pass was made.
            update_post_meta( $source_id, $attempt_key, $attempts - 1 );
            LuwiPress_Logger::log( 'Cron: translation of #' . $source_id . ' → ' . $target_language . ' already in progress -- duplicate job skipped', 'info' );
            return;
        }

        if ( is_wp_error( $result ) ) {
            $exhausted = ( $attempts >= $max_attempts );
            update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'   => $exhausted ? 'failed_max_attempts' : 'failed',
                'language' => $target_language,
                'attempts' => $attempts,
                'code'     => $result->get_error_code(),
                'error'    => $result->get_error_message(),
                'finished' => current_time( 'mysql' ),
            ) ) );
            LuwiPress_Logger::log( sprintf(
                'Cron: Elementor translation FAILED #%d → %s (attempt %d/%d%s): %s',
                $source_id, $target_language, $attempts, $max_attempts,
                $exhausted ? ', giving up' : '', $result->get_error_message()
            ), 'error', array( 'post_id' => $source_id, 'language' => $target_language, 'attempts' => $attempts ) );
        } else {
            // Verify translated post has a proper title and slug
            $tid = $result['translated_id'] ?? 0;
            if ( $tid ) {
                $translated_post = get_post( $tid );
                if ( $translated_post && ( empty( $translated_post->post_title ) || self::slug_needs_repair( $translated_post->post_name ) ) ) {
                    $source = get_post( $source_id );
                    $fix = array( 'ID' => $tid );
                    if ( empty( $translated_post->post_title ) && $source ) {
                        $fix['post_title'] = $source->post_title;
                    }
                    if ( self::slug_needs_repair( $translated_post->post_name ) ) {
                        $slug_base = $translated_post->post_title;
                        if ( '' === trim( (string) $slug_base ) && $source ) {
                            $slug_base = $source->post_title;
                        }
                        $fix['post_name'] = sanitize_title( (string) $slug_base ) . '-' . $target_language;
                    }
                    wp_update_post( $fix );
                    LuwiPress_Logger::log( 'Cron: fixed missing title/slug on #' . $tid, 'warning' );
                    // BELT: wp_update_post fires WPML's save_post handler, which can
                    // re-stamp the translation from a stale per-request cache
                    // (orphan-page bug, 2026-06-10). Re-assert the registration.
                    if ( defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'LuwiPress_Translation' ) ) {
                        LuwiPress_Translation::get_instance()->ensure_translation_registration( $tid, $source_id, $target_language );
                    }
                }
            }

            // Success clears the attempt budget so a later legitimate re-run is not
            // blocked by an old failure streak.
            delete_post_meta( $source_id, $attempt_key );

            update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'        => 'completed',
                'language'      => $target_language,
                'translated_id' => $tid,
                'attempts'      => $attempts,
                'finished'      => current_time( 'mysql' ),
            ) ) );
            LuwiPress_Logger::log( 'Cron: Elementor translation completed #' . $source_id . ' → #' . ( $tid ?: '?' ) . ' (' . $target_language . ')', 'info' );
        }
    }

    /* ──────────────────────────── Constants ──────────────────────────── */

    /**
     * Widget types and their translatable text fields.
     */
    const TRANSLATABLE_WIDGETS = array(
        'heading'         => array( 'title' ),
        'text-editor'     => array( 'editor' ),
        'html'            => array( 'html' ), // raw-HTML widget — translated like 'editor'; script/style blocks are skipped in extract_widget_texts
        'button'          => array( 'text' ),
        'image-box'       => array( 'title_text', 'description_text' ),
        'icon-box'        => array( 'title_text', 'description_text' ),
        'testimonial'     => array( 'testimonial_content', 'testimonial_name', 'testimonial_job' ),
        'call-to-action'  => array( 'title', 'description', 'button_text' ),
        'price-table'     => array( 'heading', 'sub_heading' ),
        'tabs'            => array(),  // handled specially (items array)
        'accordion'       => array(),  // handled specially (items array)
        'toggle'          => array(),  // handled specially (items array)
        'alert'           => array( 'alert_title', 'alert_description' ),
        'counter'         => array( 'title', 'suffix', 'prefix' ),
        'progress'        => array( 'title', 'inner_text' ),
        'icon-list'       => array(),  // handled specially (items array)
        'animated-headline' => array( 'before_text', 'highlighted_text', 'after_text', 'rotating_text' ),
        // ElementsKit widgets
        'elementskit-heading' => array( 'ekit_heading_title', 'ekit_heading_sub_title', 'ekit_heading_extra_title', 'ekit_heading_description' ),
        // LuwiPress Gold theme widgets (3.4.3+)
        'lwp-section-head'   => array( 'eyebrow', 'heading', 'cta_label' ),
        'lwp-hero-split'     => array( 'eyebrow', 'kicker', 'heading', 'body', 'cta_label', 'quote', 'quote_name', 'quote_role', 'lead', 'cta1_label', 'cta2_label', 'pill_text', 'tag_eyebrow', 'tag_title', 'quote_initial', 'quote_text' ),
        'lwp-tour-hero'      => array( 'eyebrow', 'heading', 'sub', 'cta1_label', 'cta2_label', 'label_experience', 'label_date', 'label_guests' ),
        'lwp-story-split'    => array( 'card_eyebrow', 'card_title', 'card_sub', 'eyebrow', 'heading', 'lead', 'cta_label' ),
        'lwp-timeline'       => array( 'eyebrow', 'heading' ),
        'lwp-master-profile' => array( 'name', 'location', 'specialty', 'fallback_initial' ),
        'lwp-info-bar'       => array(),
        'lwp-category-grid'  => array( 'count_label' ),
        'lwp-master-grid'    => array( 'eyebrow', 'heading', 'view_all_label' ),
        'lwp-editorial-grid' => array( 'heading', 'eyebrow', 'all_label' ),
        'lwp-newsletter'     => array( 'eyebrow', 'heading', 'sub', 'button_text', 'success_message', 'lead', 'placeholder', 'btn_label', 'gdpr_text', 'success_msg', 'error_msg' ),
        'lwp-faq'            => array( 'eyebrow', 'heading' ),
        'lwp-cta-banner'     => array( 'eyebrow', 'heading', 'body', 'button_text', 'lead', 'cta1_label', 'cta2_label' ),
        'lwp-testimonials'   => array( 'eyebrow', 'heading' ),
        'lwp-youtube-channel'=> array( 'eyebrow', 'heading', 'sub', 'cta_label', 'subscribe_label' ),
        'lwp-instagram-channel' => array( 'eyebrow', 'heading', 'sub', 'cta_label', 'follow_label' ),
        'lwp-process-steps'  => array( 'eyebrow', 'heading' ),
        'lwp-featured-product' => array( 'eyebrow', 'heading', 'cta_label', 'c_title', 'c_excerpt', 'c_price' ),
        'lwp-featured-strip' => array( 'eyebrow', 'heading' ),
        'lwp-countdown'      => array( 'heading', 'sub', 'cta_label', 'lbl_days', 'lbl_hours', 'lbl_minutes', 'lbl_seconds', 'expired_msg', 'expired_btn' ),
        'lwp-ai-search'      => array( 'placeholder', 'cta_label', 'badge' ),
        'lwp-megabar'        => array(),
        'lwp-stat-counter'   => array(),
        'lwp-trust-badges'   => array(),
        // 1.7.37 — header / footer chrome widgets
        'lwp-topbar'         => array(),
        'lwp-logo'           => array( 'text' ),
        'lwp-header-actions' => array( 'cta_label' ),
        'lwp-search-overlay' => array( 'eyebrow', 'placeholder', 'mobile_drawer_title' ),
        'lwp-footer-brand'   => array( 'logo_text', 'tagline', 'phone', 'email', 'address' ),
        'lwp-footer-column'  => array( 'heading' ),
        'lwp-footer-bottom'  => array( 'copyright', 'pay_label' ),
        // 1.7.38 — shop / single-product widgets
        'lwp-shop-filters'   => array( 'label_category', 'label_price', 'label_stock', 'apply_label', 'clear_label' ),
        'lwp-shop-toolbar'   => array( 'count_template' ),
        'lwp-spec-list'      => array(),
        'lwp-perks-list'     => array(),
        // 1.8.0 — journal / 404 widgets
        'lwp-featured-post'  => array( 'cta_label' ),
        'lwp-byline-card'    => array( 'manual_name', 'manual_role', 'manual_date' ),
        'lwp-pullquote'      => array( 'quote', 'attribution' ),
        'lwp-reading-progress' => array(),
        'lwp-load-more'      => array( 'label_template' ),
        'lwp-404-hero'       => array( 'big_number', 'eyebrow', 'heading', 'lead', 'search_placeholder', 'cta1_label', 'cta2_label' ),
        // 1.9.0 — dynamic post widgets (Pro Theme Builder-free replacements). Content
        // pulled from the current post via WP core (the_title/the_content/post_thumbnail),
        // so they have no widget-level translatable text fields — translation happens
        // at the post level via WPML/Polylang.
        'lwp-post-title'          => array(),
        'lwp-post-content'        => array(),
        'lwp-post-featured-image' => array(),
        // 1.9.0 — dynamic WC widgets Tier 1 (Pro WC widget replacements). Content
        // pulled from the current product via WC core. Only label fields are
        // translatable at the widget level; product data flows through WPML's
        // product translation layer.
        'lwp-wc-title'            => array(),
        'lwp-wc-price'            => array(),
        'lwp-wc-rating'           => array(),
        'lwp-wc-short-desc'       => array(),
        'lwp-wc-meta'             => array( 'sku_label', 'categories_label', 'tags_label' ),
        // 1.9.0 — dynamic WC widgets Tier 2/3
        'lwp-wc-gallery'          => array(),
        'lwp-wc-related'          => array( 'heading' ),
        'lwp-wc-add-to-cart'      => array( 'quantity_label' ),
        'lwp-wc-tabs'             => array(),
        // 1.10.2+ curated product grid (Pro-free [products] shortcode replacement)
        'lwp-product-grid'        => array( 'heading' ),
        // 1.10.3+ Contact CTA card (WhatsApp/Telegram auto-pulled from chat settings)
        'lwp-contact-cta'         => array( 'heading', 'lead', 'email', 'address_line', 'cta_label' ),
        // 3.17.4 — widgets audited against the Gold theme control set
        'lwp-cpt-grid' => array( 'meta_line_1', 'meta_line_2', 'view_all_label' ),
        'lwp-hero' => array( 'eyebrow', 'headline', 'lead', 'cta_primary_label', 'cta_secondary_label', 'master_name', 'master_role' ),
        'lwp-kg-stats' => array( 'eyebrow', 'heading', 'lbl_products', 'lbl_categories', 'lbl_authors', 'lbl_countries' ),
        'lwp-kg-trending' => array( 'eyebrow', 'heading' ),
        'lwp-taxonomy-terms' => array( 'count_label' ),
    );

    /**
     * Repeater-based widget types and their item text fields.
     */
    const REPEATER_WIDGETS = array(
        'tabs'      => array( 'items_key' => 'tabs', 'fields' => array( 'tab_title', 'tab_content' ) ),
        'accordion' => array( 'items_key' => 'tabs', 'fields' => array( 'tab_title', 'tab_content' ) ),
        'toggle'    => array( 'items_key' => 'tabs', 'fields' => array( 'tab_title', 'tab_content' ) ),
        'icon-list' => array( 'items_key' => 'icon_list', 'fields' => array( 'text' ) ),
        'price-table' => array( 'items_key' => 'features_list', 'fields' => array( 'item_text' ) ),
        // LuwiPress Gold theme widget repeaters (3.4.3+)
        'lwp-timeline'       => array( 'items_key' => 'rows',     'fields' => array( 'year', 'title', 'body' ) ),
        'lwp-info-bar'       => array( 'items_key' => 'items', 'fields' => array( 'label', 'sub', 'title', 'desc' ) ),
        'lwp-category-grid'  => array( 'items_key' => 'items', 'fields' => array( 'title', 'sub_label', 'eyebrow', 'sub' ) ),
        'lwp-master-grid'    => array( 'items_key' => 'items',    'fields' => array( 'name', 'location', 'specialty' ) ),
        'lwp-editorial-grid' => array( 'items_key' => 'items',    'fields' => array( 'title', 'eyebrow', 'excerpt' ) ),
        'lwp-hero-split'     => array( 'items_key' => 'stats',    'fields' => array( 'value', 'label' ) ),
        'lwp-section-head'   => array( 'items_key' => 'pills',    'fields' => array( 'label' ) ),
        'lwp-story-split'    => array( 'items_key' => 'bullets',  'fields' => array( 'title', 'body' ) ),
        'lwp-faq'            => array( 'items_key' => 'items', 'fields' => array( 'question', 'answer', 'q', 'a' ) ),
        'lwp-testimonials'   => array( 'items_key' => array( 'items', 'reviews' ), 'fields' => array( 'name', 'role', 'quote', 'location', 'product', 'date' ) ),
        'lwp-process-steps'  => array( 'items_key' => array( 'steps', 'items' ), 'fields' => array( 'title', 'body' ) ),
        'lwp-youtube-channel'=> array( 'items_key' => 'videos', 'fields' => array( 'title', 'byline', 'duration' ) ),
        'lwp-instagram-channel' => array( 'items_key' => array( 'items', 'posts' ), 'fields' => array( 'caption', 'meta' ) ),
        'lwp-trust-badges'   => array( 'items_key' => array( 'badges', 'items' ), 'fields' => array( 'label' ) ),
        'lwp-stat-counter'   => array( 'items_key' => array( 'stats', 'items' ), 'fields' => array( 'label', 'suffix', 'prefix', 'sub' ) ),
        // 1.7.37 — header / footer chrome widgets
        'lwp-topbar'         => array( 'items_key' => 'left_items', 'fields' => array( 'text', 'label' ) ),
        'lwp-search-overlay' => array( 'items_key' => 'suggest_blocks', 'fields' => array( 'label', 'chips' ) ),
        'lwp-footer-column'  => array( 'items_key' => 'links',       'fields' => array( 'label' ) ),
        'lwp-footer-bottom'  => array( 'items_key' => 'legal_links', 'fields' => array( 'label' ) ),
        // 1.7.38 — shop / single-product widget repeaters
        'lwp-shop-filters'   => array( 'items_key' => 'custom_blocks', 'fields' => array( 'heading', 'label', 'count' ) ),
        'lwp-spec-list'      => array( 'items_key' => 'rows',          'fields' => array( 'label', 'value' ) ),
        'lwp-perks-list'     => array( 'items_key' => 'perks',         'fields' => array( 'strong', 'body' ) ),
        // 1.8.0 — none of the new widgets have translatable repeaters beyond their top-level fields
        // 3.17.4 — widgets audited against the Gold theme control set
        'lwp-ai-search' => array( 'items_key' => 'chips', 'fields' => array( 'label' ) ),
        'lwp-hero' => array( 'items_key' => 'stats', 'fields' => array( 'label' ) ),
    );

    /**
     * CSS property → Elementor setting key map.
     * Allows MCP clients to use natural CSS names like "font-size" instead of "typography_font_size".
     *
     * Format types:
     *   'string'    → simple value (color hex, font name, etc.)
     *   'size_unit' → {size, unit} object (font-size, line-height, etc.)
     *   'dimension' → {top, right, bottom, left, unit, isLinked} object (padding, margin, etc.)
     */
    const CSS_MAP = array(
        // Colors
        'color'               => array( 'key' => 'title_color',              'format' => 'string' ),
        'text-color'          => array( 'key' => 'title_color',              'format' => 'string' ),
        'background-color'    => array( 'key' => 'background_color',         'format' => 'string' ),
        'background'          => array( 'key' => 'background_color',         'format' => 'string' ),
        'border-color'        => array( 'key' => 'border_color',             'format' => 'string' ),

        // Typography
        'font-size'           => array( 'key' => 'typography_font_size',     'format' => 'size_unit' ),
        'font-weight'         => array( 'key' => 'typography_font_weight',   'format' => 'string' ),
        'font-family'         => array( 'key' => 'typography_font_family',   'format' => 'string' ),
        'line-height'         => array( 'key' => 'typography_line_height',   'format' => 'size_unit' ),
        'letter-spacing'      => array( 'key' => 'typography_letter_spacing','format' => 'size_unit' ),
        'text-transform'      => array( 'key' => 'typography_text_transform','format' => 'string' ),
        'font-style'          => array( 'key' => 'typography_font_style',    'format' => 'string' ),
        'text-decoration'     => array( 'key' => 'typography_text_decoration','format' => 'string' ),
        'word-spacing'        => array( 'key' => 'typography_word_spacing',  'format' => 'size_unit' ),

        // Spacing
        'padding'             => array( 'key' => '_padding',                 'format' => 'dimension' ),
        'margin'              => array( 'key' => '_margin',                  'format' => 'dimension' ),

        // Border
        'border-radius'       => array( 'key' => 'border_radius',           'format' => 'dimension' ),
        'border-width'        => array( 'key' => 'border_width',            'format' => 'dimension' ),
        'border-style'        => array( 'key' => 'border_border',           'format' => 'string' ),

        // Sizing
        'width'               => array( 'key' => '_element_width',          'format' => 'string' ),
        'text-align'          => array( 'key' => 'align',                   'format' => 'string' ),
        'opacity'             => array( 'key' => '_opacity',                'format' => 'string' ),
    );

    /**
     * Widget-specific color key overrides.
     * Different widgets use different keys for their text color.
     */
    const WIDGET_COLOR_KEYS = array(
        'heading'        => 'title_color',
        'text-editor'    => 'color',
        'button'         => 'button_text_color',
        'icon-box'       => 'title_color',
        'image-box'      => 'title_color',
        'counter'        => 'title_color',
        'progress'       => 'title_color',
        'alert'          => 'alert_title_color',
        'testimonial'    => 'content_content_color',
    );

    /**
     * Widget-specific background color key overrides.
     */
    const WIDGET_BG_KEYS = array(
        'button'  => 'button_background_color',
        'section' => 'background_color',
        'column'  => 'background_color',
    );

    /**
     * Language name map for prompts.
     */
    const LANG_NAMES = array(
        'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French',
        'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch',
        'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese',
        'ko' => 'Korean', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish',
        'fi' => 'Finnish', 'no' => 'Norwegian', 'el' => 'Greek', 'he' => 'Hebrew',
    );

    /* ──────────────────────── REST Endpoints ────────────────────────── */

    public function register_endpoints() {
        $ns = 'luwipress/v1';

        register_rest_route( $ns, '/elementor/page/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_page_data' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/translate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_translate_page' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Queue Elementor translations as background jobs (no timeout)
        register_rest_route( $ns, '/elementor/translate-queue', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_queue_translations' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Inspect the pending background translation queue
        register_rest_route( $ns, '/elementor/translate-queue', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_translation_queue' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            'args'                => array(
                'post_id'  => array( 'sanitize_callback' => 'absint' ),
                'language' => array( 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // Cancel ONE pending queue entry (post + language)
        register_rest_route( $ns, '/elementor/translate-queue/cancel', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_cancel_translation_queue_entry' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            'args'                => array(
                'post_id'  => array( 'required' => true, 'sanitize_callback' => 'absint' ),
                'language' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        register_rest_route( $ns, '/elementor/widget', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_widget_text' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/style', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_widget_style' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/bulk-update', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_bulk_update' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/outline/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_page_outline' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Structural operations
        register_rest_route( $ns, '/elementor/add-widget', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_add_widget' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/add-section', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_add_section' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/delete', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_delete_element' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/move', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_move_element' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/clone', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_clone_element' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/copy-section', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_copy_section' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Inspect helpers (3.1.40)
        register_rest_route( $ns, '/elementor/outline-deep/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_outline_deep' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/find/(?P<post_id>\d+)/(?P<element_id>[a-f0-9]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_find_by_id' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/find-by-text', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_find_by_text' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/kit-css/preflight', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_kit_css_preflight' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/custom-css', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_custom_css' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/responsive', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_responsive' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/global-style', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_global_style' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Sync & Audit
        register_rest_route( $ns, '/elementor/sync-styles', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_sync_styles' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/audit/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_audit_spacing' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Revision
        register_rest_route( $ns, '/elementor/snapshot', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_create_snapshot' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/rollback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_rollback' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/snapshots/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_list_snapshots' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Raw post_meta access — recovery surface, whitelist-enforced.
        register_rest_route( $ns, '/post-meta/raw', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_post_meta_raw_get' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_post_meta_raw_set' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
        ) );

        register_rest_route( $ns, '/elementor/auto-fix', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_auto_fix' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Theme Builder template management (3.1.42-hotfix4 — Tapadum partnership feature).
        // List, create, clone, set conditions, get/set type for elementor_library posts.
        register_rest_route( $ns, '/elementor/templates', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_templates_list' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            'args' => array(
                'type'   => array( 'description' => 'Filter by template type (header/footer/single-post/single-product/archive/single-404/search-results/cart/checkout/my-account/popup/page/section)' ),
                'status' => array( 'default' => 'any' ),
                'limit'  => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
            ),
        ) );

        register_rest_route( $ns, '/elementor/template/create', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_template_create' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/template/clone', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_template_clone' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/template/conditions', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_template_conditions_get' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_template_conditions_set' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
        ) );

        register_rest_route( $ns, '/elementor/template/delete', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_template_delete' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/responsive-audit/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_responsive_audit' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Global CSS — Elementor Kit custom_css field
        register_rest_route( $ns, '/elementor/global-css', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_global_css' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_set_global_css' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
        ) );

        // Batch page-level CSS — apply CSS to multiple posts by ID or post_type
        register_rest_route( $ns, '/elementor/batch-css', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_batch_page_css' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Elementor Kit info — expose kit ID, settings, breakpoints
        register_rest_route( $ns, '/elementor/kit', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_kit_info' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Force flush all Elementor CSS files
        register_rest_route( $ns, '/elementor/flush-css', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_flush_css' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Purge page cache (LiteSpeed / WP Rocket / W3TC / Super Cache / Cache Enabler)
        register_rest_route( $ns, '/elementor/purge-page-cache', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_purge_page_cache' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Elementor CSS print method — set to 'internal' to eliminate FOUC
        register_rest_route( $ns, '/elementor/print-method', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_print_method' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_set_print_method' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
        ) );

        // Google Fonts URL — output as <link> in wp_head (avoids @import in Kit CSS)
        register_rest_route( $ns, '/elementor/google-fonts', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_google_fonts' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_set_google_fonts' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
        ) );

        // Reorder top-level sections by providing new order
        register_rest_route( $ns, '/elementor/reorder-sections', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_reorder_sections' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Find and replace text/styles across pages
        register_rest_route( $ns, '/elementor/find-replace', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_find_replace' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Sync page structure to WPML translations (preserving translated texts)
        register_rest_route( $ns, '/elementor/sync-structure', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_sync_structure' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Global design tokens (colors, typography) from Elementor Kit
        register_rest_route( $ns, '/elementor/css-vars', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_css_vars' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_set_css_vars' ),
                'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            ),
        ) );

        // List saved Elementor templates
        register_rest_route( $ns, '/elementor/templates', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_list_templates' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Apply saved template to a page
        register_rest_route( $ns, '/elementor/apply-template', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_apply_template' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        /* ─── FR suite (3.4.2): bulk read / css read / image / advanced / schema / diff ─── */
        register_rest_route( $ns, '/elementor/widgets-bulk', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_get_widgets_bulk' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );
        register_rest_route( $ns, '/elementor/custom-css-get', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_custom_css' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );
        register_rest_route( $ns, '/elementor/widget-schema', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_widget_schema' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );
        register_rest_route( $ns, '/elementor/widget-image', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_widget_image' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );
        register_rest_route( $ns, '/elementor/widget-advanced', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_widget_advanced' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );
        register_rest_route( $ns, '/elementor/snapshot-diff', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_snapshot_diff' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );
    }

    /* ──────────────────────── READ Operations ───────────────────────── */

    /**
     * Get raw Elementor data for a post.
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error Parsed elementor data array or error.
     */
    public function get_elementor_data( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
        }

        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( empty( $raw ) ) {
            return new WP_Error( 'no_elementor', 'No Elementor data for this post', array( 'status' => 404 ) );
        }

        $data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'parse_error', 'Failed to parse Elementor JSON', array( 'status' => 500 ) );
        }

        return $data;
    }

    /**
     * Get a flat list of all elements (sections, columns, widgets) from Elementor data.
     *
     * Returns every element with its id, type, parent info, and relevant
     * settings — giving the MCP client a full picture of the page structure.
     *
     * @param int  $post_id      Post ID.
     * @param bool $widgets_only If true, only return widgets (legacy compat).
     * @return array|WP_Error Array of element info or error.
     */
    public function get_widget_tree( $post_id, $widgets_only = false ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $elements = array();
        $this->walk_elements_with_path( $data, '', function ( $element, $parent_id ) use ( &$elements, $widgets_only ) {
            $el_type = $element['elType'] ?? '';

            // In widgets_only mode, skip sections/columns
            if ( $widgets_only && $el_type !== 'widget' ) {
                return;
            }

            $settings    = $element['settings'] ?? array();
            $widget_type = $element['widgetType'] ?? null;

            $info = array(
                'id'        => $element['id'],
                'el_type'   => $el_type,
                'parent_id' => $parent_id ?: null,
            );

            if ( $widget_type ) {
                $info['widget_type'] = $widget_type;
            }

            // For sections/columns, extract key visual settings
            if ( $el_type === 'section' || $el_type === 'column' || $el_type === 'container' ) {
                $info['style_summary'] = $this->summarize_styles( $settings, $el_type );
            }

            // For widgets, extract text + style summary
            if ( $el_type === 'widget' ) {
                $texts = $this->extract_widget_texts( $widget_type, $settings );
                if ( ! empty( $texts ) ) {
                    $info['texts'] = $texts;
                }
                $info['style_summary'] = $this->summarize_styles( $settings, $widget_type );
            }

            // Always include full settings for detailed inspection
            $info['settings'] = $settings;

            $elements[] = $info;
        } );

        return $elements;
    }

    /**
     * Get a condensed page structure — just element IDs, types, hierarchy and text.
     * Much lighter output for quick page overview without full settings.
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error
     */
    public function get_page_outline( $post_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        return $this->build_outline( $data );
    }

    /**
     * Recursively build a compact outline of the page structure.
     */
    private function build_outline( array $elements ) {
        $outline = array();
        foreach ( $elements as $element ) {
            $el_type     = $element['elType'] ?? '';
            $settings    = $element['settings'] ?? array();
            $widget_type = $element['widgetType'] ?? null;

            $node = array(
                'id'      => $element['id'],
                'el_type' => $el_type,
            );

            if ( $widget_type ) {
                $node['widget_type'] = $widget_type;

                // Include text preview (first 80 chars)
                $texts = $this->extract_widget_texts( $widget_type, $settings );
                if ( ! empty( $texts ) ) {
                    $first_text = reset( $texts );
                    $node['text_preview'] = mb_substr( wp_strip_all_tags( $first_text ), 0, 80 );
                }
            }

            if ( ! empty( $element['elements'] ) ) {
                $node['children'] = $this->build_outline( $element['elements'] );
            }

            $outline[] = $node;
        }
        return $outline;
    }

    /**
     * Extract all translatable text from a page.
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error Keyed array: widget_id => ['type' => ..., 'texts' => [...]]
     */
    public function extract_translatable_text( $post_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $translatable = array();
        $this->walk_elements( $data, function ( $element ) use ( &$translatable ) {
            if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
                return;
            }

            $widget_type = $element['widgetType'] ?? '';
            $settings    = $element['settings'] ?? array();

            if ( ! isset( self::TRANSLATABLE_WIDGETS[ $widget_type ] ) ) {
                return;
            }

            $texts = $this->extract_widget_texts( $widget_type, $settings );
            if ( ! empty( $texts ) ) {
                $translatable[ $element['id'] ] = array(
                    'type'  => $widget_type,
                    'texts' => $texts,
                );
            }
        } );

        return $translatable;
    }

    /* ──────────────────────── WRITE Operations ──────────────────────── */

    /** Separator for indexed repeater paths: "items:0:title". */
    const REPEATER_PATH_SEPARATOR = ':';

    /**
     * Sanitize a widget setting value of ANY shape.
     *
     * wp_kses_post() is a string-only sanitizer. Feeding it a repeater array or a
     * link-control object flattens the value (or fatals inside the CSS rebuild that
     * follows the save) and the caller gets a broken response body instead of an
     * error -- tapadum, 2026-08-25. Arrays are walked recursively so structured
     * controls survive intact; anything we cannot represent is refused loudly.
     *
     * @param mixed $value Raw value from the caller.
     * @param int   $depth Recursion guard.
     * @return mixed|WP_Error Sanitized value, or WP_Error for unsupported types.
     */
    public static function sanitize_setting_value( $value, $depth = 0 ) {
        if ( $depth > 12 ) {
            return new WP_Error( 'value_too_deep', 'Setting value nested deeper than 12 levels', array( 'status' => 400 ) );
        }
        if ( null === $value ) {
            return '';
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
            return $value;
        }
        if ( is_string( $value ) ) {
            return wp_kses_post( $value );
        }
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $key => $item ) {
                $safe_key  = is_int( $key ) ? $key : sanitize_text_field( (string) $key );
                $safe_item = self::sanitize_setting_value( $item, $depth + 1 );
                if ( is_wp_error( $safe_item ) ) {
                    return $safe_item;
                }
                $clean[ $safe_key ] = $safe_item;
            }
            return $clean;
        }
        return new WP_Error(
            'unsupported_value_type',
            sprintf( 'Setting value of type "%s" cannot be written; pass a string, number, or array.', gettype( $value ) ),
            array( 'status' => 400 )
        );
    }

    /**
     * Write ONE field into a widget's settings array.
     *
     * Shared by set_widget_text() and bulk_update() so both entry points behave
     * identically. Three behaviours the old inline write got wrong:
     *
     *  1. "items:0:title" paths now land in the repeater row instead of creating a
     *     flat junk key the renderer never reads.
     *  2. A bare string aimed at a link control ({url, is_external, nofollow})
     *     fills the url key instead of replacing the object -- the old behaviour
     *     silently deleted the rendered button and could not be undone with the
     *     same tool, only via snapshot rollback.
     *  3. A bare string aimed at any other structured control is refused rather
     *     than accepted-then-broken.
     *
     * @param array  $settings Widget settings, modified in place.
     * @param string $field    Field key, optionally an "items:index:field" path.
     * @param mixed  $value    New value.
     * @return true|WP_Error
     */
    public static function apply_widget_setting( array &$settings, $field, $value ) {
        $field = (string) $field;

        // ── Nested paths ──
        if ( false !== strpos( $field, self::REPEATER_PATH_SEPARATOR ) ) {
            $parts = explode( self::REPEATER_PATH_SEPARATOR, $field, 3 );

            // Two segments address a key inside an OBJECT control -- most often a
            // link control's url ("cta_url:url", "all_link:url"). Without this the
            // only way to change a link was to resend the whole object, and a bare
            // string write silently broke the rendered element (tapadum, 2026-08-28).
            if ( 2 === count( $parts ) ) {
                list( $object_key, $sub_key ) = $parts;
                $object_key = sanitize_text_field( $object_key );

                // "tabs:0" names a repeater ROW, not a value -- ambiguous on its own.
                if ( is_numeric( $sub_key ) ) {
                    return new WP_Error(
                        'invalid_field_path',
                        sprintf( 'Field path "%s" addresses a repeater row; use "%s:%s:<sub_field>".', $field, $object_key, $sub_key ),
                        array( 'status' => 400 )
                    );
                }
                if ( ! isset( $settings[ $object_key ] ) || ! is_array( $settings[ $object_key ] ) ) {
                    return new WP_Error(
                        'not_an_object_field',
                        sprintf( 'Field "%s" is not an object control on this widget; read the widget first.', $object_key ),
                        array( 'status' => 404 )
                    );
                }

                $clean = self::sanitize_setting_value( $value );
                if ( is_wp_error( $clean ) ) {
                    return $clean;
                }
                $settings[ $object_key ][ sanitize_text_field( $sub_key ) ] = $clean;
                return true;
            }

            list( $items_key, $index, $sub_field ) = $parts;
            $items_key = sanitize_text_field( $items_key );
            $sub_field = sanitize_text_field( $sub_field );
            $index     = (int) $index;

            if ( ! isset( $settings[ $items_key ] ) || ! is_array( $settings[ $items_key ] )
                || ! isset( $settings[ $items_key ][ $index ] ) || ! is_array( $settings[ $items_key ][ $index ] ) ) {
                return new WP_Error(
                    'repeater_index_missing',
                    sprintf( 'Repeater "%s" has no row %d on this widget; read the widget first.', $items_key, $index ),
                    array( 'status' => 404 )
                );
            }

            $clean = self::sanitize_setting_value( $value );
            if ( is_wp_error( $clean ) ) {
                return $clean;
            }
            $settings[ $items_key ][ $index ][ $sub_field ] = $clean;
            return true;
        }

        $key      = sanitize_text_field( $field );
        $existing = isset( $settings[ $key ] ) ? $settings[ $key ] : null;

        $clean = self::sanitize_setting_value( $value );
        if ( is_wp_error( $clean ) ) {
            return $clean;
        }

        // ── Shape guard: never downgrade a structured control to a bare scalar ──
        if ( is_array( $existing ) && ! is_array( $clean ) ) {
            if ( array_key_exists( 'url', $existing ) ) {
                $existing['url']  = is_scalar( $clean ) ? (string) $clean : '';
                $settings[ $key ] = $existing;
                return true;
            }
            return new WP_Error(
                'shape_mismatch',
                sprintf( 'Field "%s" holds a structured control; pass an array of the same shape, not a bare value.', $key ),
                array( 'status' => 400 )
            );
        }

        $settings[ $key ] = $clean;
        return true;
    }

    /**
     * Accepted find-replace scopes.
     *
     * Single source of truth for the entry-point allowlist AND the scope flags in
     * find_replace_on_post(). They drifted once: 'links'/'all' were taught to the
     * walker and published in the MCP schema while this allowlist still rejected
     * them, so the documented scope 400'd (caught on tapadum, 2026-08-28).
     */
    const FIND_REPLACE_SCOPES = array( 'text', 'styles', 'both', 'links', 'all' );

    /**
     * Run a replacement over every link control in a settings array.
     *
     * Elementor stores links as arrays ({url, is_external, nofollow}), including
     * inside repeater rows. The find-replace text scope reads the widget TEXT map
     * and the styles scope only visits scalar settings, so link targets were
     * unreachable from either — which left no practical way to repoint
     * source-language URLs in translated pages (tapadum, 2026-08-28).
     *
     * @param array    $settings Settings array, modified in place.
     * @param callable $replacer Receives the current url string, returns the new one.
     * @param int      $depth    Recursion guard.
     * @return int Number of url values actually changed.
     */
    public static function replace_in_link_fields( array &$settings, $replacer, $depth = 0 ) {
        if ( $depth > 12 ) {
            return 0;
        }

        $changed = 0;
        foreach ( $settings as $key => &$value ) {
            if ( ! is_array( $value ) ) {
                continue;
            }

            if ( isset( $value['url'] ) && is_string( $value['url'] ) && '' !== $value['url'] ) {
                $new = (string) call_user_func( $replacer, $value['url'] );
                if ( $new !== $value['url'] ) {
                    $value['url'] = $new;
                    $changed++;
                }
            }

            // Repeater rows and other nested structures.
            $changed += self::replace_in_link_fields( $value, $replacer, $depth + 1 );
        }
        unset( $value );

        return $changed;
    }

    /**
     * Apply a map of field => value to one widget, collecting per-field errors.
     *
     * Atomic per widget: the batch is staged on a copy and only committed when every
     * field succeeds. A half-applied widget is worse than a refused one -- the caller
     * cannot tell which fields landed, and a mixed-shape settings array can take the
     * renderer down.
     *
     * @param array $settings   Widget settings, replaced in place on success.
     * @param array $new_values field => value.
     * @return array List of ['field' => ..., 'code' => ..., 'message' => ...] failures.
     */
    public static function apply_widget_settings( array &$settings, array $new_values ) {
        $working = $settings;
        $errors  = array();
        foreach ( $new_values as $field => $value ) {
            $result = self::apply_widget_setting( $working, $field, $value );
            if ( is_wp_error( $result ) ) {
                $errors[] = array(
                    'field'   => (string) $field,
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                );
            }
        }
        if ( empty( $errors ) ) {
            $settings = $working;
        }
        return $errors;
    }

    /**
     * Update text in a specific widget.
     *
     * @param int    $post_id   Post ID.
     * @param string $widget_id Widget element ID.
     * @param array  $new_texts Associative array of field => new text.
     * @return true|WP_Error
     */
    public function set_widget_text( $post_id, $widget_id, array $new_texts ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found  = false;
        $errors = array();
        $data   = $this->walk_elements_modify( $data, function ( &$element ) use ( $widget_id, $new_texts, &$found, &$errors ) {
            if ( ( $element['id'] ?? '' ) !== $widget_id ) {
                return;
            }
            $found = true;

            if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
                $element['settings'] = array();
            }
            $errors = self::apply_widget_settings( $element['settings'], $new_texts );
        } );

        if ( ! $found ) {
            return new WP_Error( 'widget_not_found', 'Widget ID not found: ' . $widget_id, array( 'status' => 404 ) );
        }

        // All-or-nothing: a half-applied write leaves the widget in a shape the
        // renderer may not survive, and the caller cannot tell which fields landed.
        if ( ! empty( $errors ) ) {
            return new WP_Error(
                'setting_write_rejected',
                sprintf(
                    '%d of %d field(s) rejected on widget %s; nothing was written. First: %s',
                    count( $errors ), count( $new_texts ), $widget_id, $errors[0]['message']
                ),
                array( 'status' => 400, 'errors' => $errors )
            );
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Update style properties on any Elementor element (widget, section, or column).
     *
     * Accepts both CSS-friendly properties ("font-size": "18px") and
     * Elementor-native keys ("typography_font_size": {"size": 18, "unit": "px"}).
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element ID (widget, section, or column).
     * @param array  $styles     CSS or Elementor style properties.
     * @return true|WP_Error
     */
    public function set_widget_style( $post_id, $element_id, array $styles ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found = false;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $element_id, $styles, &$found ) {
            if ( ( $element['id'] ?? '' ) !== $element_id ) {
                return;
            }
            $found = true;

            $el_type     = $element['elType'] ?? 'widget';
            $widget_type = $element['widgetType'] ?? $el_type;

            // Normalize CSS properties to Elementor keys
            $normalized = $this->normalize_styles( $styles, $widget_type, $el_type );

            foreach ( $normalized as $key => $value ) {
                $element['settings'][ $key ] = $value;
            }
        } );

        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element ID not found: ' . $element_id, array( 'status' => 404 ) );
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Bulk update multiple widgets' text and/or styles.
     *
     * @param int   $post_id Post ID.
     * @param array $changes Array of changes: [['widget_id' => ..., 'texts' => [...], 'styles' => [...]], ...]
     * @return array|WP_Error Results per widget.
     */
    public function bulk_update( $post_id, array $changes ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $change_map = array();
        foreach ( $changes as $change ) {
            $wid = $change['widget_id'] ?? '';
            if ( $wid ) {
                $change_map[ $wid ] = $change;
            }
        }

        $results = array();
        $data = $this->walk_elements_modify( $data, function ( &$element ) use ( $change_map, &$results ) {
            $wid = $element['id'] ?? '';
            if ( ! isset( $change_map[ $wid ] ) ) {
                return;
            }

            $change = $change_map[ $wid ];

            // Apply text changes
            $text_errors = array();
            if ( ! empty( $change['texts'] ) && is_array( $change['texts'] ) ) {
                if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
                    $element['settings'] = array();
                }
                $text_errors = self::apply_widget_settings( $element['settings'], $change['texts'] );
            }

            // Apply style changes (CSS-friendly or Elementor-native)
            if ( ! empty( $change['styles'] ) && is_array( $change['styles'] ) ) {
                $el_type     = $element['elType'] ?? 'widget';
                $widget_type = $element['widgetType'] ?? $el_type;
                $normalized  = $this->normalize_styles( $change['styles'], $widget_type, $el_type );
                foreach ( $normalized as $key => $value ) {
                    $element['settings'][ $key ] = $value;
                }
            }

            // Per-widget reporting: a rejected field must be visible in the response
            // instead of surfacing as a broken JSON body (tapadum 2026-08-25).
            $results[ $wid ] = empty( $text_errors )
                ? 'updated'
                : array( 'status' => 'rejected', 'errors' => $text_errors );
        } );

        // Check for missing widgets
        foreach ( $change_map as $wid => $change ) {
            if ( ! isset( $results[ $wid ] ) ) {
                $results[ $wid ] = 'not_found';
            }
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        return $results;
    }

    /* ──────────────────── TRANSLATE Operation ────────────────────────── */

    /** Default share of attempted strings that must come back before we write. */
    const MIN_TRANSLATION_COVERAGE = 0.8;

    /** Max background attempts per (post, language) before the job is given up on. */
    const MAX_TRANSLATION_ATTEMPTS = 3;

    /**
     * Does a translation post's slug need rebuilding?
     *
     * Both broken shapes matter: WordPress falls back to the post ID for an
     * untitled post (numeric slug), and a translation created before its title
     * existed can be saved with NO slug at all. The old check only tested
     * is_numeric(), and is_numeric('') is false — so empty-slug siblings were
     * never repaired and their permalinks bounced (tapadum finding 6b).
     *
     * @param string $post_name Current slug.
     * @return bool
     */
    public static function slug_needs_repair( $post_name ) {
        $post_name = trim( (string) $post_name );
        return '' === $post_name || is_numeric( $post_name );
    }

    /**
     * Meta key holding the background attempt count for one target language.
     *
     * @param string $language Target language code.
     * @return string
     */
    public static function translation_attempt_meta_key( $language ) {
        return '_luwipress_translation_attempts_' . sanitize_key( $language );
    }

    /**
     * Share of attempted strings the AI actually returned (0.0 - 1.0).
     *
     * The old code treated "at least one string came back" as success and then wrote
     * a source-language clone over the live translation. Measuring coverage is what
     * lets save_translated_page() refuse a mostly-failed pass.
     *
     * @param array $attempted List of ['widget_id' => ..., 'field' => ...].
     * @param array $trans_map widget_id => [field => translated text].
     * @return float
     */
    public static function translation_coverage( array $attempted, array $trans_map ) {
        $total = count( $attempted );
        if ( $total < 1 ) {
            return 0.0;
        }
        $hits = 0;
        foreach ( $attempted as $item ) {
            $wid   = $item['widget_id'] ?? '';
            $field = $item['field'] ?? '';
            if ( '' === $wid || '' === $field ) {
                continue;
            }
            if ( isset( $trans_map[ $wid ][ $field ] ) && '' !== (string) $trans_map[ $wid ][ $field ] ) {
                $hits++;
            }
        }
        return round( $hits / $total, 4 );
    }

    /**
     * Flatten an Elementor element tree into element_id => settings.
     *
     * @param array $elements Decoded _elementor_data.
     * @return array
     */
    public static function collect_widget_settings( array $elements ) {
        $map  = array();
        $walk = function ( $nodes ) use ( &$walk, &$map ) {
            foreach ( $nodes as $node ) {
                if ( ! is_array( $node ) ) {
                    continue;
                }
                $id = $node['id'] ?? '';
                if ( '' !== $id && isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
                    $map[ $id ] = $node['settings'];
                }
                if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
                    $walk( $node['elements'] );
                }
            }
        };
        $walk( $elements );
        return $map;
    }

    /**
     * Pick the value to write for one translatable field.
     *
     * Order matters: a fresh translation wins; otherwise whatever the target page
     * already shows is kept (that is either an earlier good translation or an
     * operator's hand fix -- both were being overwritten with source text); only a
     * genuinely empty target falls back to the source string.
     *
     * @param string $widget_id    Element ID.
     * @param string $field        Setting key.
     * @param array  $trans_map    Fresh translations.
     * @param array  $existing_map Current target-page settings (element_id => settings).
     * @param mixed  $source_value Value on the source page.
     * @return mixed
     */
    public static function resolve_translated_value( $widget_id, $field, array $trans_map, array $existing_map, $source_value ) {
        if ( isset( $trans_map[ $widget_id ][ $field ] ) && '' !== (string) $trans_map[ $widget_id ][ $field ] ) {
            return $trans_map[ $widget_id ][ $field ];
        }
        if ( isset( $existing_map[ $widget_id ][ $field ] ) ) {
            $previous = $existing_map[ $widget_id ][ $field ];
            $has_value = is_array( $previous ) ? ! empty( $previous ) : ( '' !== trim( (string) $previous ) );
            if ( $has_value ) {
                return $previous;
            }
        }
        return $source_value;
    }

    /**
     * Read pending wp_cron events for one hook out of a cron array.
     *
     * The Elementor translation queue lives in the `cron` option and was reachable
     * from no endpoint at all, so operators could neither see nor cancel a pending
     * job (tapadum finding 2).
     *
     * @param array  $cron_array Result of _get_cron_array().
     * @param string $hook       Hook name to filter on.
     * @return array List of ['post_id' => int, 'language' => string, 'timestamp' => int].
     */
    public static function parse_cron_queue( array $cron_array, $hook ) {
        $entries = array();
        foreach ( $cron_array as $timestamp => $hooks ) {
            // The cron option carries a non-numeric 'version' key alongside timestamps.
            if ( ! is_int( $timestamp ) || ! is_array( $hooks ) ) {
                continue;
            }
            if ( ! isset( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
                continue;
            }
            foreach ( $hooks[ $hook ] as $event ) {
                $args = ( is_array( $event ) && isset( $event['args'] ) && is_array( $event['args'] ) )
                    ? array_values( $event['args'] )
                    : array();
                if ( count( $args ) < 2 ) {
                    continue;
                }
                $entries[] = array(
                    'post_id'   => (int) $args[0],
                    'language'  => (string) $args[1],
                    'timestamp' => (int) $timestamp,
                );
            }
        }
        usort( $entries, function ( $a, $b ) {
            return $a['timestamp'] <=> $b['timestamp'];
        } );
        return $entries;
    }

    /**
     * Translate all Elementor page content to a target language.
     *
     * Extracts all translatable text, sends to AI Engine, creates
     * (or updates) WPML translation post with translated Elementor data.
     *
     * Entry lock: a full run takes 60s+ (synchronous AI calls), which is longer
     * than most HTTP client timeouts -- clients then blindly retry while the
     * first run is still working. Without this lock every retry burned a full
     * AI pass AND created another translation post (the 2026-06-10 orphan-page
     * incident: 14 orphans from one IT + one FR request). Concurrent calls for
     * the same (post, language) now get an immediate 'translation_in_progress'
     * error instead.
     *
     * @param int    $post_id         Source post ID.
     * @param string $target_language Target language code.
     * @return array|WP_Error Translation result.
     */
    public function translate_page( $post_id, $target_language ) {
        $lock_key = 'luwipress_eltr_lock_' . absint( $post_id ) . '_' . sanitize_key( $target_language );
        if ( get_transient( $lock_key ) ) {
            return new WP_Error(
                'translation_in_progress',
                sprintf(
                    'Translation of #%d to %s is already running (started <15 min ago). Do NOT re-call -- each run costs a full AI pass. Poll translation_post_siblings or system_logs for completion.',
                    $post_id, $target_language
                ),
                array( 'status' => 409 )
            );
        }
        set_transient( $lock_key, time(), 15 * MINUTE_IN_SECONDS );

        // A run takes 60s+; the REST/MCP entry points otherwise execute at the
        // host's default max_execution_time and die mid-write when the client
        // disconnects. (AJAX and cron paths already set their own limits.)
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }
        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }

        try {
            return $this->translate_page_unlocked( $post_id, $target_language );
        } finally {
            // Releases on every return and uncaught Throwable. Engine fatals
            // (hard timeout, OOM) skip finally -- the 15-min TTL is the
            // backstop; retries during that window get a 409 that self-heals.
            delete_transient( $lock_key );
        }
    }

    /**
     * Lock-free inner implementation of translate_page(). Never call directly
     * outside the locked wrapper above.
     *
     * @param int    $post_id         Source post ID.
     * @param string $target_language Target language code.
     * @return array|WP_Error Translation result.
     */
    private function translate_page_unlocked( $post_id, $target_language ) {
        // CRITICAL inline guard: every translation flow eventually calls this method,
        // so this is the single chokepoint where we MUST verify $post_id is a legit
        // EN source -- not a translation, not a cascade duplicate. Without this,
        // stale UI items / queued cron jobs / external REST calls keep producing
        // duplicate translations of translations until coverage stops making sense.
        if ( defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'LuwiPress_Translation' ) ) {
            global $wpdb;
            $src_post = get_post( $post_id );
            if ( ! $src_post ) {
                return new WP_Error( 'not_found', 'Source post #' . $post_id . ' not found' );
            }
            $default_lang = LuwiPress_Translation::get_default_language();
            $element_type = 'post_' . $src_post->post_type;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT language_code, source_language_code, trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $post_id, $element_type
            ) );
            if ( $row && ( $row->language_code !== $default_lang || $row->source_language_code !== null ) ) {
                LuwiPress_Logger::log( sprintf(
                    'translate_page guard: refusing #%d -- registered as %s translation (source_language_code=%s)',
                    $post_id, $row->language_code, $row->source_language_code ?? 'NULL'
                ), 'warning' );
                return new WP_Error( 'not_source', sprintf( 'Post #%d is not a valid translation source', $post_id ) );
            }
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
                    // Try to RE-STAMP this row as the correct translation row (using our
                    // own source-meta to find the right language) instead of deleting it.
                    // Deleting the row leaves the missing-list showing the source as
                    // "needs translation" -> we re-translate -> WPML mis-stamps again
                    // -> infinite loop. Re-stamping breaks the loop because the source
                    // now has a real translation registered.
                    $self_source = absint( get_post_meta( $post_id, '_luwipress_translation_source', true ) );
                    $self_lang   = sanitize_text_field( get_post_meta( $post_id, '_luwipress_translation_language', true ) );
                    if ( $self_source && $self_lang && $self_lang !== $default_lang && $self_source === absint( $older_sibling ) ) {
                        // Remove any conflicting target-language row in the source's trid first.
                        $wpdb->delete(
                            $wpdb->prefix . 'icl_translations',
                            array( 'trid' => $row->trid, 'language_code' => $self_lang, 'element_type' => $element_type ),
                            array( '%d', '%s', '%s' )
                        );
                        $wpdb->update(
                            $wpdb->prefix . 'icl_translations',
                            array(
                                'language_code'        => $self_lang,
                                'source_language_code' => $default_lang,
                            ),
                            array( 'element_id' => $post_id, 'element_type' => $element_type ),
                            array( '%s', '%s' ),
                            array( '%d', '%s' )
                        );
                        LuwiPress_Logger::log( sprintf(
                            'translate_page guard: #%d re-stamped as %s translation of #%d (trid %d) -- breaks WPML race loop',
                            $post_id, $self_lang, $older_sibling, $row->trid
                        ), 'warning' );
                        return new WP_Error( 'restamped', sprintf( 'Post #%d was a cascade dup of #%d; re-stamped as %s translation', $post_id, $older_sibling, $self_lang ) );
                    }
                    // Fallback: no source meta, plain refusal.
                    LuwiPress_Logger::log( sprintf(
                        'translate_page guard: #%d is cascade duplicate (older EN sibling #%d in trid %d) -- refusing',
                        $post_id, $older_sibling, $row->trid
                    ), 'warning' );
                    return new WP_Error( 'cascade_dup', sprintf( 'Post #%d is a cascade duplicate of #%d', $post_id, $older_sibling ) );
                }
            }
        }

        // Validate Elementor data exists
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // Auto-snapshot before translation for rollback support
        $this->create_snapshot( $post_id, sprintf( 'Pre-translation backup (%s)', $target_language ) );

        // Save pre-translation revision
        $revision_id = wp_save_post_revision( $post_id );
        if ( $revision_id ) {
            update_post_meta( $post_id, '_luwipress_pre_translation_revision', $revision_id );
        }

        // Extract translatable texts
        $translatable = $this->extract_translatable_text( $post_id );
        if ( is_wp_error( $translatable ) ) {
            return $translatable;
        }
        if ( empty( $translatable ) ) {
            // No translatable text -- typical for shortcode-only pages (Cart / Checkout /
            // My Account where WooCommerce renders everything via shortcodes that already
            // honour WPML's string translation). Instead of failing, create a structure-only
            // translation post so WPML knows the pair exists and the missing-list stops
            // surfacing it. The translation post's _elementor_data is a clone of the source
            // (no AI calls made, $0 cost), and the operator can manually edit shortcode
            // args via WPML String Translation if they ever need localized variants.
            $translated_data = $this->get_elementor_data( $post_id );
            if ( is_wp_error( $translated_data ) ) {
                $translated_data = array();
            }
            $result = $this->save_translated_page( $post_id, $target_language, (array) $translated_data );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $tid = $result['translated_id'] ?? 0;
            if ( $tid ) {
                $src = get_post( $post_id );
                if ( $src ) {
                    wp_update_post( array(
                        'ID'           => $tid,
                        'post_title'   => $src->post_title,
                        'post_status'  => 'publish',
                    ) );
                    update_post_meta( $tid, '_luwipress_synced_source_modified', get_post_field( 'post_modified_gmt', $post_id ) );
                }
                // BELT: wp_update_post fires WPML's save_post handler which can
                // re-stamp the fresh translation from a stale cache (orphan-page
                // bug, 2026-06-10). Re-assert registration before returning.
                if ( defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'LuwiPress_Translation' ) ) {
                    LuwiPress_Translation::get_instance()->ensure_translation_registration( $tid, $post_id, $target_language );
                }
            }
            // Mark the source post so future cron sweeps skip it (avoids the
            // "shortcode-only page re-queued every cron tick" loop documented as
            // BUG-014 — the queue would otherwise schedule the same post forever
            // since it has no translatable content to flip the "needs translation"
            // signal off).
            update_post_meta( $post_id, '_luwipress_no_translatable_text', '1' );
            LuwiPress_Logger::log( sprintf( 'Elementor translate #%d -> %s: no translatable text (shortcode-only page) -- structure-only WPML pair created', $post_id, $target_language ), 'info' );
            return array( 'translated_id' => $tid, 'note' => 'structure_only_no_text' );
        }

        // SELF-HEAL: we reached here, so the page HAS translatable text. Clear any
        // stale "no translatable text" flag that an earlier transient failure (or a
        // buggy prior version) may have left on the source -- otherwise the cron
        // sweep would keep skipping this page forever.
        delete_post_meta( $post_id, '_luwipress_no_translatable_text' );

        // Build a flat structure for AI translation
        $texts_for_ai = array();
        $long_texts   = array(); // Texts > 3000 chars — translated individually via chunking
        foreach ( $translatable as $widget_id => $info ) {
            // Get the widget's title to detect duplicate <h1> in extra_title
            $widget_title = $info['texts']['ekit_heading_title'] ?? '';

            foreach ( $info['texts'] as $field => $text ) {
                // Defensive: widget settings can contain non-string values (arrays for
                // typography, objects for links, null for empty repeaters). trim() on
                // non-string is a fatal in PHP 8.1+. Coerce + skip empties.
                if ( ! is_scalar( $text ) ) {
                    continue;
                }
                $text = (string) $text;
                if ( ! empty( trim( strip_tags( $text ) ) ) ) {
                    // Strip leading <h1> from ekit_heading_extra_title if it duplicates ekit_heading_title
                    if ( 'ekit_heading_extra_title' === $field && ! empty( $widget_title ) ) {
                        $text = preg_replace( '/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', $text, 1 );
                    }

                    // Route long content AND raw-HTML widgets to the individual
                    // (chunked) path. HTML widgets carry large markup blobs; batching
                    // several into one JSON request overflows the response and the AI
                    // returns malformed JSON — the whole chunk then fails and those
                    // widgets keep the source language (homepage hero stayed English).
                    // Translating each HTML widget on its own keeps every request small.
                    if ( strlen( $text ) > 3000 || 'html' === $field ) {
                        $long_texts[] = array(
                            'widget_id' => $widget_id,
                            'field'     => $field,
                            'text'      => $text,
                        );
                    } else {
                        $texts_for_ai[] = array(
                            'widget_id' => $widget_id,
                            'field'     => $field,
                            'text'      => $text,
                        );
                    }
                }
            }
        }

        if ( empty( $texts_for_ai ) && empty( $long_texts ) ) {
            return new WP_Error( 'no_text', 'No non-empty translatable text found', array( 'status' => 400 ) );
        }

        // Get source language
        $source_language = LuwiPress_Translation::get_default_language();
        $source_name     = self::LANG_NAMES[ $source_language ] ?? ucfirst( $source_language );
        $target_name     = self::LANG_NAMES[ $target_language ] ?? ucfirst( $target_language );

        $trans_map = array();

        // ── Short texts: batch translate via JSON, CHUNKED ──
        // A full page can carry 80-100+ short strings. Sending them all in ONE
        // dispatch_json call builds a ~16k-token request that routinely exceeds the
        // provider's 60s cURL timeout (cURL error 28) -- which left the whole page
        // untranslated and, worst case, flagged as "no translatable text" so the cron
        // sweep skipped it forever. Splitting into small fixed-size chunks keeps every
        // call well under the timeout, and a slow/failed chunk fails in isolation
        // (partial translation kept) instead of dropping the entire page.
        if ( ! empty( $texts_for_ai ) ) {
            $chunk_size = (int) apply_filters( 'luwipress_elementor_translate_chunk_size', 18 );
            if ( $chunk_size < 1 ) {
                $chunk_size = 18;
            }
            $text_chunks = array_chunk( $texts_for_ai, $chunk_size );
            $chunk_total = count( $text_chunks );
            $chunk_fails = 0;

            foreach ( $text_chunks as $ci => $chunk ) {
                $prompt   = $this->build_translation_prompt( $chunk, $source_name, $target_name );
                $messages = LuwiPress_AI_Engine::build_messages( $prompt );

                $estimated_tokens = max( 2048, intval( array_sum( array_map( function ( $t ) { return strlen( $t['text'] ); }, $chunk ) ) / 3 ) );
                $max_tokens       = min( $estimated_tokens, 8000 );

                $ai_result = LuwiPress_AI_Engine::dispatch_json( 'elementor-translation', $messages, array(
                    'max_tokens' => $max_tokens,
                    'timeout'    => 90,
                ) );

                if ( is_wp_error( $ai_result ) ) {
                    $chunk_fails++;
                    LuwiPress_Logger::log( sprintf(
                        'Elementor translation chunk %d/%d failed: %s',
                        $ci + 1, $chunk_total, $ai_result->get_error_message()
                    ), 'error', array(
                        'post_id'  => $post_id,
                        'language' => $target_language,
                    ) );
                    continue; // isolate the failure to this chunk; keep the rest
                }

                $translations = $ai_result['translations'] ?? $ai_result;
                if ( is_array( $translations ) ) {
                    foreach ( $translations as $item ) {
                        $wid   = $item['widget_id'] ?? '';
                        $field = $item['field'] ?? '';
                        $text  = $item['text'] ?? '';
                        if ( $wid && $field && '' !== (string) $text ) {
                            $trans_map[ $wid ][ $field ] = $text;
                        }
                    }
                }
            }

            if ( $chunk_fails > 0 ) {
                LuwiPress_Logger::log( sprintf(
                    'Elementor translate #%d -> %s: %d/%d text chunks failed (partial translation kept; rerun to fill gaps)',
                    $post_id, $target_language, $chunk_fails, $chunk_total
                ), 'warning' );
            }
        }

        // ── Long texts: chunked translation (plain HTML, not JSON) ──
        foreach ( $long_texts as $lt ) {
            $translated = $this->translate_long_html( $lt['text'], $source_name, $target_name );
            if ( ! is_wp_error( $translated ) && ! empty( $translated ) ) {
                $trans_map[ $lt['widget_id'] ][ $lt['field'] ] = $translated;
                LuwiPress_Logger::log( sprintf( 'Elementor long text translated: widget=%s field=%s len=%d→%d', $lt['widget_id'], $lt['field'], strlen( $lt['text'] ), strlen( $translated ) ), 'info' );
            } else {
                LuwiPress_Logger::log( sprintf( 'Elementor long text FAILED: widget=%s field=%s: %s', $lt['widget_id'], $lt['field'], is_wp_error( $translated ) ? $translated->get_error_message() : 'empty' ), 'warning' );
            }
        }

        // If all AI calls failed, revert and bail
        if ( empty( $trans_map ) ) {
            $this->revert_to_pre_translation_revision( $post_id );
            return new WP_Error( 'translation_failed', 'All AI translation calls failed — reverted to pre-translation state', array( 'status' => 500 ) );
        }

        // ── Coverage gate ──────────────────────────────────────────────────────
        // Everything below writes a SOURCE-derived tree over the target page. When
        // most chunks failed, that tree is mostly source language: publishing it
        // replaces a good translation (and any operator hand-fixes) with English.
        // tapadum, 2026-08-25: one page oscillated DE -> EN -> EN across reruns
        // because "at least one string came back" counted as success.
        $attempted = array_merge( $texts_for_ai, $long_texts );
        $coverage  = self::translation_coverage( $attempted, $trans_map );

        $existing_target_id = $this->find_translation_id( $post_id, $target_language );
        $existing_map       = $this->existing_translation_texts( $existing_target_id );

        $min_coverage = (float) apply_filters(
            'luwipress_elementor_translation_min_coverage',
            self::MIN_TRANSLATION_COVERAGE,
            $post_id,
            $target_language
        );

        if ( $coverage < $min_coverage && ! empty( $existing_map ) ) {
            // Deliberately NOT reverting the source here: nothing was written, and
            // wp_restore_post_revision() would bump the source's post_modified, which
            // marks every language's translation "outdated" on a refusal we expect to
            // happen routinely. Just drop the revision pointer this run created.
            delete_post_meta( $post_id, '_luwipress_pre_translation_revision' );
            LuwiPress_Logger::log( sprintf(
                'Elementor translate #%d -> %s REFUSED: only %.0f%% of %d strings came back (min %.0f%%). Existing translation #%d left untouched — rerun to fill the gaps.',
                $post_id, $target_language, $coverage * 100, count( $attempted ), $min_coverage * 100, $existing_target_id
            ), 'error', array(
                'post_id'       => $post_id,
                'language'      => $target_language,
                'coverage'      => $coverage,
                'translated_id' => $existing_target_id,
            ) );
            return new WP_Error(
                'partial_translation_refused',
                sprintf(
                    'Only %.0f%% of %d strings were translated (minimum %.0f%%). Existing translation #%d was NOT overwritten.',
                    $coverage * 100, count( $attempted ), $min_coverage * 100, $existing_target_id
                ),
                array( 'status' => 409, 'coverage' => $coverage, 'translated_id' => $existing_target_id )
            );
        }

        if ( $coverage < 1.0 ) {
            LuwiPress_Logger::log( sprintf(
                'Elementor translate #%d -> %s: coverage %.0f%% (%d strings). Untranslated fields keep the target page\'s current value.',
                $post_id, $target_language, $coverage * 100, count( $attempted )
            ), 'warning', array( 'post_id' => $post_id, 'language' => $target_language, 'coverage' => $coverage ) );
        }

        // Fields we tried to translate, grouped per widget — the resolution set.
        $attempted_by_widget = array();
        foreach ( $attempted as $item ) {
            $wid = (string) $item['widget_id'];
            $fld = (string) $item['field'];
            if ( '' !== $wid && '' !== $fld ) {
                $attempted_by_widget[ $wid ][] = $fld;
            }
        }

        // Apply translations to a copy of the Elementor data. Structure comes from
        // the source (structural sync is intended); text is resolved per field so a
        // failed string keeps whatever the target already shows instead of reverting
        // to source language.
        $translated_data = $this->walk_elements_modify( $data, function ( &$element ) use ( $trans_map, $existing_map, $attempted_by_widget ) {
            $wid = $element['id'] ?? '';
            if ( '' === $wid ) {
                return;
            }

            $fields = $attempted_by_widget[ $wid ] ?? array();
            foreach ( array_keys( (array) ( $trans_map[ $wid ] ?? array() ) ) as $translated_field ) {
                if ( ! in_array( $translated_field, $fields, true ) ) {
                    $fields[] = $translated_field;
                }
            }
            if ( empty( $fields ) ) {
                return;
            }

            foreach ( $fields as $field ) {
                // Handle repeater items (tabs, accordion, etc.)
                if ( strpos( $field, ':' ) !== false ) {
                    list( $items_key, $index, $sub_field ) = explode( ':', $field, 3 );
                    $index = intval( $index );
                    if ( ! isset( $element['settings'][ $items_key ][ $index ][ $sub_field ] ) ) {
                        continue;
                    }
                    $element['settings'][ $items_key ][ $index ][ $sub_field ] = self::resolve_translated_value(
                        $wid, $field, $trans_map, $existing_map,
                        $element['settings'][ $items_key ][ $index ][ $sub_field ]
                    );
                } else {
                    $element['settings'][ $field ] = self::resolve_translated_value(
                        $wid, $field, $trans_map, $existing_map,
                        $element['settings'][ $field ] ?? ''
                    );
                }
            }
        } );

        // ── Page title ─────────────────────────────────────────────────────────
        // Translate the REAL post title. The old code scavenged the first widget
        // carrying a 'title'-ish field, but 'title' exists on counters, CTAs and
        // image boxes too, and $trans_map has no meaningful order — so a button
        // label could become the page title (and therefore the slug): the Discounts
        // page came out titled "MEHR ANSEHEN" (tapadum, 2026-08-25). Widget
        // scavenging is now only a fallback for genuinely untitled pages, and only
        // from heading widgets.
        $translated_title = '';
        $source_post      = get_post( $post_id );

        if ( ! $source_post || '' === trim( (string) $source_post->post_title ) ) {
            $heading_types = array( 'heading', 'elementskit-heading', 'lwp-section-head' );
            $title_fields  = array( 'title', 'ekit_heading_title', 'heading_title', 'heading' );
            foreach ( $trans_map as $wid => $fields ) {
                if ( ! in_array( $translatable[ $wid ]['type'] ?? '', $heading_types, true ) ) {
                    continue;
                }
                foreach ( $title_fields as $tf ) {
                    if ( ! empty( $fields[ $tf ] ) ) {
                        $translated_title = strip_tags( $fields[ $tf ] );
                        break 2;
                    }
                }
            }
        }

        if ( empty( $translated_title ) && $source_post && ! empty( $source_post->post_title ) ) {
            $ai_title = LuwiPress_AI_Engine::dispatch( 'title-translation', LuwiPress_AI_Engine::build_messages( array(
                'system' => 'You are a translator. Return ONLY the translated text, nothing else.'
                    . LuwiPress_Translation::glossary_prompt_rule(),
                'user'   => sprintf( 'Translate this title from %s to %s: "%s"',
                    $source_name, $target_name, $source_post->post_title ),
            ) ), array( 'max_tokens' => 256 ) );
            // dispatch() returns an array { content, input_tokens, ... }, NOT a bare string.
            // Earlier code did trim($ai_title) directly which threw a PHP fatal:
            // "trim(): Argument #1 must be of type string, array given" -- killed the
            // whole translation pipeline silently from the operator's perspective.
            $title_text = is_array( $ai_title ) ? (string) ( $ai_title['content'] ?? '' ) : '';
            if ( ! is_wp_error( $ai_title ) && ! empty( trim( $title_text ) ) ) {
                $translated_title = strip_tags( trim( $title_text, " \t\n\r\"'." ) );
            } else {
                // Last resort: keep source title (better than empty)
                $translated_title = $source_post->post_title;
                LuwiPress_Logger::log( 'Title translation failed for #' . $post_id . ', keeping source title', 'warning' );
            }
        }

        // Create or update WPML translation
        $result = $this->save_translated_page( $post_id, $target_language, $translated_data );
        if ( is_wp_error( $result ) ) {
            $this->revert_to_pre_translation_revision( $post_id );
            return $result;
        }

        // ALWAYS update title, slug, and excerpt on the translated post
        if ( ! empty( $result['translated_id'] ) ) {
            $tid = $result['translated_id'];
            $update = array( 'ID' => $tid );

            // Title: always set (never leave empty)
            if ( ! empty( $translated_title ) ) {
                $update['post_title'] = $translated_title;
                $update['post_name']  = sanitize_title( $translated_title );
            } elseif ( $source_post ) {
                $update['post_title'] = $source_post->post_title;
                $update['post_name']  = sanitize_title( $source_post->post_title ) . '-' . $target_language;
            }

            // Excerpt: extract from first text-editor widget
            $excerpt_text = '';
            foreach ( $trans_map as $wid => $fields ) {
                foreach ( $fields as $field => $text ) {
                    if ( in_array( $field, array( 'editor', 'description_text', 'testimonial_content' ), true ) && ! empty( $text ) ) {
                        $excerpt_text = wp_trim_words( wp_strip_all_tags( $text ), 30, '...' );
                        break 2;
                    }
                }
            }
            if ( ! empty( $excerpt_text ) ) {
                $update['post_excerpt'] = $excerpt_text;
            }

            wp_update_post( $update );

            // BELT: the wp_update_post above fires WPML's save_post handler, which
            // can re-stamp a freshly created translation as a default-language
            // original with a FRESH trid when WPML's per-request cache predates
            // our icl_translations insert (the 2026-06-10 orphan-page bug -- the
            // row was verified correct at create time, then silently flipped to
            // en + new trid here). Re-assert the registration before returning.
            if ( defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'LuwiPress_Translation' ) ) {
                LuwiPress_Translation::get_instance()->ensure_translation_registration( $tid, $post_id, $target_language );
            }
        }

        $count = count( $texts_for_ai ) + count( $long_texts );
        LuwiPress_Logger::log(
            sprintf( 'Elementor page #%d translated to %s (%d texts)', $post_id, strtoupper( $target_language ), $count ),
            'info',
            array( 'post_id' => $post_id, 'language' => $target_language, 'text_count' => $count )
        );

        return array(
            'status'          => 'completed',
            'post_id'         => $post_id,
            'translated_id'   => $result['translated_id'],
            'target_language' => $target_language,
            'texts_translated' => $count,
        );
    }

    /* ──────────────────── REST Callbacks ─────────────────────────────── */

    /**
     * GET /elementor/page/{post_id}
     */
    public function rest_get_page_data( $request ) {
        $post_id = intval( $request['post_id'] );
        $widgets = $this->get_widget_tree( $post_id );
        if ( is_wp_error( $widgets ) ) {
            return $widgets;
        }

        $post = get_post( $post_id );
        return rest_ensure_response( array(
            'post_id'    => $post_id,
            'title'      => $post->post_title,
            'post_type'  => $post->post_type,
            'widget_count' => count( $widgets ),
            'widgets'    => $widgets,
        ) );
    }

    /**
     * GET /elementor/outline/{post_id} — compact hierarchy with text previews.
     */
    public function rest_get_page_outline( $request ) {
        $post_id = intval( $request['post_id'] );
        $outline = $this->get_page_outline( $post_id );
        if ( is_wp_error( $outline ) ) {
            return $outline;
        }

        $post = get_post( $post_id );
        return rest_ensure_response( array(
            'post_id'   => $post_id,
            'title'     => $post->post_title,
            'post_type' => $post->post_type,
            'structure' => $outline,
        ) );
    }

    /**
     * POST /elementor/translate
     */
    public function rest_translate_page( $request ) {
        $post_id         = intval( $request->get_param( 'post_id' ) );
        $target_language = sanitize_text_field( $request->get_param( 'target_language' ) );

        if ( ! $post_id || ! $target_language ) {
            return new WP_Error( 'missing_params', 'post_id and target_language required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->translate_page( $post_id, $target_language ) );
    }

    /**
     * Pending luwipress_elementor_translate_single events, oldest first.
     *
     * @return array List of ['post_id' => int, 'language' => string, 'timestamp' => int].
     */
    private function pending_translation_events() {
        $cron = _get_cron_array();
        return self::parse_cron_queue( $cron ? $cron : array(), 'luwipress_elementor_translate_single' );
    }

    /**
     * List pending background translation jobs, enriched with per-post state.
     *
     * The queue lives in wp_cron; translation_status only counts the standard
     * (non-Elementor) path's meta, so Elementor jobs were invisible everywhere
     * (tapadum finding 2).
     *
     * @param int    $filter_post_id  Optional post filter (0 = all).
     * @param string $filter_language Optional language filter ('' = all).
     * @return array
     */
    public function list_translation_queue( $filter_post_id = 0, $filter_language = '' ) {
        $pending = $this->pending_translation_events();

        $max_attempts = (int) apply_filters(
            'luwipress_elementor_translation_max_attempts',
            self::MAX_TRANSLATION_ATTEMPTS,
            0,
            ''
        );

        $now     = time();
        $entries = array();
        foreach ( $pending as $entry ) {
            if ( $filter_post_id && $entry['post_id'] !== $filter_post_id ) {
                continue;
            }
            if ( '' !== $filter_language && $entry['language'] !== $filter_language ) {
                continue;
            }

            $status_raw = get_post_meta( $entry['post_id'], '_luwipress_translation_status', true );
            $status     = is_string( $status_raw ) ? json_decode( $status_raw, true ) : null;
            $attempts   = (int) get_post_meta( $entry['post_id'], self::translation_attempt_meta_key( $entry['language'] ), true );

            $entries[] = array(
                'post_id'      => $entry['post_id'],
                'title'        => get_the_title( $entry['post_id'] ),
                'language'     => $entry['language'],
                'scheduled_at' => gmdate( 'c', $entry['timestamp'] ),
                'due_in'       => $entry['timestamp'] - $now,
                'overdue'      => $entry['timestamp'] <= $now,
                'attempts'     => $attempts,
                'max_attempts' => $max_attempts,
                'phase'        => is_array( $status ) ? ( $status['status'] ?? 'queued' ) : 'queued',
                'last_status'  => is_array( $status ) ? $status : null,
            );
        }

        return array(
            'pending'      => count( $entries ),
            'cron_running' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            'entries'      => $entries,
        );
    }

    /**
     * Cancel every pending queue entry for one (post, language) pair.
     *
     * @param int    $post_id  Source post ID.
     * @param string $language Target language code.
     * @return array
     */
    public function cancel_translation_queue_entry( $post_id, $language ) {
        $post_id  = absint( $post_id );
        $language = sanitize_text_field( $language );

        $pending = $this->pending_translation_events();

        $removed = 0;
        foreach ( $pending as $entry ) {
            if ( $entry['post_id'] !== $post_id || $entry['language'] !== $language ) {
                continue;
            }
            wp_unschedule_event( $entry['timestamp'], 'luwipress_elementor_translate_single', array( $post_id, $language ) );
            $removed++;
        }

        // Belt: clears any event whose args differ only by type coercion.
        wp_clear_scheduled_hook( 'luwipress_elementor_translate_single', array( $post_id, $language ) );

        if ( $removed ) {
            update_post_meta( $post_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'   => 'cancelled',
                'language' => $language,
                'finished' => current_time( 'mysql' ),
            ) ) );
        }

        LuwiPress_Logger::log( sprintf(
            'Elementor translate queue: %d job(s) cancelled for #%d → %s',
            $removed, $post_id, $language
        ), 'info', array( 'post_id' => $post_id, 'language' => $language ) );

        return array(
            'status'   => $removed ? 'cancelled' : 'not_queued',
            'post_id'  => $post_id,
            'language' => $language,
            'removed'  => $removed,
        );
    }

    /**
     * GET /elementor/translate-queue
     */
    public function rest_get_translation_queue( $request ) {
        return rest_ensure_response( $this->list_translation_queue(
            absint( $request->get_param( 'post_id' ) ),
            sanitize_text_field( (string) $request->get_param( 'language' ) )
        ) );
    }

    /**
     * POST /elementor/translate-queue/cancel
     */
    public function rest_cancel_translation_queue_entry( $request ) {
        $post_id  = absint( $request->get_param( 'post_id' ) );
        $language = sanitize_text_field( (string) $request->get_param( 'language' ) );

        if ( ! $post_id || '' === $language ) {
            return new WP_Error( 'missing_params', 'post_id and language are required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->cancel_translation_queue_entry( $post_id, $language ) );
    }

    /**
     * POST /elementor/translate-queue
     * Queue multiple Elementor pages for background translation.
     *
     * Body: { "post_ids": [31329, 31691, ...], "target_language": "fr" }
     *   or: { "post_type": "post", "target_language": "fr" } to auto-discover
     */
    public function rest_queue_translations( $request ) {
        $action = sanitize_text_field( $request->get_param( 'action' ) ?: '' );

        // Cancel all pending jobs
        if ( 'cancel' === $action ) {
            $cleared = wp_unschedule_hook( 'luwipress_elementor_translate_single' );
            LuwiPress_Logger::log( 'Elementor translate queue cancelled: ' . $cleared . ' jobs removed', 'info' );
            return rest_ensure_response( array( 'status' => 'cancelled', 'jobs_removed' => $cleared ) );
        }

        $target_language = sanitize_text_field( $request->get_param( 'target_language' ) );
        if ( ! $target_language ) {
            return new WP_Error( 'missing_params', 'target_language required', array( 'status' => 400 ) );
        }

        $post_ids  = $request->get_param( 'post_ids' );
        $post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?: '' );

        if ( ! empty( $post_ids ) && is_array( $post_ids ) ) {
            $source_ids = array_map( 'absint', $post_ids );
        } elseif ( $post_type ) {
            // Auto-discover: find source-language Elementor posts that need translation
            $default_lang = LuwiPress_Translation::get_default_language();
            $posts = get_posts( array(
                'post_type'   => $post_type,
                'post_status' => 'publish',
                'numberposts' => 100,
                'meta_key'    => '_elementor_data',
                'suppress_filters' => false,
            ) );
            $source_ids = array();
            foreach ( $posts as $p ) {
                // Check if translation already exists with own _elementor_data
                $translated_id = apply_filters( 'wpml_object_id', $p->ID, $post_type, false, $target_language );
                if ( $translated_id && $translated_id !== $p->ID ) {
                    $has_own = get_post_meta( $translated_id, '_luwipress_elementor_translated', true );
                    if ( $has_own ) {
                        continue; // Already translated
                    }
                }
                $source_ids[] = $p->ID;
            }
        } else {
            return new WP_Error( 'missing_params', 'post_ids array or post_type required', array( 'status' => 400 ) );
        }

        // Schedule each as a separate wp_cron event with staggered timing
        $queued = 0;
        $delay  = 5; // seconds between jobs
        foreach ( $source_ids as $source_id ) {
            $timestamp = time() + ( $queued * $delay );
            $scheduled = wp_schedule_single_event( $timestamp, 'luwipress_elementor_translate_single', array( $source_id, $target_language ) );
            if ( false !== $scheduled ) {
                $queued++;
            }
        }

        LuwiPress_Logger::log( 'Elementor translate queue: ' . $queued . ' jobs scheduled for ' . $target_language, 'info' );

        return rest_ensure_response( array(
            'status'          => 'queued',
            'queued'          => $queued,
            'target_language' => $target_language,
            'source_ids'      => $source_ids,
        ) );
    }

    /**
     * POST /elementor/widget
     */
    public function rest_set_widget_text( $request ) {
        $post_id   = intval( $request->get_param( 'post_id' ) );
        $widget_id = sanitize_text_field( $request->get_param( 'widget_id' ) );
        $texts     = $request->get_param( 'texts' );

        if ( ! $post_id || ! $widget_id || ! is_array( $texts ) ) {
            return new WP_Error( 'missing_params', 'post_id, widget_id, and texts required', array( 'status' => 400 ) );
        }

        $result = $this->set_widget_text( $post_id, $widget_id, $texts );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'status'    => 'updated',
            'post_id'   => $post_id,
            'widget_id' => $widget_id,
        ) );
    }

    /**
     * POST /elementor/style
     */
    public function rest_set_widget_style( $request ) {
        $post_id   = intval( $request->get_param( 'post_id' ) );
        $widget_id = sanitize_text_field( $request->get_param( 'widget_id' ) );
        $styles    = $request->get_param( 'styles' );

        if ( ! $post_id || ! $widget_id || ! is_array( $styles ) ) {
            return new WP_Error( 'missing_params', 'post_id, widget_id, and styles required', array( 'status' => 400 ) );
        }

        $result = $this->set_widget_style( $post_id, $widget_id, $styles );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'status'    => 'updated',
            'post_id'   => $post_id,
            'widget_id' => $widget_id,
        ) );
    }

    /**
     * POST /elementor/bulk-update
     */
    public function rest_bulk_update( $request ) {
        $post_id = intval( $request->get_param( 'post_id' ) );
        $changes = $request->get_param( 'changes' );

        if ( ! $post_id || ! is_array( $changes ) ) {
            return new WP_Error( 'missing_params', 'post_id and changes array required', array( 'status' => 400 ) );
        }

        $result = $this->bulk_update( $post_id, $changes );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'status'  => 'completed',
            'post_id' => $post_id,
            'results' => $result,
        ) );
    }

    /* ──────────── REST Callbacks — Structural Operations ───────────── */

    public function rest_add_widget( $request ) {
        $post_id      = intval( $request->get_param( 'post_id' ) );
        $container_id = sanitize_text_field( $request->get_param( 'container_id' ) );
        $widget_type  = sanitize_text_field( $request->get_param( 'widget_type' ) );
        $settings     = $request->get_param( 'settings' ) ?: array();
        $position     = intval( $request->get_param( 'position' ) ?? -1 );

        if ( ! $post_id || ! $container_id || ! $widget_type ) {
            return new WP_Error( 'missing_params', 'post_id, container_id, and widget_type required', array( 'status' => 400 ) );
        }

        $result = $this->add_widget( $post_id, $container_id, $widget_type, $settings, $position );
        return rest_ensure_response( is_wp_error( $result ) ? $result : $result );
    }

    public function rest_add_section( $request ) {
        $post_id  = intval( $request->get_param( 'post_id' ) );
        $position = intval( $request->get_param( 'position' ) ?? -1 );
        $settings = $request->get_param( 'settings' ) ?: array();

        if ( ! $post_id ) {
            return new WP_Error( 'missing_params', 'post_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->add_section( $post_id, $position, $settings ) );
    }

    public function rest_delete_element( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) );

        if ( ! $post_id || ! $element_id ) {
            return new WP_Error( 'missing_params', 'post_id and element_id required', array( 'status' => 400 ) );
        }

        $result = $this->delete_element( $post_id, $element_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'status' => 'deleted', 'element_id' => $element_id ) );
    }

    public function rest_move_element( $request ) {
        $post_id       = intval( $request->get_param( 'post_id' ) );
        $element_id    = sanitize_text_field( $request->get_param( 'element_id' ) );
        $target_parent = sanitize_text_field( $request->get_param( 'target_parent' ) ?? '' );
        $position      = intval( $request->get_param( 'position' ) ?? -1 );

        if ( ! $post_id || ! $element_id ) {
            return new WP_Error( 'missing_params', 'post_id and element_id required', array( 'status' => 400 ) );
        }

        $result = $this->move_element( $post_id, $element_id, $target_parent, $position );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'status' => 'moved', 'element_id' => $element_id ) );
    }

    public function rest_clone_element( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) );

        if ( ! $post_id || ! $element_id ) {
            return new WP_Error( 'missing_params', 'post_id and element_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->clone_element( $post_id, $element_id ) );
    }

    /* ──────────── Elementor Kit & Global CSS ──────────────────────────── */

    /**
     * Get the Elementor active kit post ID.
     *
     * @return int Kit post ID, or 0 if not found.
     */
    private function get_kit_id() {
        return (int) get_option( 'elementor_active_kit', 0 );
    }

    /**
     * Get Elementor active breakpoints using the Breakpoints Manager API (3.2+).
     *
     * @return array Breakpoint name → pixel value map.
     */
    private function get_breakpoints() {
        $breakpoints = array(
            'mobile'  => 767,
            'tablet'  => 1024,
        );

        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->breakpoints ) ) {
            $active = \Elementor\Plugin::$instance->breakpoints->get_active_breakpoints();
            foreach ( $active as $name => $bp ) {
                $breakpoints[ $name ] = $bp->get_value();
            }
        }

        return $breakpoints;
    }

    /**
     * Flush Elementor CSS cache — Kit + individual posts.
     *
     * @param int|null $post_id Optional specific post to clear.
     */
    private function flush_elementor_css( $post_id = null ) {
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return;
        }

        // Clear global CSS cache (Kit CSS, global widgets, etc.)
        \Elementor\Plugin::$instance->files_manager->clear_cache();

        // Delete Kit CSS file directly — Elementor Global_CSS handles Kit differently
        $kit_id = $this->get_kit_id();
        if ( $kit_id ) {
            // Delete the Kit post CSS meta
            delete_post_meta( $kit_id, '_elementor_css' );
            delete_post_meta( $kit_id, '_elementor_page_assets' );

            // Use Global_CSS class if available (Elementor 3.x+)
            if ( class_exists( '\Elementor\Core\Files\CSS\Global_CSS' ) ) {
                try {
                    $global_css = new \Elementor\Core\Files\CSS\Global_CSS( 'global.css' );
                    $global_css->update();
                } catch ( \Throwable $e ) {
                    LuwiPress_Logger::log( 'Global CSS update error: ' . $e->getMessage(), 'debug' );
                }
            }

            // Also delete the physical Kit CSS file
            $upload_dir = wp_upload_dir();
            $kit_css_file = $upload_dir['basedir'] . '/elementor/css/post-' . $kit_id . '.css';
            if ( file_exists( $kit_css_file ) ) {
                @unlink( $kit_css_file );
            }
            // Also the global.css
            $global_css_file = $upload_dir['basedir'] . '/elementor/css/global.css';
            if ( file_exists( $global_css_file ) ) {
                @unlink( $global_css_file );
            }

            // Regenerate Kit CSS as a post
            $this->regenerate_css( $kit_id );
        }

        // Clear specific post CSS if provided
        if ( $post_id && $post_id !== $kit_id ) {
            $this->regenerate_css( $post_id );
        }
    }

    /* ── Kit Info ── */

    public function rest_get_kit_info( $request ) {
        $kit_id = $this->get_kit_id();

        if ( ! $kit_id ) {
            return new WP_Error( 'no_kit', 'Elementor active kit not found', array( 'status' => 404 ) );
        }

        $kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        if ( ! is_array( $kit_settings ) ) {
            $kit_settings = array();
        }

        $breakpoints = $this->get_breakpoints();

        return rest_ensure_response( array(
            'kit_id'      => $kit_id,
            'breakpoints' => $breakpoints,
            'custom_css'  => $kit_settings['custom_css'] ?? '',
            'css_length'  => strlen( $kit_settings['custom_css'] ?? '' ),
        ) );
    }

    /* ── Flush CSS (delete all Elementor CSS files) ── */

    public function rest_flush_css( $request ) {
        $upload_dir = wp_upload_dir();
        $css_dir    = $upload_dir['basedir'] . '/elementor/css/';
        $deleted    = array();

        if ( is_dir( $css_dir ) ) {
            $files = glob( $css_dir . '*.css' );
            foreach ( $files as $file ) {
                if ( @unlink( $file ) ) {
                    $deleted[] = basename( $file );
                }
            }
        }

        // Also clear WP options that Elementor uses for CSS caching
        delete_option( '_elementor_global_css' );
        delete_option( 'elementor-custom-breakpoints-files' );

        // Clear all post meta CSS caches
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_css', '_elementor_page_assets')" );

        // Clear Elementor files manager if available
        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            } catch ( \Throwable $e ) {
                // Silently continue
            }
        }

        // Also purge page cache (LiteSpeed, WP Rocket, etc.)
        if ( class_exists( 'LiteSpeed\Purge' ) ) {
            // Purge HTML cache
            do_action( 'litespeed_purge_all' );
            // Purge CSS/JS optimization (combined/minified files) — separate cache
            do_action( 'litespeed_purge_css_js' );
            // Delete physical LiteSpeed CSS/JS optimize files
            $ls_dirs = array(
                WP_CONTENT_DIR . '/litespeed/css/',
                WP_CONTENT_DIR . '/litespeed/js/',
                WP_CONTENT_DIR . '/cache/litespeed/css/',
                WP_CONTENT_DIR . '/cache/litespeed/js/',
            );
            foreach ( $ls_dirs as $ls_dir ) {
                if ( is_dir( $ls_dir ) ) {
                    $ls_files = glob( $ls_dir . '*.css' ) ?: array();
                    foreach ( $ls_files as $lf ) { @unlink( $lf ); }
                    $ls_files = glob( $ls_dir . '*.js' ) ?: array();
                    foreach ( $ls_files as $lf ) { @unlink( $lf ); }
                }
            }
        } elseif ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        } elseif ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }

        LuwiPress_Logger::log( 'Elementor CSS flush: deleted ' . count( $deleted ) . ' files + page cache purged', 'info' );

        return rest_ensure_response( array(
            'status'  => 'flushed',
            'deleted' => $deleted,
            'count'   => count( $deleted ),
        ) );
    }

    /* ── Global CSS (Kit custom_css) ── */

    public function rest_get_global_css( $request ) {
        $kit_id = $this->get_kit_id();

        // Primary: read from our inline option
        $css    = get_option( 'luwipress_kit_css', '' );
        $source = ! empty( $css ) ? 'luwipress_inline' : 'none';

        // Fallback: read from Elementor Kit meta (legacy)
        if ( empty( $css ) && $kit_id ) {
            $kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
            if ( is_array( $kit_settings ) && ! empty( $kit_settings['custom_css'] ) ) {
                $css    = $kit_settings['custom_css'];
                $source = 'elementor_kit';
            }
        }

        return rest_ensure_response( array(
            'css'    => $css,
            'source' => $source,
            'kit_id' => $kit_id,
        ) );
    }

    public function rest_set_global_css( $request ) {
        $css    = $request->get_param( 'css' );
        $append = (bool) $request->get_param( 'append' );

        if ( $css === null ) {
            return new WP_Error( 'missing_params', 'css required', array( 'status' => 400 ) );
        }

        $css = wp_strip_all_tags( $css );

        // Append to existing if requested
        if ( $append ) {
            $existing = get_option( 'luwipress_kit_css', '' );
            if ( ! empty( $existing ) ) {
                $css = $existing . "\n" . $css;
            }
        }

        // PRIMARY: store in our own option — output inline via output_global_css()
        update_option( 'luwipress_kit_css', $css );

        // SECONDARY: clear Elementor Kit's custom_css to prevent @import regeneration
        $kit_id = $this->get_kit_id();
        if ( $kit_id ) {
            $kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
            if ( ! is_array( $kit_settings ) ) {
                $kit_settings = array();
            }
            $kit_settings['custom_css'] = '';
            update_post_meta( $kit_id, '_elementor_page_settings', $kit_settings );
        }

        $this->flush_elementor_css();

        LuwiPress_Logger::log( 'Kit CSS saved inline (luwipress_kit_css option, ' . strlen( $css ) . ' bytes)', 'info' );

        return rest_ensure_response( array(
            'status' => 'updated',
            'source' => 'luwipress_inline',
            'kit_id' => $kit_id,
            'length' => strlen( $css ),
        ) );
    }

    /* ── Batch Page CSS — apply to multiple posts by IDs or post_type ── */

    public function rest_batch_page_css( $request ) {
        $css       = $request->get_param( 'css' );
        $post_ids  = $request->get_param( 'post_ids' );
        $post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?? '' );
        $append    = (bool) $request->get_param( 'append' );

        if ( ! $css ) {
            return new WP_Error( 'missing_params', 'css required', array( 'status' => 400 ) );
        }

        $css = wp_strip_all_tags( $css );

        // Resolve target post IDs
        if ( empty( $post_ids ) && ! empty( $post_type ) ) {
            // Find all Elementor-built posts of this type
            global $wpdb;
            $post_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = %s AND p.post_status = 'publish'
                 AND pm.meta_key = '_elementor_edit_mode' AND pm.meta_value = 'builder'",
                $post_type
            ) );
        }

        if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
            return new WP_Error( 'no_targets', 'No target posts found. Provide post_ids array or post_type.', array( 'status' => 400 ) );
        }

        $updated = array();
        $errors  = array();

        foreach ( $post_ids as $pid ) {
            $pid = intval( $pid );
            if ( ! $pid ) {
                continue;
            }

            $page_settings = get_post_meta( $pid, '_elementor_page_settings', true );
            if ( ! is_array( $page_settings ) ) {
                $page_settings = array();
            }

            if ( $append && ! empty( $page_settings['custom_css'] ) ) {
                $page_settings['custom_css'] = $page_settings['custom_css'] . "\n" . $css;
            } else {
                $page_settings['custom_css'] = $css;
            }

            update_post_meta( $pid, '_elementor_page_settings', $page_settings );
            $this->regenerate_css( $pid );
            $updated[] = $pid;
        }

        LuwiPress_Logger::log( sprintf( 'Batch page CSS applied to %d posts', count( $updated ) ), 'info' );

        return rest_ensure_response( array(
            'status'       => 'updated',
            'updated_ids'  => $updated,
            'total'        => count( $updated ),
        ) );
    }

    /**
     * Output LuwiPress Kit CSS as inline <style> in wp_head.
     *
     * CSS stored in `luwipress_kit_css` option is ALWAYS output inline — no
     * external file, no LiteSpeed combiner interference, no FOUC.
     * Priority 99 keeps it after Elementor's own generated CSS so our rules win.
     */
    public function output_global_css() {
        $css = get_option( 'luwipress_kit_css', '' );
        if ( empty( $css ) ) {
            return;
        }
        echo "\n<style id=\"luwipress-kit-css\">\n" . $css . "\n</style>\n";
    }

    /**
     * Output Google Fonts as a proper <link> tag at priority 1 of wp_head.
     * This avoids the CSS @import-in-middle-of-file browser bug where @import
     * placed after other rules in Elementor's generated post-{kit}.css is ignored.
     */
    public function enqueue_google_fonts() {
        $url = get_option( 'luwipress_google_fonts_url', '' );
        if ( empty( $url ) ) {
            return;
        }
        $url = esc_url( $url );
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        echo '<link rel="stylesheet" href="' . $url . '">' . "\n";
    }

    public function rest_get_google_fonts( $request ) {
        return rest_ensure_response( array(
            'url' => get_option( 'luwipress_google_fonts_url', '' ),
        ) );
    }

    public function rest_set_google_fonts( $request ) {
        $url = sanitize_text_field( $request->get_param( 'url' ) ?? '' );
        update_option( 'luwipress_google_fonts_url', $url );
        return rest_ensure_response( array(
            'status' => 'updated',
            'url'    => $url,
        ) );
    }

    public function rest_get_print_method( $request ) {
        return rest_ensure_response( array(
            'method' => get_option( 'elementor_css_print_method', 'external' ),
        ) );
    }

    public function rest_set_print_method( $request ) {
        $method = sanitize_text_field( $request->get_param( 'method' ) ?? 'internal' );
        if ( ! in_array( $method, array( 'internal', 'external' ), true ) ) {
            return new WP_Error( 'invalid_method', 'method must be internal or external', array( 'status' => 400 ) );
        }
        update_option( 'elementor_css_print_method', $method );
        return rest_ensure_response( array(
            'status' => 'updated',
            'method' => $method,
        ) );
    }

    /* ──────────── Reorder top-level sections ──────────── */

    public function rest_reorder_sections( $request ) {
        $post_id = intval( $request->get_param( 'post_id' ) );
        $order   = $request->get_param( 'order' );

        if ( ! $post_id || ! is_array( $order ) || empty( $order ) ) {
            return new WP_Error( 'missing_params', 'post_id and order array required', array( 'status' => 400 ) );
        }
        $order = array_map( 'sanitize_text_field', $order );
        if ( count( $order ) !== count( array_unique( $order ) ) ) {
            return new WP_Error( 'duplicate_ids', 'order contains duplicate IDs', array( 'status' => 400 ) );
        }

        $result = $this->reorder_sections( $post_id, $order );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function reorder_sections( $post_id, array $order ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $top_map = array();
        foreach ( $data as $element ) {
            $id = $element['id'] ?? '';
            if ( $id ) {
                $top_map[ $id ] = $element;
            }
        }

        $missing = array_diff( $order, array_keys( $top_map ) );
        $extra   = array_diff( array_keys( $top_map ), $order );
        if ( ! empty( $missing ) || ! empty( $extra ) ) {
            return new WP_Error( 'order_mismatch', 'order IDs do not match top-level elements', array(
                'status'      => 400,
                'missing_ids' => array_values( $missing ),
                'extra_ids'   => array_values( $extra ),
            ) );
        }

        $snapshot = $this->create_snapshot( $post_id, 'Pre-reorder backup' );
        $snapshot_id = is_wp_error( $snapshot ) ? '' : ( $snapshot['snapshot_id'] ?? '' );

        $new_data = array();
        foreach ( $order as $id ) {
            $new_data[] = $top_map[ $id ];
        }

        $saved = $this->save_elementor_data( $post_id, $new_data );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        LuwiPress_Logger::log( sprintf( 'Reordered %d sections on post #%d', count( $order ), $post_id ), 'info' );

        return array(
            'status'        => 'reordered',
            'post_id'       => $post_id,
            'section_count' => count( $order ),
            'new_order'     => $order,
            'snapshot_id'   => $snapshot_id,
        );
    }

    /* ──────────── Find and Replace across pages ──────────── */

    public function rest_find_replace( $request ) {
        $post_ids  = $request->get_param( 'post_ids' );
        $post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?? '' );
        $find      = $request->get_param( 'find' );
        $replace   = $request->get_param( 'replace' );
        $scope     = sanitize_text_field( $request->get_param( 'scope' ) ?? 'text' );
        $is_regex  = (bool) $request->get_param( 'is_regex' );
        $dry_run   = (bool) $request->get_param( 'dry_run' );
        $style_key = sanitize_text_field( $request->get_param( 'style_key' ) ?? '' );

        if ( $find === null || $find === '' ) {
            return new WP_Error( 'missing_params', 'find parameter required', array( 'status' => 400 ) );
        }
        if ( $replace === null ) {
            return new WP_Error( 'missing_params', 'replace parameter required', array( 'status' => 400 ) );
        }
        if ( ! in_array( $scope, self::FIND_REPLACE_SCOPES, true ) ) {
            return new WP_Error(
                'invalid_scope',
                'scope must be one of: ' . implode( ', ', self::FIND_REPLACE_SCOPES ),
                array( 'status' => 400 )
            );
        }
        if ( $is_regex ) {
            // @ suppresses preg warning to evaluate pattern validity
            if ( @preg_match( $find, '' ) === false ) {
                return new WP_Error( 'invalid_regex', 'find is not a valid regex pattern', array( 'status' => 400 ) );
            }
        }

        $ids = $this->resolve_post_ids( $post_ids, $post_type );
        if ( is_wp_error( $ids ) ) {
            return $ids;
        }

        $results = array();
        $total_replacements = 0;
        $pages_modified = 0;

        foreach ( $ids as $pid ) {
            $res = $this->find_replace_on_post( $pid, $find, $replace, $scope, $is_regex, $dry_run, $style_key );
            if ( is_wp_error( $res ) ) {
                $results[ $pid ] = array( 'status' => 'error', 'message' => $res->get_error_message() );
                continue;
            }
            $results[ $pid ] = $res;
            $total_replacements += $res['replacements'] ?? 0;
            if ( ! empty( $res['replacements'] ) ) {
                $pages_modified++;
            }
        }

        return rest_ensure_response( array(
            'status'             => 'completed',
            'dry_run'            => $dry_run,
            'find'               => $find,
            'replace'            => $replace,
            'scope'              => $scope,
            'results'            => $results,
            'total_replacements' => $total_replacements,
            'pages_modified'     => $pages_modified,
        ) );
    }

    private function find_replace_on_post( $post_id, $find, $replace, $scope, $is_regex, $dry_run, $style_key = '' ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $count = 0;
        // 'both' stays text+styles for back-compat; 'all' adds link controls, and
        // 'links' targets them alone (tapadum 2026-08-28: link URLs were reachable
        // from no scope at all).
        $do_text   = in_array( $scope, array( 'text', 'both', 'all' ), true );
        $do_styles = in_array( $scope, array( 'styles', 'both', 'all' ), true );
        $do_links  = in_array( $scope, array( 'links', 'all' ), true );

        $this->walk_elements_modify( $data, function( &$element ) use ( $find, $replace, $is_regex, $do_text, $do_styles, $do_links, $style_key, &$count ) {
            if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
                return;
            }

            $widget_type = $element['widgetType'] ?? '';
            $el_type     = $element['elType'] ?? '';

            // Text scope: replace in known text fields
            if ( $do_text && $el_type === 'widget' && $widget_type ) {
                $text_fields = $this->extract_widget_texts( $widget_type, $element['settings'] );
                foreach ( $text_fields as $key => $value ) {
                    if ( ! is_string( $value ) ) {
                        continue;
                    }
                    $new = $is_regex
                        ? preg_replace( $find, $replace, $value, -1, $n )
                        : str_replace( $find, $replace, $value, $n );
                    if ( $n > 0 && $new !== null ) {
                        // $key can be a repeater path ("tabs:0:tab_title") — assigning
                        // it flat would write a key the renderer ignores.
                        if ( ! is_wp_error( self::apply_widget_setting( $element['settings'], $key, $new ) ) ) {
                            $count += $n;
                        }
                    }
                }
            }

            // Styles scope: replace in scalar string settings (colors, font names, sizes, etc.)
            if ( $do_styles ) {
                foreach ( $element['settings'] as $key => $value ) {
                    if ( ! is_string( $value ) ) {
                        continue;
                    }
                    if ( $style_key !== '' && $key !== $style_key ) {
                        continue;
                    }
                    $new = $is_regex
                        ? preg_replace( $find, $replace, $value, -1, $n )
                        : str_replace( $find, $replace, $value, $n );
                    if ( $n > 0 && $new !== null ) {
                        $element['settings'][ $key ] = $new;
                        $count += $n;
                    }
                }
            }

            // Links scope: link controls are ARRAYS ({url, is_external, nofollow}),
            // top level and inside repeater rows, so neither scope above reaches them.
            if ( $do_links ) {
                $count += self::replace_in_link_fields(
                    $element['settings'],
                    function ( $url ) use ( $find, $replace, $is_regex ) {
                        $out = $is_regex
                            ? preg_replace( $find, $replace, $url )
                            : str_replace( $find, $replace, $url );
                        return null === $out ? $url : $out;
                    }
                );
            }
        } );

        if ( $count > 0 && ! $dry_run ) {
            $this->create_snapshot( $post_id, 'Pre-find-replace backup' );
            $saved = $this->save_elementor_data( $post_id, $data );
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }
        }

        return array(
            'replacements' => $count,
            'status'       => $count > 0 ? ( $dry_run ? 'would_update' : 'updated' ) : 'no_changes',
        );
    }

    /* ──────────── Sync page structure to WPML translations ──────────── */

    public function rest_sync_structure( $request ) {
        $source_id     = intval( $request->get_param( 'source_id' ) );
        $target_ids    = $request->get_param( 'target_ids' );
        $preserve_text = $request->get_param( 'preserve_text' );
        $preserve_text = ( $preserve_text === null ) ? true : (bool) $preserve_text;

        if ( ! $source_id ) {
            return new WP_Error( 'missing_params', 'source_id required', array( 'status' => 400 ) );
        }

        $source_data = $this->get_elementor_data( $source_id );
        if ( is_wp_error( $source_data ) ) {
            return $source_data;
        }

        if ( ! is_array( $target_ids ) || empty( $target_ids ) ) {
            $translations = $this->get_translation_ids( $source_id );
            $target_ids   = array_values( $translations );
        }
        $target_ids = array_map( 'intval', $target_ids );
        $target_ids = array_filter( $target_ids, function( $tid ) use ( $source_id ) {
            return $tid > 0 && $tid !== $source_id;
        } );

        if ( empty( $target_ids ) ) {
            return new WP_Error( 'no_targets', 'No translation targets found', array( 'status' => 400 ) );
        }

        $results = array();
        foreach ( $target_ids as $target_id ) {
            $res = $this->sync_structure_to_target( $source_id, $target_id, $source_data, $preserve_text );
            if ( is_wp_error( $res ) ) {
                $results[ $target_id ] = array( 'status' => 'error', 'message' => $res->get_error_message() );
                continue;
            }
            $results[ $target_id ] = $res;
        }

        return rest_ensure_response( array(
            'status'    => 'completed',
            'source_id' => $source_id,
            'results'   => $results,
        ) );
    }

    private function sync_structure_to_target( $source_id, $target_id, array $source_data, $preserve_text ) {
        // Build text map from target BEFORE overwriting
        $text_map = array();
        if ( $preserve_text ) {
            $target_data = $this->get_elementor_data( $target_id );
            if ( ! is_wp_error( $target_data ) ) {
                $text_map = $this->build_text_map_by_position( $target_data );
            }
        }

        // Snapshot target
        $snapshot = $this->create_snapshot( $target_id, 'Pre-structure-sync backup' );
        $snapshot_id = is_wp_error( $snapshot ) ? '' : ( $snapshot['snapshot_id'] ?? '' );

        // Deep clone source with new IDs
        $cloned = $this->deep_clone_tree( $source_data );

        // Re-apply target's texts at matching positions
        $preserved = 0;
        if ( $preserve_text && ! empty( $text_map ) ) {
            $preserved = $this->apply_text_map_to_tree( $cloned, $text_map );
        }

        // Save
        $saved = $this->save_elementor_data( $target_id, $cloned );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        // Stamp source's modified_gmt so this target is no longer "outdated".
        $src_modified = get_post_field( 'post_modified_gmt', $source_id );
        if ( $src_modified ) {
            update_post_meta( $target_id, '_luwipress_synced_source_modified', $src_modified );
        }

        LuwiPress_Logger::log( sprintf( 'Synced structure #%d -> #%d (%d texts preserved)', $source_id, $target_id, $preserved ), 'info' );

        return array(
            'status'           => 'synced',
            'sections_synced'  => count( $cloned ),
            'texts_preserved'  => $preserved,
            'snapshot_id'      => $snapshot_id,
        );
    }

    /* ──────────── CSS Vars (design tokens) ──────────── */

    public function rest_get_css_vars( $request ) {
        $kit_id = $this->get_kit_id();
        if ( ! $kit_id ) {
            return new WP_Error( 'no_kit', 'Elementor kit not found', array( 'status' => 404 ) );
        }
        $kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        if ( ! is_array( $kit_settings ) ) {
            $kit_settings = array();
        }

        return rest_ensure_response( array(
            'kit_id'                => $kit_id,
            'system_colors'         => $kit_settings['system_colors']       ?? array(),
            'custom_colors'         => $kit_settings['custom_colors']       ?? array(),
            'system_typography'     => $kit_settings['system_typography']   ?? array(),
            'custom_typography'     => $kit_settings['custom_typography']   ?? array(),
            'default_generic_fonts' => $kit_settings['default_generic_fonts'] ?? '',
        ) );
    }

    public function rest_set_css_vars( $request ) {
        $kit_id = $this->get_kit_id();
        if ( ! $kit_id ) {
            return new WP_Error( 'no_kit', 'Elementor kit not found', array( 'status' => 404 ) );
        }

        $system_colors     = $request->get_param( 'system_colors' );
        $custom_colors     = $request->get_param( 'custom_colors' );
        $system_typography = $request->get_param( 'system_typography' );
        $custom_typography = $request->get_param( 'custom_typography' );

        if ( ! is_array( $system_colors ) && ! is_array( $custom_colors ) &&
             ! is_array( $system_typography ) && ! is_array( $custom_typography ) ) {
            return new WP_Error( 'missing_params', 'Provide at least one token array', array( 'status' => 400 ) );
        }

        $kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        if ( ! is_array( $kit_settings ) ) {
            $kit_settings = array();
        }

        $this->create_snapshot( $kit_id, 'Pre-css-vars-update backup' );

        $colors_updated = 0;
        $colors_added   = 0;
        $typo_updated   = 0;
        $typo_added     = 0;

        if ( is_array( $system_colors ) ) {
            list( $kit_settings['system_colors'], $u, $a ) = $this->upsert_token_array( $kit_settings['system_colors'] ?? array(), $system_colors );
            $colors_updated += $u;
            $colors_added   += $a;
        }
        if ( is_array( $custom_colors ) ) {
            // Ensure new custom colors get an _id
            foreach ( $custom_colors as &$c ) {
                if ( is_array( $c ) && empty( $c['_id'] ) ) {
                    $c['_id'] = $this->generate_element_id();
                }
            }
            unset( $c );
            list( $kit_settings['custom_colors'], $u, $a ) = $this->upsert_token_array( $kit_settings['custom_colors'] ?? array(), $custom_colors );
            $colors_updated += $u;
            $colors_added   += $a;
        }
        if ( is_array( $system_typography ) ) {
            list( $kit_settings['system_typography'], $u, $a ) = $this->upsert_token_array( $kit_settings['system_typography'] ?? array(), $system_typography );
            $typo_updated += $u;
            $typo_added   += $a;
        }
        if ( is_array( $custom_typography ) ) {
            foreach ( $custom_typography as &$t ) {
                if ( is_array( $t ) && empty( $t['_id'] ) ) {
                    $t['_id'] = $this->generate_element_id();
                }
            }
            unset( $t );
            list( $kit_settings['custom_typography'], $u, $a ) = $this->upsert_token_array( $kit_settings['custom_typography'] ?? array(), $custom_typography );
            $typo_updated += $u;
            $typo_added   += $a;
        }

        update_post_meta( $kit_id, '_elementor_page_settings', $kit_settings );
        $this->flush_elementor_css();

        LuwiPress_Logger::log( sprintf( 'CSS vars updated: %d colors, %d typography', $colors_updated + $colors_added, $typo_updated + $typo_added ), 'info' );

        return rest_ensure_response( array(
            'status'             => 'updated',
            'kit_id'             => $kit_id,
            'colors_updated'     => $colors_updated,
            'colors_added'       => $colors_added,
            'typography_updated' => $typo_updated,
            'typography_added'   => $typo_added,
        ) );
    }

    /* ──────────── Templates ──────────── */

    public function rest_list_templates( $request ) {
        $type     = sanitize_text_field( $request->get_param( 'type' ) ?? '' );
        $per_page = min( 100, max( 1, intval( $request->get_param( 'per_page' ) ?? 50 ) ) );
        $page     = max( 1, intval( $request->get_param( 'page' ) ?? 1 ) );
        $search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );

        $args = array(
            'post_type'      => 'elementor_library',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );
        if ( $type ) {
            $args['tax_query'] = array( array(
                'taxonomy' => 'elementor_library_type',
                'field'    => 'slug',
                'terms'    => $type,
            ) );
        }
        if ( $search ) {
            $args['s'] = $search;
        }

        $query = new WP_Query( $args );
        $templates = array();

        foreach ( $query->posts as $post ) {
            $template_type = get_post_meta( $post->ID, '_elementor_template_type', true );
            $thumbnail     = get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: '';
            $has_data      = ! empty( get_post_meta( $post->ID, '_elementor_data', true ) );

            $templates[] = array(
                'id'                 => $post->ID,
                'title'              => $post->post_title,
                'type'               => $template_type ?: 'page',
                'date_modified'      => mysql2date( 'c', $post->post_modified ),
                'thumbnail'          => $thumbnail,
                'has_elementor_data' => $has_data,
            );
        }

        return rest_ensure_response( array(
            'templates'    => $templates,
            'total'        => intval( $query->found_posts ),
            'pages'        => intval( $query->max_num_pages ),
            'current_page' => $page,
        ) );
    }

    public function rest_apply_template( $request ) {
        $post_id     = intval( $request->get_param( 'post_id' ) );
        $template_id = intval( $request->get_param( 'template_id' ) );
        $position    = intval( $request->get_param( 'position' ) ?? -1 );
        $replace_all = (bool) $request->get_param( 'replace_all' );

        if ( ! $post_id || ! $template_id ) {
            return new WP_Error( 'missing_params', 'post_id and template_id required', array( 'status' => 400 ) );
        }

        $result = $this->apply_template( $post_id, $template_id, $position, $replace_all );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function apply_template( $post_id, $template_id, $position = -1, $replace_all = false ) {
        $target_post = get_post( $post_id );
        if ( ! $target_post ) {
            return new WP_Error( 'not_found', 'Target post not found', array( 'status' => 404 ) );
        }

        $template_post = get_post( $template_id );
        if ( ! $template_post || $template_post->post_type !== 'elementor_library' ) {
            return new WP_Error( 'template_not_found', 'Template not found', array( 'status' => 404 ) );
        }

        $template_data = $this->get_elementor_data( $template_id );
        if ( is_wp_error( $template_data ) ) {
            return new WP_Error( 'template_empty', 'Template has no Elementor data', array( 'status' => 404 ) );
        }

        // Read existing target data (may be empty)
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        $target_data = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : array();
        if ( ! is_array( $target_data ) ) {
            $target_data = array();
        }

        $snapshot = $this->create_snapshot( $post_id, 'Pre-apply-template backup' );
        $snapshot_id = is_wp_error( $snapshot ) ? '' : ( $snapshot['snapshot_id'] ?? '' );

        // Clone template with fresh IDs
        $cloned = $this->deep_clone_tree( $template_data );

        if ( $replace_all ) {
            $new_data = $cloned;
        } else {
            if ( $position < 0 || $position >= count( $target_data ) ) {
                $new_data = array_merge( $target_data, $cloned );
            } else {
                $new_data = $target_data;
                array_splice( $new_data, $position, 0, $cloned );
            }
        }

        $saved = $this->save_elementor_data( $post_id, $new_data );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        // Ensure edit mode is builder
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

        LuwiPress_Logger::log( sprintf( 'Applied template #%d -> post #%d at position %d', $template_id, $post_id, $position ), 'info' );

        return array(
            'status'            => 'applied',
            'post_id'           => $post_id,
            'template_id'       => $template_id,
            'template_title'    => $template_post->post_title,
            'elements_inserted' => count( $cloned ),
            'position'          => $position,
            'total_sections'    => count( $new_data ),
            'snapshot_id'       => $snapshot_id,
        );
    }

    /* ──────────── Page-level Custom CSS ──────────── */

    public function rest_set_custom_css( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) ?? '' );
        $css        = $request->get_param( 'css' );

        if ( ! $post_id || ! $css ) {
            return new WP_Error( 'missing_params', 'post_id and css required', array( 'status' => 400 ) );
        }

        // Page-level CSS if no element_id
        if ( empty( $element_id ) ) {
            $result = $this->set_page_css( $post_id, $css );
        } else {
            $result = $this->set_custom_css( $post_id, $element_id, $css );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'status' => 'updated', 'target' => $element_id ?: 'page' ) );
    }

    public function rest_set_responsive( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) );
        $device     = sanitize_text_field( $request->get_param( 'device' ) );
        $styles     = $request->get_param( 'styles' );

        if ( ! $post_id || ! $element_id || ! $device || ! is_array( $styles ) ) {
            return new WP_Error( 'missing_params', 'post_id, element_id, device (mobile/tablet), and styles required', array( 'status' => 400 ) );
        }

        $result = $this->set_responsive_style( $post_id, $element_id, $device, $styles );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'status' => 'updated', 'element_id' => $element_id, 'device' => $device ) );
    }

    public function rest_global_style( $request ) {
        $post_id     = intval( $request->get_param( 'post_id' ) );
        $widget_type = sanitize_text_field( $request->get_param( 'widget_type' ) );
        $styles      = $request->get_param( 'styles' );

        if ( ! $post_id || ! $widget_type || ! is_array( $styles ) ) {
            return new WP_Error( 'missing_params', 'post_id, widget_type, and styles required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->apply_global_style( $post_id, $widget_type, $styles ) );
    }

    /* ──────────── REST Callbacks — Sync, Audit, Revision ────────────── */

    public function rest_sync_styles( $request ) {
        $source_id  = intval( $request->get_param( 'source_id' ) );
        $target_ids = $request->get_param( 'target_ids' );
        $options    = $request->get_param( 'options' ) ?: array();

        if ( ! $source_id ) {
            return new WP_Error( 'missing_params', 'source_id required', array( 'status' => 400 ) );
        }

        // Auto-discover targets if not provided
        if ( empty( $target_ids ) ) {
            $translations = $this->get_translation_ids( $source_id );
            if ( empty( $translations ) ) {
                return new WP_Error( 'no_targets', 'No translation pages found and no target_ids provided', array( 'status' => 400 ) );
            }
            $target_ids = array_values( $translations );
        }

        if ( ! is_array( $target_ids ) ) {
            $target_ids = array( intval( $target_ids ) );
        }

        // Auto-snapshot targets before sync
        foreach ( $target_ids as $tid ) {
            $this->create_snapshot( intval( $tid ), 'Pre-sync backup' );
        }

        return rest_ensure_response( $this->sync_styles( $source_id, $target_ids, $options ) );
    }

    public function rest_audit_spacing( $request ) {
        $post_id = intval( $request['post_id'] );
        return rest_ensure_response( $this->audit_spacing( $post_id ) );
    }

    public function rest_create_snapshot( $request ) {
        $post_id = intval( $request->get_param( 'post_id' ) );
        $label   = sanitize_text_field( $request->get_param( 'label' ) ?? '' );

        if ( ! $post_id ) {
            return new WP_Error( 'missing_params', 'post_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->create_snapshot( $post_id, $label ) );
    }

    public function rest_rollback( $request ) {
        $post_id     = intval( $request->get_param( 'post_id' ) );
        $snapshot_id = sanitize_text_field( $request->get_param( 'snapshot_id' ) ?? '' );

        if ( ! $post_id ) {
            return new WP_Error( 'missing_params', 'post_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->rollback_snapshot( $post_id, $snapshot_id ) );
    }

    public function rest_list_snapshots( $request ) {
        $post_id = intval( $request['post_id'] );
        return rest_ensure_response( $this->list_snapshots( $post_id ) );
    }

    public function rest_auto_fix( $request ) {
        $post_id = intval( $request->get_param( 'post_id' ) );
        $options = $request->get_param( 'options' ) ?: array();

        if ( ! $post_id ) {
            return new WP_Error( 'missing_params', 'post_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->auto_fix_spacing( $post_id, $options ) );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  THEME BUILDER TEMPLATE MANAGEMENT (3.1.42-hotfix4)
     *  ═══════════════════════════════════════════════════════════════════
     *  Lets MCP clients enumerate, create, clone, and configure Elementor
     *  Pro Theme Builder templates (header/footer/single-post/archive/etc).
     *  Bridges the gap between LuwiPress AI workflows and Elementor Pro's
     *  template system so AI agents can scaffold full template hierarchies
     *  for new sites or backfill missing templates on existing ones.
     */

    private static function template_type_whitelist() {
        return array(
            'page', 'section', 'widget', 'kit',
            'header', 'footer', 'popup',
            'single', 'single-post', 'single-page',
            'product', 'single-product',
            'archive', 'product-archive', 'search-results',
            'single-404', 'cart', 'checkout', 'my-account',
        );
    }

    public function rest_templates_list( $request ) {
        $type   = sanitize_text_field( $request->get_param( 'type' ) ?? '' );
        $status = sanitize_text_field( $request->get_param( 'status' ) ?: 'any' );
        $limit  = max( 1, min( intval( $request->get_param( 'limit' ) ?: 100 ), 500 ) );

        if ( ! post_type_exists( 'elementor_library' ) ) {
            return new WP_Error( 'no_elementor_pro', 'elementor_library post type missing — Elementor Pro required for template features', array( 'status' => 412 ) );
        }

        $args = array(
            'post_type'      => 'elementor_library',
            'post_status'    => $status === 'any' ? array( 'publish', 'draft', 'pending', 'private' ) : $status,
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );
        if ( $type !== '' ) {
            $args['meta_query'] = array(
                array( 'key' => '_elementor_template_type', 'value' => $type ),
            );
        }

        $q = new WP_Query( $args );
        $items = array();
        foreach ( $q->posts as $p ) {
            $tpl_type   = get_post_meta( $p->ID, '_elementor_template_type', true );
            $conditions = get_post_meta( $p->ID, '_elementor_conditions', true );
            $items[] = array(
                'id'          => $p->ID,
                'title'       => $p->post_title,
                'slug'        => $p->post_name,
                'status'      => $p->post_status,
                'template_type' => $tpl_type ?: '',
                'conditions'  => is_array( $conditions ) ? $conditions : array(),
                'modified'    => $p->post_modified,
                'edit_url'    => admin_url( 'post.php?post=' . $p->ID . '&action=elementor' ),
            );
        }

        return rest_ensure_response( array(
            'count'     => count( $items ),
            'templates' => $items,
        ) );
    }

    public function rest_template_create( $request ) {
        $title         = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
        $template_type = sanitize_text_field( $request->get_param( 'template_type' ) ?? '' );
        $status        = sanitize_text_field( $request->get_param( 'status' ) ?: 'draft' );
        $conditions    = $request->get_param( 'conditions' );  // optional array
        $copy_from     = intval( $request->get_param( 'copy_from' ) ?: 0 );  // optional source template ID
        $data          = $request->get_param( 'data' );  // optional Elementor data array

        if ( ! $title || ! $template_type ) {
            return new WP_Error( 'missing_params', 'title and template_type required', array( 'status' => 400 ) );
        }
        if ( ! in_array( $template_type, self::template_type_whitelist(), true ) ) {
            return new WP_Error( 'invalid_type', 'template_type not in whitelist: ' . implode( ',', self::template_type_whitelist() ), array( 'status' => 400 ) );
        }
        if ( ! post_type_exists( 'elementor_library' ) ) {
            return new WP_Error( 'no_elementor_pro', 'Elementor Pro required', array( 'status' => 412 ) );
        }

        // If copy_from provided, source the data + type from it
        if ( $copy_from ) {
            $src = get_post( $copy_from );
            if ( ! $src || $src->post_type !== 'elementor_library' ) {
                return new WP_Error( 'invalid_source', 'copy_from is not a valid elementor_library post', array( 'status' => 400 ) );
            }
            global $wpdb;
            $data_raw = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
                $copy_from
            ) );
        } elseif ( is_array( $data ) ) {
            $data_raw = wp_slash( wp_json_encode( $data ) );
        } else {
            // Empty starter
            $data_raw = wp_slash( '[]' );
        }

        // Create the post
        $insert_result = wp_insert_post( array(
            'post_title'  => $title,
            'post_status' => $status,
            'post_type'   => 'elementor_library',
        ), true );

        if ( is_wp_error( $insert_result ) ) {
            return new WP_Error( 'create_failed', 'wp_insert_post failed: ' . $insert_result->get_error_message(), array( 'status' => 500 ) );
        }
        $new_id = (int) $insert_result;
        // wp_insert_post with throw-on-error returns either WP_Error or a positive int,
        // so an explicit zero-check would be dead code per PHPStan; the WP_Error guard
        // above already covers the failure path.

        // Set Elementor type metas — these are what Theme Builder uses
        update_post_meta( $new_id, '_elementor_template_type', $template_type );
        update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $new_id, '_wp_page_template', 'default' );

        // Write data via direct wpdb (preserve slashing)
        if ( $data_raw ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, '_elementor_data', %s)",
                $new_id, $data_raw
            ) );
        }

        // Set library type taxonomy if it exists
        if ( taxonomy_exists( 'elementor_library_type' ) ) {
            wp_set_object_terms( $new_id, $template_type, 'elementor_library_type' );
        }

        // Set conditions if provided
        if ( is_array( $conditions ) && ! empty( $conditions ) ) {
            $clean_conds = array_values( array_filter( array_map( 'sanitize_text_field', $conditions ) ) );
            update_post_meta( $new_id, '_elementor_conditions', $clean_conds );
        }

        wp_cache_delete( $new_id, 'post_meta' );

        LuwiPress_Logger::log( sprintf(
            'Theme Builder template created: #%d (%s) type=%s status=%s%s',
            $new_id, $title, $template_type, $status,
            $copy_from ? ' copy_from=' . $copy_from : ''
        ), 'info' );

        return rest_ensure_response( array(
            'status'        => 'created',
            'id'            => $new_id,
            'title'         => $title,
            'template_type' => $template_type,
            'post_status'   => $status,
            'edit_url'      => admin_url( 'post.php?post=' . $new_id . '&action=elementor' ),
            'conditions'    => is_array( $conditions ) ? $conditions : array(),
            'copied_from'   => $copy_from ?: null,
        ) );
    }

    public function rest_template_clone( $request ) {
        $source_id  = intval( $request->get_param( 'source_id' ) ?? 0 );
        $new_title  = sanitize_text_field( $request->get_param( 'new_title' ) ?? '' );

        if ( ! $source_id || ! $new_title ) {
            return new WP_Error( 'missing_params', 'source_id and new_title required', array( 'status' => 400 ) );
        }
        $src = get_post( $source_id );
        if ( ! $src || $src->post_type !== 'elementor_library' ) {
            return new WP_Error( 'invalid_source', 'source_id is not an elementor_library post', array( 'status' => 400 ) );
        }

        $tpl_type = get_post_meta( $source_id, '_elementor_template_type', true ) ?: 'page';

        // Use template_create with copy_from
        $new_request = new WP_REST_Request( 'POST', '' );
        $new_request->set_param( 'title', $new_title );
        $new_request->set_param( 'template_type', $tpl_type );
        $new_request->set_param( 'status', 'draft' );
        $new_request->set_param( 'copy_from', $source_id );

        return $this->rest_template_create( $new_request );
    }

    public function rest_template_conditions_get( $request ) {
        $template_id = intval( $request->get_param( 'template_id' ) ?? 0 );
        if ( ! $template_id ) {
            return new WP_Error( 'missing_params', 'template_id required', array( 'status' => 400 ) );
        }
        $conds = get_post_meta( $template_id, '_elementor_conditions', true );
        return rest_ensure_response( array(
            'template_id' => $template_id,
            'conditions'  => is_array( $conds ) ? $conds : array(),
        ) );
    }

    public function rest_template_conditions_set( $request ) {
        $template_id = intval( $request->get_param( 'template_id' ) ?? 0 );
        $conditions  = $request->get_param( 'conditions' );

        if ( ! $template_id ) {
            return new WP_Error( 'missing_params', 'template_id required', array( 'status' => 400 ) );
        }
        if ( ! is_array( $conditions ) ) {
            return new WP_Error( 'invalid_conditions', 'conditions must be an array of strings (e.g. ["include/general","include/post"])', array( 'status' => 400 ) );
        }

        $tpl = get_post( $template_id );
        if ( ! $tpl || $tpl->post_type !== 'elementor_library' ) {
            return new WP_Error( 'not_found', 'Template not found', array( 'status' => 404 ) );
        }

        $clean = array_values( array_filter( array_map( 'sanitize_text_field', $conditions ) ) );
        update_post_meta( $template_id, '_elementor_conditions', $clean );

        // Pro-free by design: do not call into Elementor Pro's Conditions_Cache.
        // Template conditions are persisted as meta; the standard Elementor template
        // resolution chain picks them up on next request.

        LuwiPress_Logger::log( sprintf(
            'Template conditions updated: #%d %s',
            $template_id, implode( ',', $clean )
        ), 'info' );

        return rest_ensure_response( array(
            'status'      => 'updated',
            'template_id' => $template_id,
            'conditions'  => $clean,
        ) );
    }

    public function rest_template_delete( $request ) {
        $template_id   = intval( $request->get_param( 'template_id' ) ?? 0 );
        $confirm_token = sanitize_text_field( $request->get_param( 'confirm_token' ) ?? '' );
        $force         = (bool) $request->get_param( 'force' );

        if ( ! $template_id ) {
            return new WP_Error( 'missing_params', 'template_id required', array( 'status' => 400 ) );
        }
        if ( $confirm_token !== 'I_KNOW_WHAT_IM_DOING' ) {
            return new WP_Error( 'confirm_required', 'confirm_token must equal "I_KNOW_WHAT_IM_DOING"', array( 'status' => 400 ) );
        }

        $tpl = get_post( $template_id );
        if ( ! $tpl || $tpl->post_type !== 'elementor_library' ) {
            return new WP_Error( 'not_found', 'Template not found', array( 'status' => 404 ) );
        }

        // Refuse to delete an actively-applied header/footer/kit
        $tpl_type   = get_post_meta( $template_id, '_elementor_template_type', true );
        $conditions = get_post_meta( $template_id, '_elementor_conditions', true );
        if ( ! $force && in_array( $tpl_type, array( 'header', 'footer', 'kit' ), true ) && ! empty( $conditions ) ) {
            return new WP_Error( 'protected', 'Refusing to delete an active header/footer/kit. Pass force=true to override.', array( 'status' => 409 ) );
        }

        $deleted = wp_delete_post( $template_id, true );  // skip trash
        if ( ! $deleted ) {
            return new WP_Error( 'delete_failed', 'wp_delete_post returned false', array( 'status' => 500 ) );
        }

        LuwiPress_Logger::log( sprintf(
            'Template deleted: #%d (%s, type=%s)',
            $template_id, $tpl->post_title, $tpl_type
        ), 'warning' );

        return rest_ensure_response( array(
            'status' => 'deleted',
            'id'     => $template_id,
        ) );
    }

    public function rest_responsive_audit( $request ) {
        $post_id = intval( $request['post_id'] );
        return rest_ensure_response( $this->audit_responsive( $post_id ) );
    }

    /* ──────────────────── Private Helpers ────────────────────────────── */

    /**
     * Recursively walk Elementor element tree and call a callback on each element.
     *
     * @param array    $elements Array of Elementor elements.
     * @param callable $callback Function receiving each element.
     */
    private function walk_elements( array $elements, callable $callback ) {
        foreach ( $elements as $element ) {
            $callback( $element );

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->walk_elements( $element['elements'], $callback );
            }
        }
    }

    /**
     * Walk elements with parent tracking.
     *
     * @param array    $elements  Elements array.
     * @param string   $parent_id Parent element ID.
     * @param callable $callback  Function(element, parent_id).
     */
    private function walk_elements_with_path( array $elements, $parent_id, callable $callback ) {
        foreach ( $elements as $element ) {
            $callback( $element, $parent_id );

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->walk_elements_with_path( $element['elements'], $element['id'] ?? '', $callback );
            }
        }
    }

    /**
     * Extract a human-readable style summary from settings.
     * Returns only the visually significant properties.
     *
     * @param array  $settings    Element settings.
     * @param string $widget_type Widget or element type.
     * @return array Summary of active styles.
     */
    private function summarize_styles( array $settings, $widget_type = '' ) {
        $summary = array();

        // Color keys to check (widget-specific + generic)
        $color_key = self::WIDGET_COLOR_KEYS[ $widget_type ] ?? 'title_color';
        if ( ! empty( $settings[ $color_key ] ) ) {
            $summary['color'] = $settings[ $color_key ];
        } elseif ( ! empty( $settings['color'] ) ) {
            $summary['color'] = $settings['color'];
        }

        // Background
        $bg_key = self::WIDGET_BG_KEYS[ $widget_type ] ?? 'background_color';
        if ( ! empty( $settings[ $bg_key ] ) ) {
            $summary['background'] = $settings[ $bg_key ];
        } elseif ( ! empty( $settings['background_color'] ) ) {
            $summary['background'] = $settings['background_color'];
        }

        // Typography
        $typo_keys = array(
            'font-size'      => 'typography_font_size',
            'font-weight'    => 'typography_font_weight',
            'font-family'    => 'typography_font_family',
            'text-transform' => 'typography_text_transform',
        );
        foreach ( $typo_keys as $css_name => $el_key ) {
            if ( ! empty( $settings[ $el_key ] ) ) {
                $val = $settings[ $el_key ];
                if ( is_array( $val ) && isset( $val['size'] ) ) {
                    $summary[ $css_name ] = $val['size'] . ( $val['unit'] ?? 'px' );
                } else {
                    $summary[ $css_name ] = $val;
                }
            }
        }

        // Spacing
        foreach ( array( '_padding' => 'padding', '_margin' => 'margin' ) as $el_key => $css_name ) {
            if ( ! empty( $settings[ $el_key ] ) && is_array( $settings[ $el_key ] ) ) {
                $d    = $settings[ $el_key ];
                $unit = $d['unit'] ?? 'px';
                $summary[ $css_name ] = ( $d['top'] ?? '0' ) . $unit . ' ' . ( $d['right'] ?? '0' ) . $unit . ' ' . ( $d['bottom'] ?? '0' ) . $unit . ' ' . ( $d['left'] ?? '0' ) . $unit;
            }
        }

        // Alignment
        if ( ! empty( $settings['align'] ) ) {
            $summary['text-align'] = $settings['align'];
        }

        return $summary;
    }

    /**
     * Recursively walk and modify Elementor element tree.
     *
     * @param array    $elements Array of Elementor elements.
     * @param callable $callback Function receiving element by reference.
     * @return array Modified elements.
     */
    private function walk_elements_modify( array $elements, callable $callback ) {
        foreach ( $elements as &$element ) {
            $callback( $element );

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->walk_elements_modify( $element['elements'], $callback );
            }
        }
        unset( $element );
        return $elements;
    }

    /**
     * Extract text fields from a widget's settings.
     *
     * @param string $widget_type Widget type name.
     * @param array  $settings    Widget settings.
     * @return array Associative array of field => text.
     */
    private function extract_widget_texts( $widget_type, array $settings ) {
        $texts = array();

        // Simple text fields
        $fields = self::TRANSLATABLE_WIDGETS[ $widget_type ] ?? array();
        foreach ( $fields as $field ) {
            if ( ! empty( $settings[ $field ] ) ) {
                $texts[ $field ] = $settings[ $field ];
            }
        }

        // Raw-HTML widgets: translate plain-markup HTML, but never AI-translate a
        // block carrying <script>/<style> — that could corrupt JS/CSS. Designed
        // markup (the Amber hero, custom sections) carries its CSS in stylesheets
        // and JS in enqueued files, so the visible text translates safely.
        if ( 'html' === $widget_type && isset( $texts['html'] ) && preg_match( '/<(script|style)\b/i', (string) $texts['html'] ) ) {
            unset( $texts['html'] );
        }

        // Repeater items
        if ( isset( self::REPEATER_WIDGETS[ $widget_type ] ) ) {
            $texts += self::extract_repeater_texts( $settings, self::REPEATER_WIDGETS[ $widget_type ] );
        }

        return $texts;
    }

    /**
     * Pull translatable strings out of a widget's repeater rows.
     *
     * `items_key` accepts a string OR a list of candidate keys, because theme
     * widgets rename their repeater over time (process-steps: steps -> items,
     * testimonials: items -> reviews). A single hard-coded key meant those
     * sections silently stayed in the source language after a theme update —
     * the extractor found nothing and the translator was never asked
     * (tapadum finding 6c, 2026-08-25). Unknown keys simply do not match.
     *
     * @param array $settings Widget settings.
     * @param array $config   ['items_key' => string|string[], 'fields' => string[]].
     * @return array field-path => text, keyed as "items_key:index:sub_field".
     */
    public static function extract_repeater_texts( array $settings, array $config ) {
        $texts      = array();
        $sub_fields = isset( $config['fields'] ) ? (array) $config['fields'] : array();

        foreach ( (array) ( $config['items_key'] ?? array() ) as $items_key ) {
            if ( empty( $settings[ $items_key ] ) || ! is_array( $settings[ $items_key ] ) ) {
                continue;
            }
            foreach ( $settings[ $items_key ] as $idx => $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                foreach ( $sub_fields as $sf ) {
                    if ( ! empty( $item[ $sf ] ) && is_scalar( $item[ $sf ] ) ) {
                        // Colon-separated path: items_key:index:field
                        $texts[ $items_key . ':' . $idx . ':' . $sf ] = $item[ $sf ];
                    }
                }
            }
        }

        return $texts;
    }

    /**
     * Normalize CSS-friendly style properties to Elementor setting keys.
     *
     * Accepts input like:
     *   {"font-size": "18px", "color": "#ff0000", "padding": "10px 20px"}
     * And converts to:
     *   {"typography_font_size": {"size": 18, "unit": "px"}, "title_color": "#ff0000", "_padding": {...}}
     *
     * @param array  $styles      Input styles (CSS or Elementor keys).
     * @param string $widget_type Widget type for context-aware key resolution.
     * @param string $el_type     Element type: 'widget', 'section', 'column'.
     * @return array Elementor-native settings.
     */
    public function normalize_styles( array $styles, $widget_type = '', $el_type = 'widget' ) {
        $normalized = array();

        foreach ( $styles as $key => $value ) {
            $css_key = strtolower( trim( $key ) );

            // Check CSS map
            if ( isset( self::CSS_MAP[ $css_key ] ) ) {
                $map    = self::CSS_MAP[ $css_key ];
                $el_key = $map['key'];

                // Widget-specific color overrides
                if ( $css_key === 'color' || $css_key === 'text-color' ) {
                    $el_key = self::WIDGET_COLOR_KEYS[ $widget_type ] ?? $el_key;
                } elseif ( $css_key === 'background-color' || $css_key === 'background' ) {
                    $el_key = self::WIDGET_BG_KEYS[ $widget_type ] ?? self::WIDGET_BG_KEYS[ $el_type ] ?? $el_key;
                }

                // Convert value based on format
                switch ( $map['format'] ) {
                    case 'size_unit':
                        $normalized[ $el_key ] = $this->parse_size_unit( $value );
                        break;
                    case 'dimension':
                        $normalized[ $el_key ] = $this->parse_dimension( $value );
                        break;
                    default:
                        $normalized[ $el_key ] = sanitize_text_field( $value );
                        break;
                }
            } elseif ( is_array( $value ) ) {
                // Already an Elementor-native complex value
                $normalized[ sanitize_text_field( $key ) ] = $this->sanitize_style_value( $value );
            } else {
                // Pass through as-is (already an Elementor key)
                $normalized[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
            }
        }

        return $normalized;
    }

    /**
     * Parse a CSS size value like "18px", "1.5em", or 18 into Elementor {size, unit} format.
     *
     * @param mixed $value CSS value string or number or already-array.
     * @return array {size: number, unit: string}
     */
    private function parse_size_unit( $value ) {
        if ( is_array( $value ) ) {
            return $this->sanitize_style_value( $value );
        }

        $value = trim( $value );

        if ( preg_match( '/^([\d.]+)\s*(px|em|rem|vw|vh|%)?$/i', $value, $m ) ) {
            return array(
                'size' => floatval( $m[1] ),
                'unit' => ! empty( $m[2] ) ? strtolower( $m[2] ) : 'px',
            );
        }

        // Numeric only
        if ( is_numeric( $value ) ) {
            return array( 'size' => floatval( $value ), 'unit' => 'px' );
        }

        // Fallback: pass as-is
        return array( 'size' => $value, 'unit' => 'px' );
    }

    /**
     * Parse a CSS dimension value like "10px", "10px 20px", "10px 20px 10px 20px"
     * into Elementor {top, right, bottom, left, unit, isLinked} format.
     *
     * @param mixed $value CSS shorthand or already-array.
     * @return array Elementor dimension object.
     */
    private function parse_dimension( $value ) {
        if ( is_array( $value ) ) {
            return $this->sanitize_style_value( $value );
        }

        $value = trim( $value );

        // Parse CSS shorthand: "10px" or "10px 20px" or "10px 20px 10px 20px"
        $parts = preg_split( '/\s+/', $value );
        $unit  = 'px';
        $nums  = array();

        foreach ( $parts as $part ) {
            if ( preg_match( '/^([\d.]+)\s*(px|em|rem|%|vw|vh)?$/i', $part, $m ) ) {
                $nums[] = $m[1];
                if ( ! empty( $m[2] ) ) {
                    $unit = strtolower( $m[2] );
                }
            } else {
                $nums[] = $part;
            }
        }

        $count = count( $nums );
        if ( $count === 1 ) {
            return array( 'top' => $nums[0], 'right' => $nums[0], 'bottom' => $nums[0], 'left' => $nums[0], 'unit' => $unit, 'isLinked' => true );
        } elseif ( $count === 2 ) {
            return array( 'top' => $nums[0], 'right' => $nums[1], 'bottom' => $nums[0], 'left' => $nums[1], 'unit' => $unit, 'isLinked' => false );
        } elseif ( $count === 3 ) {
            return array( 'top' => $nums[0], 'right' => $nums[1], 'bottom' => $nums[2], 'left' => $nums[1], 'unit' => $unit, 'isLinked' => false );
        } else {
            return array( 'top' => $nums[0] ?? '0', 'right' => $nums[1] ?? '0', 'bottom' => $nums[2] ?? '0', 'left' => $nums[3] ?? '0', 'unit' => $unit, 'isLinked' => false );
        }
    }

    /**
     * Sanitize a complex style value (e.g., {size: 18, unit: 'px'}).
     *
     * @param array $value Style value array.
     * @return array Sanitized value.
     */
    private function sanitize_style_value( array $value ) {
        $sanitized = array();
        foreach ( $value as $k => $v ) {
            $safe_key = sanitize_text_field( $k );
            if ( is_numeric( $v ) ) {
                $sanitized[ $safe_key ] = floatval( $v );
            } else {
                $sanitized[ $safe_key ] = sanitize_text_field( $v );
            }
        }
        return $sanitized;
    }

    /**
     * Save Elementor data back to post meta and trigger CSS regeneration.
     *
     * @param int   $post_id Post ID.
     * @param array $data    Elementor data array.
     * @return true|WP_Error
     */
    private function save_elementor_data( $post_id, array $data ) {
        $json = wp_json_encode( $data );
        if ( false === $json ) {
            return new WP_Error( 'json_error', 'Failed to encode Elementor data', array( 'status' => 500 ) );
        }

        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

        // Clear Elementor CSS cache so it regenerates
        $this->regenerate_css( $post_id );

        // Purge page cache (LiteSpeed/WP Rocket/W3TC/Super Cache) so stale HTML isn't served
        $this->purge_page_cache_for_post( $post_id );

        return true;
    }

    /**
     * Purge page cache for a post across any detected cache plugin.
     * Called automatically after every Elementor data save.
     *
     * @param int $post_id Post ID.
     * @return array List of cache plugins purged.
     */
    public function purge_page_cache_for_post( $post_id ) {
        $purged = array();

        // LiteSpeed Cache (most common on WP hosts)
        if ( defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Purge' ) ) {
            do_action( 'litespeed_purge_post', $post_id );
            $purged[] = 'litespeed';
        }

        // WP Rocket
        if ( function_exists( 'rocket_clean_post' ) ) {
            rocket_clean_post( $post_id );
            $purged[] = 'wp-rocket';
        }

        // W3 Total Cache
        if ( function_exists( 'w3tc_flush_post' ) ) {
            w3tc_flush_post( $post_id );
            $purged[] = 'w3tc';
        }

        // WP Super Cache
        if ( function_exists( 'wp_cache_post_change' ) ) {
            global $blog_id;
            wp_cache_post_change( $blog_id, $post_id );
            $purged[] = 'wp-super-cache';
        }

        // Cache Enabler
        // @phpstan-ignore-next-line function.alreadyNarrowedType
        if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_page_cache_by_post_id' ) ) {
            Cache_Enabler::clear_page_cache_by_post_id( $post_id );
            $purged[] = 'cache-enabler';
        }

        return $purged;
    }

    /**
     * Purge page cache for a URL (not tied to a post).
     *
     * @param string $url Full URL to purge.
     * @return array
     */
    public function purge_page_cache_for_url( $url ) {
        $purged = array();

        if ( defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Purge' ) ) {
            do_action( 'litespeed_purge_url', $url );
            $purged[] = 'litespeed';
        }
        if ( function_exists( 'rocket_clean_files' ) ) {
            rocket_clean_files( $url );
            $purged[] = 'wp-rocket';
        }

        return $purged;
    }

    /**
     * Purge entire site page cache. Use sparingly.
     */
    public function purge_page_cache_all() {
        $purged = array();

        if ( defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Purge' ) ) {
            do_action( 'litespeed_purge_all' );
            $purged[] = 'litespeed';
        }
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
            $purged[] = 'wp-rocket';
        }
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
            $purged[] = 'w3tc';
        }
        // @phpstan-ignore-next-line function.alreadyNarrowedType
        if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
            Cache_Enabler::clear_complete_cache();
            $purged[] = 'cache-enabler';
        }

        return $purged;
    }

    /**
     * REST handler: POST /elementor/purge-page-cache
     * Body options (one of): post_id (int), post_ids (array of int), url (string), all (bool)
     */
    public function rest_purge_page_cache( $request ) {
        $post_id  = intval( $request->get_param( 'post_id' ) );
        $post_ids = $request->get_param( 'post_ids' );
        $url      = $request->get_param( 'url' );
        $purge_all = (bool) $request->get_param( 'all' );

        $results = array();

        if ( $purge_all ) {
            $results['_all'] = $this->purge_page_cache_all();
        } elseif ( is_array( $post_ids ) && ! empty( $post_ids ) ) {
            foreach ( $post_ids as $pid ) {
                $pid = intval( $pid );
                if ( $pid > 0 ) {
                    $results[ $pid ] = $this->purge_page_cache_for_post( $pid );
                }
            }
        } elseif ( $post_id > 0 ) {
            $results[ $post_id ] = $this->purge_page_cache_for_post( $post_id );
        } elseif ( ! empty( $url ) ) {
            $url = esc_url_raw( $url );
            $results[ $url ] = $this->purge_page_cache_for_url( $url );
        } else {
            return new WP_Error( 'missing_params', 'Provide post_id, post_ids, url, or all=true', array( 'status' => 400 ) );
        }

        return rest_ensure_response( array(
            'status'  => 'completed',
            'results' => $results,
        ) );
    }

    /**
     * Trigger Elementor CSS regeneration for a post.
     *
     * @param int $post_id Post ID.
     */
    public function regenerate_css( $post_id ) {
        // Delete cached CSS so Elementor rebuilds on next load
        delete_post_meta( $post_id, '_elementor_css' );
        delete_post_meta( $post_id, '_elementor_page_assets' );

        // Elementor 3.2x+ also caches RENDERED element markup. Clearing only the CSS
        // meta leaves the old HTML being served, so a correct _elementor_data write
        // looks like it silently reverted on the frontend.
        delete_post_meta( $post_id, '_elementor_element_cache' );

        // If Elementor plugin is active, use its API to clear CSS
        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                // Elementor 3.x: Post_CSS class
                if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
                    $post_css = new \Elementor\Core\Files\CSS\Post( $post_id );
                    $post_css->delete();
                } elseif ( method_exists( \Elementor\Plugin::$instance, 'files_manager' ) ) {
                    // Fallback: files_manager API (may vary by version)
                    $post_css = \Elementor\Plugin::$instance->files_manager->get( 'post', array( 'post_id' => $post_id ) );
                    if ( $post_css ) {
                        $post_css->delete();
                    }
                }
            } catch ( \Throwable $e ) {
                // Silently fail — CSS will regenerate on next page load anyway
                LuwiPress_Logger::log( 'Elementor CSS clear error: ' . $e->getMessage(), 'debug' );
            }

            // Also clear global cache
            try {
                if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
            } catch ( \Throwable $e ) {
                // Silently fail
            }
        }

        LuwiPress_Logger::log( 'Elementor CSS regenerated for post #' . $post_id, 'debug' );
    }

    /**
     * Build AI translation prompt for Elementor texts.
     *
     * @param array  $texts_for_ai Array of {widget_id, field, text}.
     * @param string $source_name  Source language name.
     * @param string $target_name  Target language name.
     * @return array ['system' => string, 'user' => string]
     */
    private function build_translation_prompt( array $texts_for_ai, $source_name, $target_name ) {
        $system = sprintf(
            'You are an expert web content translator. Translate Elementor page content from %1$s to %2$s.

RULES:
- Preserve ALL HTML tags, classes, and attributes exactly as they are.
- Preserve brand names and proper nouns as-is.
- Keep the same tone and style as the original.
- For button text, keep it concise and action-oriented.
- Do NOT translate URLs, CSS classes, or technical attributes.
- Return ONLY valid JSON.',
            $source_name,
            $target_name
        );

        // Brand/product names the operator marked as untranslatable.
        $system .= LuwiPress_Translation::glossary_prompt_rule();

        $texts_json = wp_json_encode( $texts_for_ai, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        $user = sprintf(
            'Translate the following Elementor widget texts from %1$s to %2$s.

Input (array of widget texts):
%3$s

Respond ONLY with valid JSON:
{"translations": [{"widget_id": "...", "field": "...", "text": "translated text"}, ...]}

IMPORTANT: Return exactly the same number of items. Keep widget_id and field values unchanged. Only translate the "text" values.',
            $source_name,
            $target_name,
            $texts_json
        );

        return apply_filters( 'luwipress_prompt_elementor_translation', array(
            'system' => $system,
            'user'   => $user,
        ), $texts_for_ai, $source_name, $target_name );
    }

    /**
     * Resolve the existing translation post for a (source, language) pair.
     *
     * WPML's object_id filter is unreliable in cron context, so the direct
     * icl_translations lookup is kept as a fallback (same pairing the save path
     * has always used — extracted so the coverage gate can consult it BEFORE
     * anything is written).
     *
     * @param int    $source_id Source post ID.
     * @param string $language  Target language code.
     * @return int Translation post ID, or 0 when none exists.
     */
    public function find_translation_id( $source_id, $language ) {
        $source_id = absint( $source_id );
        if ( ! $source_id || ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return 0;
        }
        $source_post = get_post( $source_id );
        if ( ! $source_post ) {
            return 0;
        }

        $element_type = 'post_' . $source_post->post_type;
        $trid         = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
        if ( ! $trid ) {
            return 0;
        }

        $default_lang = LuwiPress_Translation::get_default_language();
        do_action( 'wpml_switch_language', $language );
        $translated_id = apply_filters( 'wpml_object_id', $source_id, $source_post->post_type, false, $language );
        do_action( 'wpml_switch_language', $default_lang );

        if ( ! $translated_id || absint( $translated_id ) === $source_id ) {
            global $wpdb;
            $translated_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code = %s AND element_id != %d",
                $trid, $language, $source_id
            ) );
        }

        $translated_id = absint( $translated_id );
        return ( $translated_id && $translated_id !== $source_id ) ? $translated_id : 0;
    }

    /**
     * Current translatable texts on an existing translation post.
     *
     * Uses the same extraction (and therefore the same field-key vocabulary,
     * repeater paths included) as the source side, so resolve_translated_value()
     * can compare them field by field.
     *
     * @param int $translated_id Translation post ID (0 = none).
     * @return array element_id => [field => value]
     */
    private function existing_translation_texts( $translated_id ) {
        if ( ! $translated_id ) {
            return array();
        }
        $extracted = $this->extract_translatable_text( $translated_id );
        if ( is_wp_error( $extracted ) || ! is_array( $extracted ) ) {
            return array();
        }
        $map = array();
        foreach ( $extracted as $wid => $info ) {
            if ( isset( $info['texts'] ) && is_array( $info['texts'] ) ) {
                $map[ $wid ] = $info['texts'];
            }
        }
        return $map;
    }

    /**
     * Save translated Elementor page via WPML.
     *
     * Creates or updates a WPML translation post with the translated
     * _elementor_data and registers it in icl_translations.
     *
     * @param int    $source_id       Source post ID.
     * @param string $language        Target language code.
     * @param array  $translated_data Translated Elementor data array.
     * @return array|WP_Error Array with 'translated_id' or error.
     */

    /**
     * Translate a long HTML string by splitting into chunks at heading boundaries.
     *
     * @param string $html        Full HTML content.
     * @param string $source_lang Source language name.
     * @param string $target_lang Target language name.
     * @return string|WP_Error Translated HTML.
     */
    private function translate_long_html( $html, $source_lang, $target_lang ) {
        // 2200-char target keeps every per-chunk AI call comfortably under the
        // provider timeout. A single oversized block (a long heading-less paragraph)
        // used to become one giant chunk that timed out (cURL error 28) and silently
        // fell back to the English original — the root cause of large blog posts
        // staying untranslated. split_html_by_headings now hard-splits such blocks.
        $chunks = $this->split_html_by_headings( $html, 2200 );

        LuwiPress_Logger::log( sprintf( 'Elementor long text: %d chunks from %d chars', count( $chunks ), strlen( $html ) ), 'info' );

        $translated_chunks = array();
        foreach ( $chunks as $i => $chunk ) {
            $prompt   = LuwiPress_Prompts::elementor_html_translation( $chunk, $source_lang, $target_lang );
            $messages = LuwiPress_AI_Engine::build_messages( $prompt );

            $max_tokens = max( 2048, intval( strlen( $chunk ) / 2 ) );
            $max_tokens = min( $max_tokens, 16000 );

            $result = LuwiPress_AI_Engine::dispatch( 'elementor-translation', $messages, array(
                'max_tokens' => $max_tokens,
                'timeout'    => 120,
            ) );

            if ( is_wp_error( $result ) ) {
                LuwiPress_Logger::log( 'Chunk ' . $i . ' failed: ' . $result->get_error_message(), 'warning' );
                $translated_chunks[] = $chunk; // Keep original on failure
            } else {
                $text = $result['content'] ?? '';
                $text = preg_replace( '/^```(?:html)?\s*/i', '', $text );
                $text = preg_replace( '/\s*```\s*$/', '', $text );
                $translated_chunks[] = trim( $text );
            }
        }

        return implode( '', $translated_chunks );
    }

    /**
     * Split HTML content at heading boundaries.
     *
     * @param string $html      Full HTML.
     * @param int    $max_chars Max chars per chunk.
     * @return array Chunks.
     */
    private function split_html_by_headings( $html, $max_chars = 3000 ) {
        $parts = preg_split( '/(?=<h[23][^>]*>)/i', $html );

        if ( empty( $parts ) || count( $parts ) <= 1 ) {
            $parts = preg_split( '/(?=<p[^>]*>)/i', $html );
        }

        if ( empty( $parts ) || count( $parts ) <= 1 ) {
            // No headings or paragraph boundaries to split on — hard-split the whole
            // blob so it never reaches the AI as one oversized (timeout-prone) call.
            return $this->hard_split_html( $html, $max_chars );
        }

        $chunks  = array();
        $current = '';

        foreach ( $parts as $part ) {
            // A single part can itself exceed max_chars (e.g. one very long <p>).
            // Hard-split it instead of emitting an oversized chunk.
            if ( strlen( $part ) > $max_chars ) {
                if ( '' !== $current ) {
                    $chunks[] = $current;
                    $current  = '';
                }
                foreach ( $this->hard_split_html( $part, $max_chars ) as $piece ) {
                    $chunks[] = $piece;
                }
                continue;
            }
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
     * Hard-split an HTML string into <= $max_chars pieces, preferring safe
     * boundaries (closing block tags, <br>, sentence ends) over raw character
     * cuts. Guarantees no piece exceeds the limit so every AI call stays well
     * under the provider timeout.
     *
     * @param string $html
     * @param int    $max_chars
     * @return array
     */
    private function hard_split_html( $html, $max_chars ) {
        if ( strlen( $html ) <= $max_chars ) {
            return array( $html );
        }
        // Split on closing block tags, <br>, or sentence terminators while keeping
        // the delimiter attached to the preceding fragment.
        $fragments = preg_split( '/(<\/(?:p|li|h[1-6]|div|blockquote|ul|ol)>|<br\s*\/?>|(?<=[.!?])\s+)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        $chunks  = array();
        $current = '';
        foreach ( $fragments as $frag ) {
            if ( '' === $frag ) {
                continue;
            }
            if ( strlen( $frag ) > $max_chars ) {
                // A single unsplittable fragment longer than the limit — cut it raw.
                if ( '' !== $current ) {
                    $chunks[] = $current;
                    $current  = '';
                }
                foreach ( str_split( $frag, $max_chars ) as $raw ) {
                    $chunks[] = $raw;
                }
                continue;
            }
            if ( strlen( $current ) + strlen( $frag ) > $max_chars && '' !== $current ) {
                $chunks[] = $current;
                $current  = $frag;
            } else {
                $current .= $frag;
            }
        }
        if ( '' !== $current ) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    private function save_translated_page( $source_id, $language, array $translated_data ) {
        $source_post = get_post( $source_id );
        if ( ! $source_post ) {
            return new WP_Error( 'not_found', 'Source post not found', array( 'status' => 404 ) );
        }

        $post_type    = $source_post->post_type;
        $element_type = 'post_' . $post_type;
        $default_lang = LuwiPress_Translation::get_default_language();

        // Check if WPML is available
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            // Without WPML, just save as a new post with a language suffix
            return $this->save_translated_page_standalone( $source_id, $language, $translated_data );
        }

        $trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
        if ( ! $trid ) {
            LuwiPress_Logger::log( 'No WPML trid for post #' . $source_id, 'warning' );
            return $this->save_translated_page_standalone( $source_id, $language, $translated_data );
        }

        // Check for existing translation (WPML context switch + direct DB fallback)
        $translated_id = $this->find_translation_id( $source_id, $language );

        $translated_json = wp_json_encode( $translated_data );

        if ( $translated_id && $translated_id !== $source_id ) {
            // Switch WPML language context to target — critical for cron where context is wrong
            do_action( 'wpml_switch_language', $language );

            // Update existing translation
            $existing = get_post( $translated_id );
            $update_data = array(
                'ID'          => $translated_id,
                'post_status' => 'publish',
            );
            if ( ! $existing || empty( $existing->post_title ) || $existing->post_title === $source_post->post_title ) {
                $update_data['post_title'] = $source_post->post_title;
            }
            wp_update_post( $update_data );

            // Write translated Elementor data directly to DB to bypass WPML meta filtering
            // No wp_slash() — direct DB doesn't need it (wp_slash is only for update_post_meta)
            global $wpdb;
            $wpdb->update(
                $wpdb->postmeta,
                array( 'meta_value' => $translated_json ),
                array( 'post_id' => $translated_id, 'meta_key' => '_elementor_data' )
            );
            if ( ! $wpdb->rows_affected ) {
                $wpdb->insert( $wpdb->postmeta, array(
                    'post_id'    => $translated_id,
                    'meta_key'   => '_elementor_data',
                    'meta_value' => $translated_json,
                ) );
            }
            update_post_meta( $translated_id, '_elementor_edit_mode', 'builder' );
            update_post_meta( $translated_id, '_luwipress_elementor_translated', '1' );
            $this->regenerate_css( $translated_id );

            // Switch back to default language
            do_action( 'wpml_switch_language', $default_lang );

            LuwiPress_Logger::log( 'Elementor WPML translation updated: #' . $translated_id, 'info' );
        } else {
            // ── Create new translation post via centralized method ──
            $translation = LuwiPress_Translation::get_instance();
            $translated_id = $translation->create_translation_post( $source_id, $language, array(
                'title' => $source_post->post_title,
            ) );

            if ( is_wp_error( $translated_id ) ) {
                return $translated_id;
            }

            // Save Elementor data — direct DB to bypass WPML meta filtering in cron
            global $wpdb;
            $wpdb->insert( $wpdb->postmeta, array(
                'post_id'    => $translated_id,
                'meta_key'   => '_elementor_data',
                'meta_value' => $translated_json,
            ) );
            update_post_meta( $translated_id, '_elementor_edit_mode', 'builder' );
            update_post_meta( $translated_id, '_luwipress_elementor_translated', '1' );
            update_post_meta( $translated_id, '_elementor_version', get_post_meta( $source_id, '_elementor_version', true ) );

            // Copy _elementor_page_settings if present
            $page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );
            if ( $page_settings ) {
                update_post_meta( $translated_id, '_elementor_page_settings', $page_settings );
            }

            $this->regenerate_css( $translated_id );

            LuwiPress_Logger::log( 'Elementor WPML translation created: #' . $translated_id, 'info' );
        }

        // Copy featured image from source
        $thumb_id = get_post_thumbnail_id( $source_id );
        if ( $thumb_id ) {
            set_post_thumbnail( $translated_id, $thumb_id );
        }

        // Mark as LuwiPress Elementor translated — prevents bypass filter
        update_post_meta( $translated_id, '_luwipress_elementor_translated', '1' );

        // Stamp the source's modified_at_gmt so we can detect "outdated translation"
        // later: if source.post_modified_gmt > stored stamp, the translation is stale
        // and the operator should re-sync. Stored on translation post (one stamp per
        // language pair). Cheap to compare, robust to timezone drift.
        $src_modified = get_post_field( 'post_modified_gmt', $source_id );
        if ( $src_modified ) {
            update_post_meta( $translated_id, '_luwipress_synced_source_modified', $src_modified );
        }

        return array( 'translated_id' => $translated_id );
    }

    /**
     * Standalone save (no WPML) — creates a copy with language suffix.
     *
     * @param int    $source_id       Source post ID.
     * @param string $language        Target language code.
     * @param array  $translated_data Translated Elementor data.
     * @return array|WP_Error
     */
    private function save_translated_page_standalone( $source_id, $language, array $translated_data ) {
        $source_post = get_post( $source_id );

        $new_id = wp_insert_post( array(
            'post_title'   => $source_post->post_title . ' [' . strtoupper( $language ) . ']',
            'post_content' => '',
            'post_type'    => $source_post->post_type,
            'post_status'  => 'draft',
            'post_author'  => $source_post->post_author,
            'post_name'    => $source_post->post_name . '-' . $language,
        ) );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        $translated_json = wp_json_encode( $translated_data );
        update_post_meta( $new_id, '_elementor_data', wp_slash( $translated_json ) );
        update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $new_id, '_elementor_version', get_post_meta( $source_id, '_elementor_version', true ) );
        update_post_meta( $new_id, '_luwipress_elementor_source', $source_id );
        update_post_meta( $new_id, '_luwipress_elementor_language', $language );

        $this->regenerate_css( $new_id );

        LuwiPress_Logger::log(
            sprintf( 'Elementor standalone translation created: #%d (%s, no WPML)', $new_id, strtoupper( $language ) ),
            'info'
        );

        return array( 'translated_id' => $new_id );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  STRUCTURAL OPERATIONS — Add / Delete / Move / Clone
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Add a new widget inside a target container (section, column, or container).
     *
     * @param int    $post_id      Post ID.
     * @param string $container_id Parent element ID to insert into.
     * @param string $widget_type  Widget type (heading, text-editor, button, image, etc.).
     * @param array  $settings     Widget settings (text + style).
     * @param int    $position     Insert position (0-based index, -1 = end).
     * @return array|WP_Error      New widget info or error.
     */
    public function add_widget( $post_id, $container_id, $widget_type, array $settings = array(), $position = -1 ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $new_id = $this->generate_element_id();

        $new_widget = array(
            'id'         => $new_id,
            'elType'     => 'widget',
            'widgetType' => sanitize_text_field( $widget_type ),
            'settings'   => $settings,
            'elements'   => array(),
        );

        $found = false;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $container_id, $new_widget, $position, &$found ) {
            if ( ( $element['id'] ?? '' ) !== $container_id ) {
                return;
            }
            $found = true;

            if ( ! isset( $element['elements'] ) || ! is_array( $element['elements'] ) ) {
                $element['elements'] = array();
            }

            if ( $position < 0 || $position >= count( $element['elements'] ) ) {
                $element['elements'][] = $new_widget;
            } else {
                array_splice( $element['elements'], $position, 0, array( $new_widget ) );
            }
        } );

        if ( ! $found ) {
            return new WP_Error( 'container_not_found', 'Container element not found: ' . $container_id, array( 'status' => 404 ) );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log(
            sprintf( 'Elementor: widget "%s" added to container %s in post #%d', $widget_type, $container_id, $post_id ),
            'info'
        );

        return array(
            'status'       => 'created',
            'widget_id'    => $new_id,
            'widget_type'  => $widget_type,
            'container_id' => $container_id,
            'position'     => $position,
        );
    }

    /**
     * Add a new section with a column to the page.
     *
     * @param int   $post_id  Post ID.
     * @param int   $position Insert position in root (-1 = end).
     * @param array $settings Section settings.
     * @return array|WP_Error New section + column info.
     */
    public function add_section( $post_id, $position = -1, array $settings = array() ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $section_id = $this->generate_element_id();
        $column_id  = $this->generate_element_id();

        $new_section = array(
            'id'       => $section_id,
            'elType'   => 'section',
            'settings' => $settings,
            'elements' => array(
                array(
                    'id'       => $column_id,
                    'elType'   => 'column',
                    'settings' => array( '_column_size' => 100 ),
                    'elements' => array(),
                ),
            ),
        );

        if ( $position < 0 || $position >= count( $data ) ) {
            $data[] = $new_section;
        } else {
            array_splice( $data, $position, 0, array( $new_section ) );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log( sprintf( 'Elementor: section added to post #%d', $post_id ), 'info' );

        return array(
            'status'     => 'created',
            'section_id' => $section_id,
            'column_id'  => $column_id,
        );
    }

    /**
     * Delete an element (widget, section, or column) by its ID.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element ID to remove.
     * @return true|WP_Error
     */
    public function delete_element( $post_id, $element_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found = false;
        $data  = $this->remove_element_recursive( $data, $element_id, $found );

        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log( sprintf( 'Elementor: element %s deleted from post #%d', $element_id, $post_id ), 'info' );
        return true;
    }

    /**
     * Move an element to a new position within its parent, or to a different container.
     *
     * @param int    $post_id        Post ID.
     * @param string $element_id     Element to move.
     * @param string $target_parent  New parent container ID (empty = same parent).
     * @param int    $position       New position index (0-based, -1 = end).
     * @return true|WP_Error
     */
    public function move_element( $post_id, $element_id, $target_parent = '', $position = -1 ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // Step 1: Extract the element (remove from current position)
        $extracted = null;
        $data      = $this->extract_element_recursive( $data, $element_id, $extracted );

        if ( ! $extracted ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        // Step 2: Insert at new position
        if ( empty( $target_parent ) ) {
            // Move within root level (for sections)
            if ( $position < 0 || $position >= count( $data ) ) {
                $data[] = $extracted;
            } else {
                array_splice( $data, $position, 0, array( $extracted ) );
            }
        } else {
            $inserted = false;
            $data = $this->walk_elements_modify( $data, function ( &$element ) use ( $target_parent, $extracted, $position, &$inserted ) {
                if ( ( $element['id'] ?? '' ) !== $target_parent ) {
                    return;
                }
                $inserted = true;
                if ( ! isset( $element['elements'] ) ) {
                    $element['elements'] = array();
                }
                if ( $position < 0 || $position >= count( $element['elements'] ) ) {
                    $element['elements'][] = $extracted;
                } else {
                    array_splice( $element['elements'], $position, 0, array( $extracted ) );
                }
            } );

            if ( ! $inserted ) {
                return new WP_Error( 'target_not_found', 'Target parent not found: ' . $target_parent, array( 'status' => 404 ) );
            }
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Clone (duplicate) an element with new IDs.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element to clone.
     * @return array|WP_Error Cloned element info.
     */
    public function clone_element( $post_id, $element_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $source  = null;
        $parent_id = null;
        $source_index = null;
        $this->find_element_with_context( $data, $element_id, $source, $parent_id, $source_index );

        if ( ! $source ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        // Deep clone with new IDs
        $clone = $this->deep_clone_element( $source );

        // Insert right after the source
        if ( $parent_id === null ) {
            // Root-level element (section)
            array_splice( $data, $source_index + 1, 0, array( $clone ) );
        } else {
            $data = $this->walk_elements_modify( $data, function ( &$element ) use ( $parent_id, $source_index, $clone ) {
                if ( ( $element['id'] ?? '' ) !== $parent_id ) {
                    return;
                }
                array_splice( $element['elements'], $source_index + 1, 0, array( $clone ) );
            } );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log( sprintf( 'Elementor: element %s cloned as %s in post #%d', $element_id, $clone['id'], $post_id ), 'info' );

        return array(
            'status'    => 'cloned',
            'source_id' => $element_id,
            'clone_id'  => $clone['id'],
        );
    }

    /* ──────────── Copy a section across posts ──────────────────────────── */

    /**
     * REST: copy a top-level section from one post into another at a given position.
     *
     * Payload:
     *   source_post_id     int    — post to read the section from
     *   source_section_id  string — id of the top-level section to copy
     *   target_post_id     int    — post to insert into
     *   target_position    int    — 0-based insertion index; -1 = append
     *
     * Behaviour: deep-clones the entire section subtree with NEW element IDs,
     * inserts at target_position in target's `_elementor_data`. Snapshots the
     * target before mutation. Does NOT touch any other section in the target —
     * existing translated text in untouched sections is preserved by construction.
     */
    public function rest_copy_section( $request ) {
        $source_post_id    = intval( $request->get_param( 'source_post_id' ) );
        $source_section_id = sanitize_text_field( $request->get_param( 'source_section_id' ) );
        $target_post_id    = intval( $request->get_param( 'target_post_id' ) );
        $target_position   = $request->get_param( 'target_position' );
        $target_position   = ( $target_position === null || $target_position === '' ) ? -1 : intval( $target_position );

        if ( ! $source_post_id || ! $source_section_id || ! $target_post_id ) {
            return new WP_Error( 'missing_params', 'source_post_id, source_section_id, target_post_id required', array( 'status' => 400 ) );
        }
        if ( $source_post_id === $target_post_id ) {
            return new WP_Error( 'same_post', 'For same-post clone use /elementor/clone instead', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->copy_section( $source_post_id, $source_section_id, $target_post_id, $target_position ) );
    }

    public function copy_section( $source_post_id, $source_section_id, $target_post_id, $target_position = -1 ) {
        // Read source
        $source_data = $this->get_elementor_data( $source_post_id );
        if ( is_wp_error( $source_data ) ) {
            return $source_data;
        }

        // Find the source section as a top-level entry
        $source_section = null;
        $source_index   = null;
        foreach ( $source_data as $idx => $element ) {
            if ( ( $element['id'] ?? '' ) === $source_section_id ) {
                $source_section = $element;
                $source_index   = $idx;
                break;
            }
        }
        if ( ! $source_section ) {
            return new WP_Error( 'source_section_not_found', sprintf( 'Section %s not found at top level of post #%d', $source_section_id, $source_post_id ), array( 'status' => 404 ) );
        }

        // Read target
        $target_data = $this->get_elementor_data( $target_post_id );
        if ( is_wp_error( $target_data ) ) {
            return $target_data;
        }

        // Snapshot target BEFORE mutation
        $snapshot = $this->create_snapshot( $target_post_id, sprintf( 'Pre-copy-section from #%d/%s', $source_post_id, $source_section_id ) );
        $snapshot_id = is_wp_error( $snapshot ) ? '' : ( $snapshot['snapshot_id'] ?? '' );

        // Deep clone source section with fresh IDs
        $cloned_section = $this->deep_clone_element( $source_section );

        // Insert at target_position (0 = prepend, -1 or >=count = append)
        $insert_at = $target_position;
        if ( $insert_at < 0 || $insert_at > count( $target_data ) ) {
            $insert_at = count( $target_data );
        }
        array_splice( $target_data, $insert_at, 0, array( $cloned_section ) );

        // Save target
        $saved = $this->save_elementor_data( $target_post_id, $target_data );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        LuwiPress_Logger::log( sprintf(
            'Elementor: copied section %s from #%d to #%d as %s at pos %d',
            $source_section_id, $source_post_id, $target_post_id, $cloned_section['id'], $insert_at
        ), 'info' );

        return array(
            'status'             => 'copied',
            'source_post_id'     => $source_post_id,
            'source_section_id'  => $source_section_id,
            'target_post_id'     => $target_post_id,
            'target_position'    => $insert_at,
            'new_section_id'     => $cloned_section['id'],
            'snapshot_id'        => $snapshot_id,
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  CUSTOM CSS & RESPONSIVE OVERRIDES
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Set custom CSS on an element (widget, section, or column).
     *
     * Uses Elementor's native custom_css setting. CSS selector `selector`
     * refers to the element's main wrapper.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element ID.
     * @param string $css        CSS rules (e.g., "selector { color: red; }")
     * @return true|WP_Error
     */
    public function set_custom_css( $post_id, $element_id, $css ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found = false;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $element_id, $css, &$found ) {
            if ( ( $element['id'] ?? '' ) !== $element_id ) {
                return;
            }
            $found = true;
            $element['settings']['custom_css'] = wp_strip_all_tags( $css );
        } );

        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Set page-level custom CSS.
     *
     * @param int    $post_id Post ID.
     * @param string $css     Full CSS code.
     * @return true|WP_Error
     */
    public function set_page_css( $post_id, $css ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
        }

        $page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
        if ( ! is_array( $page_settings ) ) {
            $page_settings = array();
        }

        $page_settings['custom_css'] = wp_strip_all_tags( $css );
        update_post_meta( $post_id, '_elementor_page_settings', $page_settings );

        $this->regenerate_css( $post_id );

        LuwiPress_Logger::log( sprintf( 'Elementor: page CSS updated for post #%d', $post_id ), 'info' );
        return true;
    }

    /**
     * Set responsive style overrides for an element.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element ID.
     * @param string $device     Device: 'mobile' or 'tablet'.
     * @param array  $styles     CSS-friendly styles for that device.
     * @return true|WP_Error
     */
    public function set_responsive_style( $post_id, $element_id, $device, array $styles ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $suffix = ( $device === 'tablet' ) ? '_tablet' : '_mobile';

        $found = false;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $element_id, $styles, $suffix, &$found ) {
            if ( ( $element['id'] ?? '' ) !== $element_id ) {
                return;
            }
            $found = true;

            $el_type     = $element['elType'] ?? 'widget';
            $widget_type = $element['widgetType'] ?? $el_type;

            // Normalize CSS to Elementor keys
            $normalized = $this->normalize_styles( $styles, $widget_type, $el_type );

            // Apply with device suffix
            foreach ( $normalized as $key => $value ) {
                $element['settings'][ $key . $suffix ] = $value;
            }
        } );

        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Apply a style to ALL widgets of a given type on a page.
     *
     * Example: "Make all headings blue" → apply_global_style($id, 'heading', ['color' => '#0000ff'])
     *
     * @param int    $post_id     Post ID.
     * @param string $widget_type Widget type to target (heading, button, text-editor, etc.).
     * @param array  $styles      CSS-friendly styles to apply.
     * @return array|WP_Error Results with count of affected widgets.
     */
    public function apply_global_style( $post_id, $widget_type, array $styles ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $count = 0;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $widget_type, $styles, &$count ) {
            if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
                return;
            }
            if ( ( $element['widgetType'] ?? '' ) !== $widget_type ) {
                return;
            }

            $normalized = $this->normalize_styles( $styles, $widget_type, 'widget' );
            foreach ( $normalized as $key => $value ) {
                $element['settings'][ $key ] = $value;
            }
            $count++;
        } );

        if ( $count === 0 ) {
            return new WP_Error( 'no_match', 'No widgets of type "' . $widget_type . '" found on this page', array( 'status' => 404 ) );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log(
            sprintf( 'Elementor: global style applied to %d "%s" widgets in post #%d', $count, $widget_type, $post_id ),
            'info'
        );

        return array(
            'status'        => 'updated',
            'widget_type'   => $widget_type,
            'affected_count' => $count,
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  SYNC & AUDIT — Cross-page style sync, spacing audit
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Sync styles from a source Elementor page to one or more target pages.
     *
     * Matches widgets by position in the tree (same structure assumed —
     * e.g., source page and its WPML translations share the same layout).
     * Only style settings are copied; text content is left untouched.
     *
     * @param int       $source_id  Source post ID.
     * @param array|int $target_ids Target post ID(s).
     * @param array     $options    Options:
     *                              - 'include' => ['colors','typography','spacing','all'] (default: 'all')
     *                              - 'widget_types' => ['heading','button',...] (default: all types)
     * @return array|WP_Error Sync results.
     */
    public function sync_styles( $source_id, $target_ids, array $options = array() ) {
        if ( ! is_array( $target_ids ) ) {
            $target_ids = array( $target_ids );
        }

        // Read source page structure
        $source_data = $this->get_elementor_data( $source_id );
        if ( is_wp_error( $source_data ) ) {
            return $source_data;
        }

        // Build source style map: position path → style settings
        $include      = $options['include'] ?? 'all';
        $widget_types = $options['widget_types'] ?? array();
        $source_styles = array();
        $this->extract_styles_by_position( $source_data, '', $source_styles, $include, $widget_types );

        if ( empty( $source_styles ) ) {
            return new WP_Error( 'no_styles', 'No style settings found on source page', array( 'status' => 400 ) );
        }

        $results = array();

        foreach ( $target_ids as $target_id ) {
            $target_id = intval( $target_id );
            $target_data = $this->get_elementor_data( $target_id );
            if ( is_wp_error( $target_data ) ) {
                $results[ $target_id ] = array( 'error' => $target_data->get_error_message() );
                continue;
            }

            // Build target position map
            $target_positions = array();
            $this->map_positions( $target_data, '', $target_positions );

            // Apply source styles to target by matching positions
            $applied = 0;
            $target_data = $this->walk_elements_modify( $target_data, function ( &$element ) use ( $source_styles, $target_positions, &$applied ) {
                $el_id = $element['id'] ?? '';
                // Find this element's position path
                $pos_path = $target_positions[ $el_id ] ?? null;
                if ( ! $pos_path || ! isset( $source_styles[ $pos_path ] ) ) {
                    return;
                }

                // Merge source styles into target (preserving target text)
                foreach ( $source_styles[ $pos_path ] as $key => $value ) {
                    $element['settings'][ $key ] = $value;
                }
                $applied++;
            } );

            if ( $applied > 0 ) {
                $save = $this->save_elementor_data( $target_id, $target_data );
                if ( is_wp_error( $save ) ) {
                    $results[ $target_id ] = array( 'error' => $save->get_error_message() );
                    continue;
                }
            }

            $results[ $target_id ] = array(
                'status'  => 'synced',
                'applied' => $applied,
            );
        }

        LuwiPress_Logger::log(
            sprintf( 'Elementor: styles synced from #%d to %d target(s)', $source_id, count( $target_ids ) ),
            'info',
            array( 'source' => $source_id, 'targets' => $target_ids )
        );

        return array(
            'status'    => 'completed',
            'source_id' => $source_id,
            'results'   => $results,
        );
    }

    /**
     * Auto-discover WPML translation post IDs for a source page.
     *
     * @param int $source_id Source post ID.
     * @return array Array of [lang_code => post_id].
     */
    public function get_translation_ids( $source_id ) {
        $post      = get_post( $source_id );
        $post_type = $post ? $post->post_type : 'page';

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            // Fallback: check our standalone translation meta
            global $wpdb;
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_luwipress_elementor_source' AND meta_value = %d",
                $source_id
            ) );
            $map = array();
            foreach ( $rows as $row ) {
                $lang = get_post_meta( $row->post_id, '_luwipress_elementor_language', true );
                if ( $lang ) {
                    $map[ $lang ] = intval( $row->post_id );
                }
            }
            return $map;
        }

        // WPML: get all translations
        $element_type = 'post_' . $post_type;
        $trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
        if ( ! $trid ) {
            return array();
        }

        $default_lang = LuwiPress_Translation::get_default_language();

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT element_id, language_code FROM {$wpdb->prefix}icl_translations
             WHERE trid = %d AND element_type = %s AND language_code != %s AND element_id IS NOT NULL",
            $trid,
            $element_type,
            $default_lang
        ) );

        $map = array();
        foreach ( $rows as $row ) {
            $map[ $row->language_code ] = intval( $row->element_id );
        }
        return $map;
    }

    /**
     * Audit spacing consistency on an Elementor page.
     *
     * Checks all elements for padding/margin values and reports
     * inconsistencies, extreme values, and common issues.
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error Audit report.
     */
    public function audit_spacing( $post_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $issues   = array();
        $all_spacings = array();

        $this->walk_elements( $data, function ( $element ) use ( &$issues, &$all_spacings ) {
            $el_id   = $element['id'] ?? '';
            $el_type = $element['elType'] ?? '';
            $wtype   = $element['widgetType'] ?? $el_type;
            $settings = $element['settings'] ?? array();

            foreach ( array( '_padding' => 'padding', '_margin' => 'margin' ) as $el_key => $css_name ) {
                // Check desktop, tablet, mobile
                foreach ( array( '' => 'desktop', '_tablet' => 'tablet', '_mobile' => 'mobile' ) as $suffix => $device ) {
                    $key = $el_key . $suffix;
                    if ( ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
                        continue;
                    }

                    $d    = $settings[ $key ];
                    $unit = $d['unit'] ?? 'px';
                    $vals = array(
                        'top'    => floatval( $d['top'] ?? 0 ),
                        'right'  => floatval( $d['right'] ?? 0 ),
                        'bottom' => floatval( $d['bottom'] ?? 0 ),
                        'left'   => floatval( $d['left'] ?? 0 ),
                    );

                    $record = array(
                        'element_id' => $el_id,
                        'el_type'    => $wtype,
                        'property'   => $css_name,
                        'device'     => $device,
                        'values'     => $vals,
                        'unit'       => $unit,
                    );
                    $all_spacings[] = $record;

                    // Check for issues
                    foreach ( $vals as $side => $v ) {
                        // Negative margins (intentional but worth flagging)
                        if ( $css_name === 'margin' && $v < 0 ) {
                            $issues[] = array(
                                'type'       => 'negative_margin',
                                'severity'   => 'warning',
                                'element_id' => $el_id,
                                'el_type'    => $wtype,
                                'device'     => $device,
                                'detail'     => sprintf( '%s-%s: %s%s', $css_name, $side, $v, $unit ),
                            );
                        }

                        // Excessive padding/margin (>100px)
                        if ( $unit === 'px' && abs( $v ) > 100 ) {
                            $issues[] = array(
                                'type'       => 'excessive_spacing',
                                'severity'   => 'warning',
                                'element_id' => $el_id,
                                'el_type'    => $wtype,
                                'device'     => $device,
                                'detail'     => sprintf( '%s-%s: %s%s (>100px)', $css_name, $side, $v, $unit ),
                            );
                        }
                    }

                    // Asymmetric horizontal padding (left != right)
                    if ( $css_name === 'padding' && $vals['left'] !== $vals['right'] ) {
                        $issues[] = array(
                            'type'       => 'asymmetric_padding',
                            'severity'   => 'info',
                            'element_id' => $el_id,
                            'el_type'    => $wtype,
                            'device'     => $device,
                            'detail'     => sprintf( 'left: %s%s, right: %s%s', $vals['left'], $unit, $vals['right'], $unit ),
                        );
                    }
                }
            }

            // Check for missing responsive spacing
            if ( isset( $settings['_padding'] ) && ! isset( $settings['_padding_mobile'] ) ) {
                $d = $settings['_padding'];
                $top = floatval( $d['top'] ?? 0 );
                $has_large = ( $d['unit'] ?? 'px' ) === 'px' && $top > 40;
                if ( $has_large ) {
                    $issues[] = array(
                        'type'       => 'missing_mobile_override',
                        'severity'   => 'suggestion',
                        'element_id' => $el_id,
                        'el_type'    => $wtype,
                        'device'     => 'mobile',
                        'detail'     => sprintf( 'Desktop padding %spx but no mobile override', $top ),
                    );
                }
            }
        } );

        // Collect spacing patterns for consistency check
        $padding_values = array();
        foreach ( $all_spacings as $s ) {
            if ( $s['property'] === 'padding' && $s['device'] === 'desktop' && $s['unit'] === 'px' ) {
                $key = $s['values']['top'] . '/' . $s['values']['right'] . '/' . $s['values']['bottom'] . '/' . $s['values']['left'];
                $padding_values[ $key ][] = $s['element_id'];
            }
        }

        // Flag outliers (uncommon padding patterns)
        $total_padded = array_sum( array_map( 'count', $padding_values ) );
        foreach ( $padding_values as $pattern => $ids ) {
            if ( count( $ids ) === 1 && $total_padded > 3 ) {
                $issues[] = array(
                    'type'       => 'inconsistent_padding',
                    'severity'   => 'info',
                    'element_id' => $ids[0],
                    'el_type'    => '',
                    'device'     => 'desktop',
                    'detail'     => sprintf( 'Unique padding pattern: %s (only element with this padding)', str_replace( '/', 'px ', $pattern ) . 'px' ),
                );
            }
        }

        // Sort by severity
        $severity_order = array( 'warning' => 0, 'suggestion' => 1, 'info' => 2 );
        usort( $issues, function ( $a, $b ) use ( $severity_order ) {
            return ( $severity_order[ $a['severity'] ] ?? 9 ) - ( $severity_order[ $b['severity'] ] ?? 9 );
        } );

        return array(
            'post_id'         => $post_id,
            'total_elements'  => count( $all_spacings ),
            'issue_count'     => count( $issues ),
            'issues'          => $issues,
            'spacing_summary' => $this->summarize_spacing_patterns( $padding_values ),
        );
    }

    /**
     * Extract style-only settings from elements, keyed by position path.
     */
    private function extract_styles_by_position( array $elements, $prefix, array &$styles, $include, array $widget_types ) {
        foreach ( $elements as $idx => $element ) {
            $pos_path = $prefix . '/' . $idx;
            $el_type  = $element['elType'] ?? '';
            $wtype    = $element['widgetType'] ?? '';
            $settings = $element['settings'] ?? array();

            // Filter by widget type if specified
            if ( ! empty( $widget_types ) && $el_type === 'widget' && ! in_array( $wtype, $widget_types, true ) ) {
                // Still recurse children
                if ( ! empty( $element['elements'] ) ) {
                    $this->extract_styles_by_position( $element['elements'], $pos_path, $styles, $include, $widget_types );
                }
                continue;
            }

            // Extract only style settings (skip text content)
            $style_settings = $this->filter_style_settings( $settings, $include );
            if ( ! empty( $style_settings ) ) {
                $styles[ $pos_path ] = $style_settings;
            }

            if ( ! empty( $element['elements'] ) ) {
                $this->extract_styles_by_position( $element['elements'], $pos_path, $styles, $include, $widget_types );
            }
        }
    }

    /**
     * Build position path map for all elements: element_id → position_path.
     */
    private function map_positions( array $elements, $prefix, array &$map ) {
        foreach ( $elements as $idx => $element ) {
            $pos_path = $prefix . '/' . $idx;
            $map[ $element['id'] ?? '' ] = $pos_path;

            if ( ! empty( $element['elements'] ) ) {
                $this->map_positions( $element['elements'], $pos_path, $map );
            }
        }
    }

    /**
     * Filter settings to only include style-related keys.
     *
     * @param array  $settings Raw element settings.
     * @param string $include  Filter: 'all', 'colors', 'typography', 'spacing'.
     * @return array Style-only settings.
     */
    private function filter_style_settings( array $settings, $include = 'all' ) {
        // Style key prefixes/patterns
        $style_patterns = array(
            'colors'     => array( 'color', 'background', '_background', 'border_color', 'overlay' ),
            'typography' => array( 'typography_', 'align', 'text_shadow' ),
            'spacing'    => array( '_padding', '_margin', 'border_radius', 'border_width', 'border_border' ),
        );

        // Text content keys to always exclude
        $text_keys = array( 'title', 'editor', 'text', 'tab_title', 'tab_content',
            'testimonial_content', 'testimonial_name', 'testimonial_job',
            'description', 'button_text', 'title_text', 'description_text',
            'heading', 'sub_heading', 'alert_title', 'alert_description',
            'inner_text', 'prefix', 'suffix', 'before_text', 'after_text',
            'highlighted_text', 'rotating_text', 'item_text', 'custom_css' );

        $selected_prefixes = array();
        if ( $include === 'all' ) {
            foreach ( $style_patterns as $group ) {
                $selected_prefixes = array_merge( $selected_prefixes, $group );
            }
        } elseif ( isset( $style_patterns[ $include ] ) ) {
            $selected_prefixes = $style_patterns[ $include ];
        } else {
            // Comma-separated
            foreach ( explode( ',', $include ) as $group ) {
                $group = trim( $group );
                if ( isset( $style_patterns[ $group ] ) ) {
                    $selected_prefixes = array_merge( $selected_prefixes, $style_patterns[ $group ] );
                }
            }
        }

        $filtered = array();
        foreach ( $settings as $key => $value ) {
            // Skip text keys
            if ( in_array( $key, $text_keys, true ) ) {
                continue;
            }
            // Skip repeater arrays (tabs, icon_list, features_list)
            if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
                continue;
            }

            // Check if key matches any style prefix
            foreach ( $selected_prefixes as $prefix ) {
                if ( strpos( $key, $prefix ) === 0 || $key === $prefix ) {
                    $filtered[ $key ] = $value;
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * Summarize padding patterns for the audit report.
     */
    private function summarize_spacing_patterns( array $padding_values ) {
        $summary = array();
        foreach ( $padding_values as $pattern => $ids ) {
            $parts = explode( '/', $pattern );
            $summary[] = array(
                'pattern'  => sprintf( '%spx %spx %spx %spx', $parts[0], $parts[1], $parts[2], $parts[3] ),
                'count'    => count( $ids ),
                'elements' => $ids,
            );
        }
        // Sort by count descending
        usort( $summary, function ( $a, $b ) { return $b['count'] - $a['count']; } );
        return $summary;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  REVISION SYSTEM — Snapshot / Rollback
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Create a snapshot of the current Elementor data for a post.
     *
     * Stored as post meta: _luwipress_elementor_snapshots (serialized array).
     * Max 10 snapshots per post (oldest auto-pruned).
     *
     * @param int    $post_id Post ID.
     * @param string $label   Optional label/reason for the snapshot.
     * @return array|WP_Error Snapshot info or error.
     */
    public function create_snapshot( $post_id, $label = '' ) {
        // 3.1.42 hotfix-3 (snapshot slash discipline, finally correct):
        // Snapshots store the _elementor_data payload as base64-encoded bytes
        // alongside an opaque "encoding" tag. base64 is opaque to WP's slash
        // sanitization layer (no backslash characters in the payload), which means
        // round-tripping through update_post_meta + get_post_meta preserves byte
        // exactness — the prior approach of storing the raw JSON string lost the
        // single backslash layer that Elementor's parser depends on (HTML quotes
        // inside string values arrive unescaped, JSON parse fails). Reading is
        // unchanged via get_post_meta($snapshots); we just decode `data_b64` at
        // restore time.
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
            $post_id
        ) );

        if ( empty( $raw ) ) {
            return new WP_Error( 'no_data', 'No Elementor data to snapshot', array( 'status' => 400 ) );
        }

        $snapshots = get_post_meta( $post_id, '_luwipress_elementor_snapshots', true );
        if ( ! is_array( $snapshots ) ) {
            $snapshots = array();
        }

        $snapshot_id = substr( md5( wp_generate_uuid4() ), 0, 8 );
        $snapshot = array(
            'id'        => $snapshot_id,
            'label'     => $label ?: 'Snapshot',
            'timestamp' => current_time( 'c' ),
            'user'      => get_current_user_id(),
            // Legacy field kept for backwards-compatibility with old rollback callers.
            // For new snapshots created on 3.1.42-hotfix3+, the authoritative payload
            // lives in `data_b64` (opaque, slash-safe). Old snapshots may have only
            // `data` populated and are best restored via the JSON repair pipeline.
            'data'        => $raw,
            'data_b64'    => base64_encode( $raw ),
            'encoding'    => 'b64-v1',
            'sha256_16'   => substr( hash( 'sha256', $raw ), 0, 16 ),
            'byte_length' => strlen( $raw ),
        );

        // Prepend (newest first)
        array_unshift( $snapshots, $snapshot );

        // Keep max 10
        if ( count( $snapshots ) > 10 ) {
            $snapshots = array_slice( $snapshots, 0, 10 );
        }

        update_post_meta( $post_id, '_luwipress_elementor_snapshots', $snapshots );

        LuwiPress_Logger::log(
            sprintf( 'Elementor: snapshot created for post #%d (%s)', $post_id, $label ?: $snapshot_id ),
            'info'
        );

        return array(
            'status'      => 'created',
            'snapshot_id' => $snapshot_id,
            'label'       => $snapshot['label'],
            'timestamp'   => $snapshot['timestamp'],
        );
    }

    /**
     * Rollback to a previous snapshot.
     *
     * @param int    $post_id     Post ID.
     * @param string $snapshot_id Snapshot ID (empty = most recent).
     * @return array|WP_Error Rollback result.
     */
    public function rollback_snapshot( $post_id, $snapshot_id = '' ) {
        $snapshots = get_post_meta( $post_id, '_luwipress_elementor_snapshots', true );
        if ( ! is_array( $snapshots ) || empty( $snapshots ) ) {
            return new WP_Error( 'no_snapshots', 'No snapshots available for this post', array( 'status' => 404 ) );
        }

        // Create a snapshot of current state before rolling back
        $this->create_snapshot( $post_id, 'Pre-rollback backup' );

        // Find the target snapshot
        $target = null;
        if ( empty( $snapshot_id ) ) {
            // Most recent — $snapshots is the local copy fetched before create_snapshot() updated post meta
            $target = $snapshots[0] ?? null;
        } else {
            foreach ( $snapshots as $snap ) {
                if ( $snap['id'] === $snapshot_id ) {
                    $target = $snap;
                    break;
                }
            }
        }

        if ( ! $target ) {
            return new WP_Error( 'snapshot_not_found', 'Snapshot not found: ' . $snapshot_id, array( 'status' => 404 ) );
        }

        // 3.1.42-hotfix3: Prefer the b64-encoded payload (slash-safe). Fall back
        // to legacy `data` field for snapshots created before this hotfix.
        $payload = null;
        $encoding = $target['encoding'] ?? '';
        if ( $encoding === 'b64-v1' && ! empty( $target['data_b64'] ) ) {
            $decoded = base64_decode( $target['data_b64'], true );
            if ( $decoded === false ) {
                return new WP_Error( 'snapshot_corrupt', 'Snapshot b64 payload failed to decode', array( 'status' => 500 ) );
            }
            $payload = $decoded;
        } elseif ( ! empty( $target['data'] ) ) {
            $payload = $target['data'];
        }

        if ( $payload === null ) {
            return new WP_Error( 'snapshot_empty', 'Snapshot has no usable payload', array( 'status' => 500 ) );
        }

        // Restore via direct $wpdb to bypass the WP meta API slash dance — write
        // the exact bytes the snapshot captured.
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
            $post_id
        ) );
        if ( $existing ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d",
                $payload, $existing
            ) );
        } else {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, '_elementor_data', %s)",
                $post_id, $payload
            ) );
        }
        wp_cache_delete( $post_id, 'post_meta' );
        $this->regenerate_css( $post_id );

        LuwiPress_Logger::log(
            sprintf( 'Elementor: rolled back post #%d to snapshot %s', $post_id, $target['id'] ),
            'info'
        );

        return array(
            'status'      => 'rolled_back',
            'snapshot_id' => $target['id'],
            'label'       => $target['label'],
            'timestamp'   => $target['timestamp'],
        );
    }

    /**
     * List available snapshots for a post.
     *
     * @param int $post_id Post ID.
     * @return array Snapshot list (without data payload).
     */
    public function list_snapshots( $post_id ) {
        $snapshots = get_post_meta( $post_id, '_luwipress_elementor_snapshots', true );
        if ( ! is_array( $snapshots ) ) {
            return array( 'post_id' => $post_id, 'snapshots' => array() );
        }

        // Return without the heavy data field
        $list = array();
        foreach ( $snapshots as $snap ) {
            $list[] = array(
                'id'        => $snap['id'],
                'label'     => $snap['label'],
                'timestamp' => $snap['timestamp'],
                'user'      => $snap['user'],
            );
        }

        return array( 'post_id' => $post_id, 'snapshots' => $list );
    }

    /**
     * Whitelist of meta keys allowed via raw post_meta REST/MCP access.
     * Recovery-only surface — never widen without explicit user authorization.
     *
     * @return array
     */
    public static function raw_meta_whitelist() {
        return array(
            '_elementor_data',
            '_elementor_page_settings',
            '_elementor_css',
            '_luwipress_elementor_snapshots',
        );
    }

    /**
     * Read a single post_meta value as raw bytes, with diagnostic shape.
     *
     * Returns base64 of the raw stored bytes (post-WP unserialize) plus
     * length + slash counters so a caller can measure escaping depth without
     * the byte stream having to survive JSON transport.
     *
     * @param int    $post_id
     * @param string $meta_key
     * @return array|WP_Error
     */
    public function read_post_meta_raw( $post_id, $meta_key ) {
        if ( ! in_array( $meta_key, self::raw_meta_whitelist(), true ) ) {
            return new WP_Error( 'meta_key_not_allowed', 'meta_key not in raw whitelist', array( 'status' => 403 ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
        }

        $value = get_post_meta( $post_id, $meta_key, true );

        $is_string = is_string( $value );
        $stringified = $is_string ? $value : maybe_serialize( $value );

        $info = array(
            'post_id'         => $post_id,
            'meta_key'        => $meta_key,
            'exists'          => ! ( $value === '' || $value === null || $value === false ),
            'is_string'       => $is_string,
            'php_type'        => gettype( $value ),
            'length'          => strlen( $stringified ),
            'sha256_16'       => substr( hash( 'sha256', $stringified ), 0, 16 ),
            'value_b64'       => base64_encode( $stringified ),
        );

        if ( $is_string ) {
            $info['leading_chars']    = substr( $stringified, 0, 16 );
            $info['backslash_runs']   = array(
                'count_1' => substr_count( $stringified, '\\' ),
                'count_2' => substr_count( $stringified, '\\\\' ),
                'count_4' => substr_count( $stringified, '\\\\\\\\' ),
            );
            // Probe how many stripslashes() passes are needed to reach valid
            // JSON whose first element looks like an Elementor section.
            $diagnostic = null;
            $candidate  = $stringified;
            for ( $i = 0; $i <= 4; $i++ ) {
                $decoded = json_decode( $candidate, true );
                if ( is_array( $decoded ) && isset( $decoded[0]['id'] ) && isset( $decoded[0]['elType'] ) ) {
                    $diagnostic = array(
                        'json_decode_pass_index' => $i,
                        'top_level_count'        => count( $decoded ),
                        'first_id'               => $decoded[0]['id'],
                    );
                    break;
                }
                $candidate = stripslashes( $candidate );
            }
            $info['decode_probe'] = $diagnostic;
        }

        return $info;
    }

    /**
     * Write a post_meta value from base64-encoded raw bytes.
     *
     * Recovery surface only. Caller must pass `confirm_token == "I_KNOW_WHAT_IM_DOING"`
     * to proceed. Always creates a pre-write snapshot of the current value into
     * a parallel meta key (`_luwipress_raw_pre_write_<key>_<epoch>`) so a
     * follow-up restore is possible without DB access.
     *
     * @param int    $post_id
     * @param string $meta_key
     * @param string $base64_value
     * @param string $confirm_token
     * @return array|WP_Error
     */
    public function write_post_meta_raw( $post_id, $meta_key, $base64_value, $confirm_token = '' ) {
        if ( $confirm_token !== 'I_KNOW_WHAT_IM_DOING' ) {
            return new WP_Error( 'confirm_required', 'confirm_token must equal "I_KNOW_WHAT_IM_DOING"', array( 'status' => 400 ) );
        }
        if ( ! in_array( $meta_key, self::raw_meta_whitelist(), true ) ) {
            return new WP_Error( 'meta_key_not_allowed', 'meta_key not in raw whitelist', array( 'status' => 403 ) );
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
        }

        $decoded = base64_decode( $base64_value, true );
        if ( $decoded === false ) {
            return new WP_Error( 'invalid_base64', 'value_b64 not valid base64', array( 'status' => 400 ) );
        }

        // Backup current value into a parallel key (raw, no whitelist check).
        $current = get_post_meta( $post_id, $meta_key, true );
        $backup_key = '_luwipress_raw_pre_write_' . preg_replace( '/[^a-z0-9_]/i', '', $meta_key ) . '_' . time();
        update_post_meta( $post_id, $backup_key, $current );

        // Write new value. We pass the bytes through wp_slash() so WP's internal
        // wp_unslash() in update_post_meta lands clean bytes in the DB — same
        // contract as save_elementor_data().
        update_post_meta( $post_id, $meta_key, wp_slash( $decoded ) );

        // ── Read-back verification ─────────────────────────────────────────────
        // update_post_meta() returns false on a rejected write (oversized payload
        // hitting max_allowed_packet, a filter veto, an unchanged value) and the old
        // code neither checked it nor re-read the row — it reported "written" plus
        // the sha of what it MEANT to store. A silently dropped write then looked
        // like the value had reverted on its own (tapadum, 2026-08-25).
        wp_cache_delete( $post_id, 'post_meta' );
        $stored     = (string) get_post_meta( $post_id, $meta_key, true );
        $want_sha   = substr( hash( 'sha256', $decoded ), 0, 16 );
        $stored_sha = substr( hash( 'sha256', $stored ), 0, 16 );
        $verified   = ( $stored_sha === $want_sha );

        if ( ! $verified ) {
            LuwiPress_Logger::log( sprintf(
                'Elementor: raw post_meta write for post #%d key=%s NOT VERIFIED — wanted %d bytes (%s), stored %d bytes (%s). Prior value preserved at %s.',
                $post_id, $meta_key, strlen( $decoded ), $want_sha, strlen( $stored ), $stored_sha, $backup_key
            ), 'error', array( 'post_id' => $post_id, 'meta_key' => $meta_key ) );

            return new WP_Error(
                'write_not_verified',
                sprintf(
                    'Write did not land: expected %d bytes (sha %s) but the row holds %d bytes (sha %s). Nothing was changed; prior value is backed up at %s.',
                    strlen( $decoded ), $want_sha, strlen( $stored ), $stored_sha, $backup_key
                ),
                array(
                    'status'         => 500,
                    'post_id'        => $post_id,
                    'meta_key'       => $meta_key,
                    'expected_sha16' => $want_sha,
                    'stored_sha16'   => $stored_sha,
                    'backup_key'     => $backup_key,
                )
            );
        }

        // For _elementor_data writes, regenerate Elementor CSS + purge page cache.
        // Only after the bytes are confirmed on disk — regenerating around a failed
        // write just republishes the stale render.
        if ( $meta_key === '_elementor_data' ) {
            $this->regenerate_css( $post_id );
            $this->purge_page_cache_for_post( $post_id );
        }

        LuwiPress_Logger::log( sprintf(
            'Elementor: raw post_meta written for post #%d key=%s len=%d backup=%s (verified)',
            $post_id, $meta_key, strlen( $decoded ), $backup_key
        ), 'warning' );

        return array(
            'status'      => 'written',
            'verified'    => true,
            'post_id'     => $post_id,
            'meta_key'    => $meta_key,
            'bytes'       => strlen( $stored ),
            'sha256_16'   => $stored_sha,
            'backup_key'  => $backup_key,
        );
    }

    /**
     * REST: GET /post-meta/raw?post_id=X&meta_key=Y
     */
    public function rest_post_meta_raw_get( $request ) {
        $post_id  = intval( $request->get_param( 'post_id' ) );
        $meta_key = sanitize_text_field( $request->get_param( 'meta_key' ) ?? '' );

        if ( ! $post_id || ! $meta_key ) {
            return new WP_Error( 'missing_params', 'post_id and meta_key required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->read_post_meta_raw( $post_id, $meta_key ) );
    }

    /**
     * REST: POST /post-meta/raw  body: {post_id, meta_key, value_b64, confirm_token}
     */
    public function rest_post_meta_raw_set( $request ) {
        $post_id       = intval( $request->get_param( 'post_id' ) );
        $meta_key      = sanitize_text_field( $request->get_param( 'meta_key' ) ?? '' );
        $value_b64     = (string) ( $request->get_param( 'value_b64' ) ?? '' );
        $confirm_token = sanitize_text_field( $request->get_param( 'confirm_token' ) ?? '' );

        if ( ! $post_id || ! $meta_key || $value_b64 === '' ) {
            return new WP_Error( 'missing_params', 'post_id, meta_key, value_b64 required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->write_post_meta_raw( $post_id, $meta_key, $value_b64, $confirm_token ) );
    }

    /**
     * Revert a post to its pre-translation revision.
     *
     * Uses the WP revision saved before translate_page() ran.
     *
     * @param int $post_id Post ID.
     * @return bool True if reverted, false if no revision found.
     */
    public function revert_to_pre_translation_revision( $post_id ) {
        $revision_id = get_post_meta( $post_id, '_luwipress_pre_translation_revision', true );
        if ( ! $revision_id ) {
            return false;
        }

        wp_restore_post_revision( $revision_id );
        delete_post_meta( $post_id, '_luwipress_pre_translation_revision' );

        LuwiPress_Logger::log( sprintf( 'Reverted post #%d to pre-translation revision #%d', $post_id, $revision_id ), 'info' );
        return true;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  AUTO-FIX — Automatic spacing/style issue resolution
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Auto-fix common spacing/styling issues on a page.
     *
     * Runs audit, then automatically fixes what it can:
     * - Excessive spacing (>100px) → cap at reasonable values
     * - Missing mobile overrides for large paddings → add proportional mobile values
     * - Asymmetric horizontal padding → optionally equalize
     *
     * Always creates a snapshot before making changes.
     *
     * @param int   $post_id Post ID.
     * @param array $options Fix options:
     *                       - 'fix_excessive' => bool (default: true) — cap extreme values
     *                       - 'fix_mobile'    => bool (default: true) — add mobile overrides
     *                       - 'max_padding'   => int (default: 80) — max padding in px
     *                       - 'mobile_ratio'  => float (default: 0.5) — mobile = desktop * ratio
     * @return array|WP_Error Fix results.
     */
    public function auto_fix_spacing( $post_id, array $options = array() ) {
        $fix_excessive = $options['fix_excessive'] ?? true;
        $fix_mobile    = $options['fix_mobile'] ?? true;
        $max_padding   = intval( $options['max_padding'] ?? 80 );
        $mobile_ratio  = floatval( $options['mobile_ratio'] ?? 0.5 );

        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // Snapshot before fixing
        $this->create_snapshot( $post_id, 'Pre-autofix backup' );

        $fixes = array();

        $data = $this->walk_elements_modify( $data, function ( &$element ) use (
            $fix_excessive, $fix_mobile, $max_padding, $mobile_ratio, &$fixes
        ) {
            $el_id   = $element['id'] ?? '';
            $settings = &$element['settings'];

            foreach ( array( '_padding', '_margin' ) as $prop ) {
                if ( ! isset( $settings[ $prop ] ) || ! is_array( $settings[ $prop ] ) ) {
                    continue;
                }

                $d    = &$settings[ $prop ];
                $unit = $d['unit'] ?? 'px';

                if ( $unit !== 'px' ) {
                    continue;
                }

                $changed = false;

                // Fix excessive values
                if ( $fix_excessive ) {
                    foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                        $val = floatval( $d[ $side ] ?? 0 );
                        if ( abs( $val ) > $max_padding ) {
                            $old = $d[ $side ];
                            $d[ $side ] = strval( $val > 0 ? $max_padding : -$max_padding );
                            $changed = true;
                            $fixes[] = array(
                                'element_id' => $el_id,
                                'fix'        => 'capped_' . $prop,
                                'detail'     => sprintf( '%s: %s→%s', $side, $old, $d[ $side ] ),
                            );
                        }
                    }
                }

                // Add mobile overrides if desktop padding is large
                if ( $fix_mobile && $prop === '_padding' && ! isset( $settings['_padding_mobile'] ) ) {
                    $top = floatval( $d['top'] ?? 0 );
                    if ( $top > 30 ) {
                        $settings['_padding_mobile'] = array(
                            'top'      => strval( round( floatval( $d['top'] ?? 0 ) * $mobile_ratio ) ),
                            'right'    => strval( round( floatval( $d['right'] ?? 0 ) * $mobile_ratio ) ),
                            'bottom'   => strval( round( floatval( $d['bottom'] ?? 0 ) * $mobile_ratio ) ),
                            'left'     => strval( round( floatval( $d['left'] ?? 0 ) * $mobile_ratio ) ),
                            'unit'     => 'px',
                            'isLinked' => $d['isLinked'] ?? false,
                        );
                        $fixes[] = array(
                            'element_id' => $el_id,
                            'fix'        => 'added_mobile_padding',
                            'detail'     => sprintf(
                                'mobile: %spx %spx %spx %spx (%.0f%% of desktop)',
                                $settings['_padding_mobile']['top'],
                                $settings['_padding_mobile']['right'],
                                $settings['_padding_mobile']['bottom'],
                                $settings['_padding_mobile']['left'],
                                $mobile_ratio * 100
                            ),
                        );
                    }
                }
            }
        } );

        if ( empty( $fixes ) ) {
            return array(
                'status'    => 'no_changes',
                'post_id'   => $post_id,
                'message'   => 'No spacing issues found that need auto-fixing',
                'fix_count' => 0,
            );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log(
            sprintf( 'Elementor: auto-fixed %d spacing issues in post #%d', count( $fixes ), $post_id ),
            'info'
        );

        return array(
            'status'    => 'fixed',
            'post_id'   => $post_id,
            'fix_count' => count( $fixes ),
            'fixes'     => $fixes,
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  RESPONSIVE AUDIT — Typography, spacing, overflow detection
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Audit responsive design issues on an Elementor page.
     *
     * Checks:
     * - Large font sizes without mobile overrides
     * - Fixed widths that could cause horizontal overflow
     * - Large padding/margin without mobile scaling
     * - Images without responsive sizing
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error Responsive audit report.
     */
    public function audit_responsive( $post_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $issues = array();

        $this->walk_elements( $data, function ( $element ) use ( &$issues ) {
            $el_id   = $element['id'] ?? '';
            $el_type = $element['elType'] ?? '';
            $wtype   = $element['widgetType'] ?? $el_type;
            $s       = $element['settings'] ?? array();

            // 1. Large font-size without mobile override
            if ( ! empty( $s['typography_font_size'] ) && is_array( $s['typography_font_size'] ) ) {
                $size = floatval( $s['typography_font_size']['size'] ?? 0 );
                $unit = $s['typography_font_size']['unit'] ?? 'px';
                if ( $unit === 'px' && $size > 28 && empty( $s['typography_font_size_mobile'] ) ) {
                    $issues[] = array(
                        'type'       => 'large_font_no_mobile',
                        'severity'   => 'warning',
                        'element_id' => $el_id,
                        'el_type'    => $wtype,
                        'detail'     => sprintf( 'font-size: %spx — no mobile override, will be too large on phones', $size ),
                        'suggestion' => sprintf( '{"font-size": "%dpx"}', max( 16, round( $size * 0.6 ) ) ),
                    );
                }
            }

            // 2. Large padding without mobile override (reuse from spacing audit)
            if ( ! empty( $s['_padding'] ) && is_array( $s['_padding'] ) && empty( $s['_padding_mobile'] ) ) {
                $p = $s['_padding'];
                if ( ( $p['unit'] ?? 'px' ) === 'px' ) {
                    $max_val = max( floatval( $p['top'] ?? 0 ), floatval( $p['bottom'] ?? 0 ) );
                    if ( $max_val > 40 ) {
                        $issues[] = array(
                            'type'       => 'large_padding_no_mobile',
                            'severity'   => 'warning',
                            'element_id' => $el_id,
                            'el_type'    => $wtype,
                            'detail'     => sprintf( 'padding top/bottom: %spx — no mobile override', $max_val ),
                            'suggestion' => sprintf( '{"padding": "%dpx"}', round( $max_val * 0.5 ) ),
                        );
                    }
                }
            }

            // 3. Large margin without mobile override
            if ( ! empty( $s['_margin'] ) && is_array( $s['_margin'] ) && empty( $s['_margin_mobile'] ) ) {
                $m = $s['_margin'];
                if ( ( $m['unit'] ?? 'px' ) === 'px' ) {
                    $max_val = max( floatval( $m['top'] ?? 0 ), floatval( $m['bottom'] ?? 0 ) );
                    if ( $max_val > 50 ) {
                        $issues[] = array(
                            'type'       => 'large_margin_no_mobile',
                            'severity'   => 'info',
                            'element_id' => $el_id,
                            'el_type'    => $wtype,
                            'detail'     => sprintf( 'margin top/bottom: %spx — no mobile override', $max_val ),
                        );
                    }
                }
            }

            // 4. Fixed column width on sections (non-responsive)
            if ( $el_type === 'column' && ! empty( $s['_inline_size'] ) ) {
                $col_size = floatval( $s['_inline_size'] );
                if ( $col_size > 0 && $col_size < 50 && empty( $s['_inline_size_mobile'] ) ) {
                    $issues[] = array(
                        'type'       => 'narrow_column_no_mobile',
                        'severity'   => 'warning',
                        'element_id' => $el_id,
                        'el_type'    => 'column',
                        'detail'     => sprintf( 'Column width: %s%% — will be cramped on mobile without override', $col_size ),
                        'suggestion' => 'Set mobile column width to 100%',
                    );
                }
            }

            // 5. Heading/text with very large line-height (can cause mobile issues)
            if ( ! empty( $s['typography_line_height'] ) && is_array( $s['typography_line_height'] ) ) {
                $lh = $s['typography_line_height'];
                if ( ( $lh['unit'] ?? '' ) === 'px' && floatval( $lh['size'] ?? 0 ) > 60 && empty( $s['typography_line_height_mobile'] ) ) {
                    $issues[] = array(
                        'type'       => 'large_lineheight_no_mobile',
                        'severity'   => 'info',
                        'element_id' => $el_id,
                        'el_type'    => $wtype,
                        'detail'     => sprintf( 'line-height: %spx — no mobile override', $lh['size'] ),
                    );
                }
            }
        } );

        // Sort by severity
        $severity_order = array( 'warning' => 0, 'info' => 1 );
        usort( $issues, function ( $a, $b ) use ( $severity_order ) {
            return ( $severity_order[ $a['severity'] ] ?? 9 ) - ( $severity_order[ $b['severity'] ] ?? 9 );
        } );

        return array(
            'post_id'     => $post_id,
            'issue_count' => count( $issues ),
            'issues'      => $issues,
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  REST + Structural Helpers
     * ═══════════════════════════════════════════════════════════════════ */

    /* ──────────────────── Structural Helpers ─────────────────────────── */

    /**
     * Recursively remove an element from the tree.
     */
    private function remove_element_recursive( array $elements, $target_id, &$found ) {
        $result = array();
        foreach ( $elements as $element ) {
            if ( ( $element['id'] ?? '' ) === $target_id ) {
                $found = true;
                continue; // skip = delete
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->remove_element_recursive( $element['elements'], $target_id, $found );
            }
            $result[] = $element;
        }
        return $result;
    }

    /**
     * Extract (remove + return) an element from the tree.
     */
    private function extract_element_recursive( array $elements, $target_id, &$extracted ) {
        $result = array();
        foreach ( $elements as $element ) {
            if ( ( $element['id'] ?? '' ) === $target_id ) {
                $extracted = $element;
                continue;
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->extract_element_recursive( $element['elements'], $target_id, $extracted );
            }
            $result[] = $element;
        }
        return $result;
    }

    /**
     * Find an element and its parent context.
     */
    private function find_element_with_context( array $elements, $target_id, &$found, &$parent_id, &$index, $current_parent = null ) {
        foreach ( $elements as $idx => $element ) {
            if ( ( $element['id'] ?? '' ) === $target_id ) {
                $found     = $element;
                $parent_id = $current_parent;
                $index     = $idx;
                return;
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->find_element_with_context( $element['elements'], $target_id, $found, $parent_id, $index, $element['id'] );
                if ( $found ) {
                    return;
                }
            }
        }
    }

    /**
     * Deep clone an element tree, assigning new unique IDs.
     */
    private function deep_clone_element( array $element ) {
        $element['id'] = $this->generate_element_id();

        if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
            foreach ( $element['elements'] as &$child ) {
                $child = $this->deep_clone_element( $child );
            }
            unset( $child );
        }

        return $element;
    }

    /**
     * Generate a unique Elementor element ID (7-char hex like Elementor uses).
     */
    private function generate_element_id() {
        return substr( md5( wp_generate_uuid4() ), 0, 7 );
    }

    /**
     * Deep clone the entire top-level _elementor_data array with fresh IDs.
     *
     * @param array $data Top-level elementor data.
     * @return array Cloned data with new element IDs throughout.
     */
    private function deep_clone_tree( array $data ) {
        $cloned = array();
        foreach ( $data as $element ) {
            if ( is_array( $element ) ) {
                $cloned[] = $this->deep_clone_element( $element );
            }
        }
        return $cloned;
    }

    /**
     * Build a map of position_path => widget texts from an Elementor tree.
     * Position paths look like "/0/0/2" — section index / column index / widget index.
     *
     * @param array  $elements Elementor elements array.
     * @param string $prefix   Parent path prefix.
     * @return array Map of path => [widget_type, texts].
     */
    private function build_text_map_by_position( array $elements, $prefix = '' ) {
        $map = array();
        foreach ( $elements as $idx => $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            $path = $prefix . '/' . $idx;
            $el_type   = $element['elType'] ?? '';
            $widget_type = $element['widgetType'] ?? '';

            if ( $el_type === 'widget' && $widget_type ) {
                $texts = $this->extract_widget_texts( $widget_type, $element['settings'] ?? array() );
                if ( ! empty( $texts ) ) {
                    $map[ $path ] = array(
                        'widget_type' => $widget_type,
                        'texts'       => $texts,
                    );
                }
            }

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $child_map = $this->build_text_map_by_position( $element['elements'], $path );
                $map = array_merge( $map, $child_map );
            }
        }
        return $map;
    }

    /**
     * Apply a position-based text map back onto a cloned tree.
     * Only overrides texts where widget_type matches at the same position.
     *
     * @param array  $elements Tree to modify (by reference).
     * @param array  $text_map Position path => [widget_type, texts].
     * @param string $prefix   Parent path prefix.
     * @return int Count of text fields preserved.
     */
    private function apply_text_map_to_tree( array &$elements, array $text_map, $prefix = '' ) {
        $count = 0;
        foreach ( $elements as $idx => &$element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            $path = $prefix . '/' . $idx;
            $el_type   = $element['elType'] ?? '';
            $widget_type = $element['widgetType'] ?? '';

            if ( $el_type === 'widget' && $widget_type && isset( $text_map[ $path ] ) ) {
                $saved = $text_map[ $path ];
                if ( ( $saved['widget_type'] ?? '' ) === $widget_type && ! empty( $saved['texts'] ) ) {
                    if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
                        $element['settings'] = array();
                    }
                    foreach ( $saved['texts'] as $key => $value ) {
                        $element['settings'][ $key ] = $value;
                        $count++;
                    }
                }
            }

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $count += $this->apply_text_map_to_tree( $element['elements'], $text_map, $path );
            }
        }
        unset( $element );
        return $count;
    }

    /**
     * Resolve target post IDs from either explicit IDs or post_type query.
     *
     * @param array|null $post_ids  Explicit post IDs.
     * @param string     $post_type Post type to query (when post_ids empty).
     * @param int        $limit     Max posts when querying by type.
     * @return array|WP_Error Array of post IDs or error.
     */
    private function resolve_post_ids( $post_ids, $post_type = '', $limit = 100 ) {
        if ( is_array( $post_ids ) && ! empty( $post_ids ) ) {
            return array_map( 'intval', $post_ids );
        }

        if ( empty( $post_type ) ) {
            return new WP_Error( 'missing_params', 'Either post_ids or post_type required', array( 'status' => 400 ) );
        }

        $query = new WP_Query( array(
            'post_type'      => sanitize_key( $post_type ),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_elementor_data',
                    'compare' => 'EXISTS',
                ),
            ),
            'no_found_rows'  => true,
        ) );

        if ( empty( $query->posts ) ) {
            return new WP_Error( 'no_elementor_pages', 'No Elementor pages found for this post type', array( 'status' => 404 ) );
        }

        return array_map( 'intval', $query->posts );
    }

    /**
     * Upsert entries into a token array (for css-vars).
     *
     * @param array  $existing Existing token array.
     * @param array  $updates  Updates to apply.
     * @param string $key_field Field to match by (e.g., '_id').
     * @return array [$new_array, $updated_count, $added_count]
     */
    private function upsert_token_array( array $existing, array $updates, $key_field = '_id' ) {
        $updated = 0;
        $added   = 0;

        foreach ( $updates as $update ) {
            if ( ! is_array( $update ) || empty( $update[ $key_field ] ) ) {
                continue;
            }
            $id = $update[ $key_field ];
            $found = false;
            foreach ( $existing as $i => $item ) {
                if ( ( $item[ $key_field ] ?? '' ) === $id ) {
                    $existing[ $i ] = array_merge( $item, $update );
                    $updated++;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $existing[] = $update;
                $added++;
            }
        }

        return array( $existing, $updated, $added );
    }

    /* ──────────────────── Inspect endpoints (3.1.40) ──────────────────── */

    /**
     * GET /elementor/outline-deep/{post_id}
     *
     * Deep variant of the outline. Walks every section, column, container, and
     * widget, returning a tree with type + tag + bg color + text preview info.
     * Caps text preview at 80 chars; entire payload at 64 KB to keep responses
     * manageable.
     */
    public function rest_outline_deep( $request ) {
        $post_id          = intval( $request['post_id'] );
        $include_settings = (bool) $request->get_param( 'include_settings' );
        $include_bg_info  = $request->get_param( 'include_bg_info' );
        $include_bg_info  = ( null === $include_bg_info ) ? true : (bool) $include_bg_info;
        $preview_chars    = max( 20, min( 200, intval( $request->get_param( 'preview_chars' ) ?: 80 ) ) );

        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $build = function ( array $elements ) use ( &$build, $include_settings, $include_bg_info, $preview_chars ) {
            $out = array();
            foreach ( $elements as $el ) {
                $node = array(
                    'id'      => $el['id'] ?? '',
                    'el_type' => $el['elType'] ?? '',
                );
                $widget_type = $el['widgetType'] ?? null;
                if ( $widget_type ) {
                    $node['widget_type'] = $widget_type;
                }
                $settings = $el['settings'] ?? array();
                if ( $widget_type ) {
                    $texts = $this->extract_widget_texts( $widget_type, $settings );
                    if ( ! empty( $texts ) ) {
                        $first = reset( $texts );
                        $node['text_preview'] = mb_substr( wp_strip_all_tags( (string) $first ), 0, $preview_chars );
                    }
                }
                if ( $include_bg_info ) {
                    $bg = array();
                    if ( ! empty( $settings['background_color'] ) ) {
                        $bg['color'] = $settings['background_color'];
                    }
                    if ( ! empty( $settings['background_image']['url'] ) ) {
                        $bg['image'] = $settings['background_image']['url'];
                    }
                    if ( ! empty( $settings['background_overlay_color'] ) ) {
                        $bg['overlay'] = $settings['background_overlay_color'];
                    }
                    if ( ! empty( $bg ) ) {
                        $node['background'] = $bg;
                    }
                }
                if ( $include_settings ) {
                    $node['settings'] = $settings;
                }
                if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                    $node['children'] = $build( $el['elements'] );
                }
                $out[] = $node;
            }
            return $out;
        };

        $tree = $build( $data );
        $post = get_post( $post_id );

        return rest_ensure_response( array(
            'post_id'   => $post_id,
            'title'     => $post ? $post->post_title : '',
            'post_type' => $post ? $post->post_type : '',
            'tree'      => $tree,
        ) );
    }

    /**
     * GET /elementor/find/{post_id}/{element_id}
     *
     * Locate one element in the page tree, returning its full ancestor chain
     * (root → element), type, widget_type, text preview, and settings summary.
     */
    public function rest_find_by_id( $request ) {
        $post_id    = intval( $request['post_id'] );
        $element_id = sanitize_text_field( $request['element_id'] );

        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found = null;

        $walk = function ( array $elements, array $chain ) use ( &$walk, &$found, $element_id ) {
            foreach ( $elements as $el ) {
                $eid = $el['id'] ?? '';
                $next_chain = array_merge( $chain, array( array(
                    'id'          => $eid,
                    'el_type'     => $el['elType'] ?? '',
                    'widget_type' => $el['widgetType'] ?? null,
                ) ) );
                if ( $eid === $element_id ) {
                    $found = array(
                        'element'        => $el,
                        'ancestor_chain' => $chain,
                        'full_path'      => $next_chain,
                    );
                    return;
                }
                if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) && null === $found ) {
                    $walk( $el['elements'], $next_chain );
                }
            }
        };

        $walk( $data, array() );

        if ( null === $found ) {
            return new WP_Error( 'not_found', sprintf( 'Element %s not found in post %d', $element_id, $post_id ), array( 'status' => 404 ) );
        }

        $el          = $found['element'];
        $widget_type = $el['widgetType'] ?? null;
        $settings    = $el['settings'] ?? array();
        $resp        = array(
            'post_id'        => $post_id,
            'element_id'     => $element_id,
            'el_type'        => $el['elType'] ?? '',
            'widget_type'    => $widget_type,
            'ancestor_chain' => $found['ancestor_chain'],
            'full_path'      => $found['full_path'],
            'style_summary'  => $this->summarize_styles( $settings, $widget_type ?: '' ),
        );
        if ( $widget_type ) {
            $texts = $this->extract_widget_texts( $widget_type, $settings );
            if ( ! empty( $texts ) ) {
                $resp['texts'] = $texts;
            }
        }
        $resp['child_count'] = ! empty( $el['elements'] ) ? count( $el['elements'] ) : 0;
        return rest_ensure_response( $resp );
    }

    /**
     * POST /elementor/find-by-text
     * Body: { post_id, text, match: 'contains'|'exact'|'starts'|'ends' (default contains) }
     *
     * Walks every widget, runs extract_widget_texts, and returns matches with
     * ancestor chain so callers can locate the element without DOM scraping.
     */
    public function rest_find_by_text( $request ) {
        $post_id = intval( $request->get_param( 'post_id' ) );
        $needle  = (string) $request->get_param( 'text' );
        $match   = sanitize_text_field( $request->get_param( 'match' ) ?: 'contains' );
        $limit   = max( 1, min( 50, intval( $request->get_param( 'limit' ) ?: 20 ) ) );
        $case_sensitive = (bool) $request->get_param( 'case_sensitive' );

        if ( ! $post_id || '' === $needle ) {
            return new WP_Error( 'missing_params', 'post_id and text are required', array( 'status' => 400 ) );
        }

        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $cmp = function ( $haystack ) use ( $needle, $match, $case_sensitive ) {
            $hay = (string) $haystack;
            if ( ! $case_sensitive ) {
                $hay    = mb_strtolower( $hay );
                $needle = mb_strtolower( $needle );
            }
            switch ( $match ) {
                case 'exact':
                    return $hay === $needle;
                case 'starts':
                    return 0 === mb_strpos( $hay, $needle );
                case 'ends':
                    return mb_strlen( $hay ) >= mb_strlen( $needle ) && mb_substr( $hay, -mb_strlen( $needle ) ) === $needle;
                case 'contains':
                default:
                    return false !== mb_strpos( $hay, $needle );
            }
        };

        $matches = array();

        $walk = function ( array $elements, array $chain ) use ( &$walk, &$matches, $cmp, $limit ) {
            foreach ( $elements as $el ) {
                if ( count( $matches ) >= $limit ) {
                    return;
                }
                $eid          = $el['id'] ?? '';
                $widget_type  = $el['widgetType'] ?? null;
                $settings     = $el['settings'] ?? array();
                $next_chain   = array_merge( $chain, array( array(
                    'id'          => $eid,
                    'el_type'     => $el['elType'] ?? '',
                    'widget_type' => $widget_type,
                ) ) );
                if ( $widget_type ) {
                    $texts = $this->extract_widget_texts( $widget_type, $settings );
                    foreach ( $texts as $field => $val ) {
                        $stripped = wp_strip_all_tags( (string) $val );
                        if ( $cmp( $stripped ) || $cmp( (string) $val ) ) {
                            $matches[] = array(
                                'element_id'     => $eid,
                                'el_type'        => $el['elType'] ?? '',
                                'widget_type'    => $widget_type,
                                'field'          => $field,
                                'preview'        => mb_substr( $stripped, 0, 200 ),
                                'ancestor_chain' => $chain,
                            );
                            break;
                        }
                    }
                }
                if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                    $walk( $el['elements'], $next_chain );
                }
            }
        };

        $walk( $data, array() );

        return rest_ensure_response( array(
            'post_id'        => $post_id,
            'needle'         => $needle,
            'match'          => $match,
            'case_sensitive' => $case_sensitive,
            'count'          => count( $matches ),
            'matches'        => $matches,
        ) );
    }

    /**
     * POST /elementor/kit-css/preflight
     * Body: { candidate_css: string }
     *
     * Estimates whether the candidate Kit CSS would fit under the option size
     * limit. Detects existing layer markers and reports redundancy candidates
     * so callers can decide what to strip before pushing.
     *
     * Limit estimate is conservative (~412 KB observed in production); real
     * MariaDB longtext is much larger but the option storage path silently
     * truncates well below that on some hosts.
     */
    public function rest_kit_css_preflight( $request ) {
        $candidate = (string) $request->get_param( 'candidate_css' );
        if ( '' === $candidate ) {
            return new WP_Error( 'missing_params', 'candidate_css is required', array( 'status' => 400 ) );
        }

        $current_live = (string) get_option( 'luwipress_kit_css', '' );
        $candidate_size  = strlen( $candidate );
        $live_size       = strlen( $current_live );
        $option_limit    = 412000; // conservative threshold per production observation
        $would_fit       = $candidate_size <= $option_limit;
        $headroom_bytes  = $option_limit - $candidate_size;

        // Detect existing layer markers in the live CSS as removal candidates
        $candidates = array();
        $patterns = array(
            'PERCUSSIONS-FIX-V2' => '#/\* PERCUSSIONS-FIX-V2.*?/\* /PERCUSSIONS-FIX-V2 \*/#s',
            'V47'                => '#/\* V47 BEGIN.*?/\* end V47 \*/#s',
            'V48'                => '#/\* V48 BEGIN.*?/\* end V48 \*/#s',
            'V49'                => '#/\* V49 BEGIN.*?/\* end V49 \*/#s',
            'V50'                => '#/\* V50 BEGIN.*?/\* end V50 \*/#s',
        );
        foreach ( $patterns as $label => $pattern ) {
            if ( preg_match( $pattern, $current_live, $m ) ) {
                $candidates[] = array(
                    'label' => $label,
                    'size'  => strlen( $m[0] ),
                    'note'  => 'Removable layer; check whether the rules it owns are still in use before stripping.',
                );
            }
        }

        // Scan candidate for paired angle-bracket comments which wp_strip_all_tags can eat
        $angle_pairs = preg_match_all( '/<[^>]+>/', $candidate, $m );

        return rest_ensure_response( array(
            'candidate_size'    => $candidate_size,
            'current_live_size' => $live_size,
            'option_limit'      => $option_limit,
            'would_fit'         => $would_fit,
            'headroom_bytes'    => $headroom_bytes,
            'angle_pair_count'  => intval( $angle_pairs ),
            'angle_pair_warning' => $angle_pairs > 0 ? 'Candidate contains <...> pairs. wp_strip_all_tags may eat content between them at write time.' : null,
            'savings_candidates' => $candidates,
        ) );
    }

    /* ───── FR-suite: bulk read / custom-css read / image / advanced / schema / diff ───── */

    /**
     * FR 1: Read multiple Elementor widgets in a single call.
     *
     * @param int   $post_id     Post ID.
     * @param array $element_ids Array of element IDs to fetch.
     * @return array|WP_Error    {found: {id => widget}, missing: [id, ...]} OR WP_Error
     */
    public function get_widgets_bulk( $post_id, array $element_ids ) {
        $tree = $this->get_widget_tree( $post_id );
        if ( is_wp_error( $tree ) ) {
            return $tree;
        }
        $by_id = array();
        foreach ( $tree as $el ) {
            $by_id[ $el['id'] ] = $el;
        }
        $found   = array();
        $missing = array();
        foreach ( $element_ids as $eid ) {
            $eid = sanitize_text_field( $eid );
            if ( isset( $by_id[ $eid ] ) ) {
                $found[ $eid ] = $by_id[ $eid ];
            } else {
                $missing[] = $eid;
            }
        }
        return array(
            'post_id' => intval( $post_id ),
            'found'   => $found,
            'missing' => $missing,
            'count'   => count( $found ),
        );
    }

    /**
     * FR 2: Read element-level or page-level custom CSS.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Optional element ID. If empty, returns page-level CSS.
     * @return array|WP_Error
     */
    public function get_custom_css( $post_id, $element_id = '' ) {
        if ( $element_id === '' ) {
            $page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
            $css = is_array( $page_settings ) ? ( $page_settings['custom_css'] ?? '' ) : '';
            return array(
                'post_id' => intval( $post_id ),
                'scope'   => 'page',
                'css'     => (string) $css,
                'length'  => strlen( (string) $css ),
            );
        }
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $found_css = null;
        $this->walk_elements( $data, function ( $element ) use ( $element_id, &$found_css ) {
            if ( ( $element['id'] ?? '' ) === $element_id ) {
                $found_css = $element['settings']['custom_css'] ?? '';
            }
        } );
        if ( $found_css === null ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }
        return array(
            'post_id'    => intval( $post_id ),
            'scope'      => 'element',
            'element_id' => $element_id,
            'css'        => (string) $found_css,
            'length'     => strlen( (string) $found_css ),
        );
    }

    /**
     * FR 3: Reflect an Elementor widget's settings schema.
     *
     * @param string $widget_type Widget type slug (e.g. 'heading', 'lwp-section-head').
     * @return array|WP_Error
     */
    public function get_widget_schema( $widget_type ) {
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return new WP_Error( 'elementor_inactive', 'Elementor is not active', array( 'status' => 503 ) );
        }
        $widget_type = sanitize_text_field( $widget_type );
        $widget_obj  = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget_type );
        if ( ! $widget_obj ) {
            return new WP_Error( 'widget_not_found', 'Widget type not registered: ' . $widget_type, array( 'status' => 404 ) );
        }
        $controls = $widget_obj->get_controls();
        $fields   = array();
        $sections = array();
        $current_section = '';
        // Elementor Controls_Manager::SECTION = 'section', REPEATER = 'repeater'.
        // Use string equivalents so PHPStan does not require the external class.
        $type_section  = 'section';
        $type_repeater = 'repeater';
        foreach ( $controls as $key => $ctrl ) {
            $type = $ctrl['type'] ?? '';
            if ( $type === $type_section ) {
                $current_section = $ctrl['label'] ?? $key;
                $sections[ $key ] = array(
                    'label' => $current_section,
                    'tab'   => $ctrl['tab'] ?? 'content',
                );
                continue;
            }
            $field = array(
                'key'     => $key,
                'type'    => $type,
                'label'   => $ctrl['label'] ?? '',
                'default' => $ctrl['default'] ?? null,
                'section' => $current_section,
                'tab'     => $ctrl['tab'] ?? 'content',
            );
            if ( ! empty( $ctrl['options'] ) ) {
                $field['options'] = $ctrl['options'];
            }
            if ( isset( $ctrl['required'] ) ) {
                $field['required'] = (bool) $ctrl['required'];
            }
            if ( isset( $ctrl['description'] ) ) {
                $field['description'] = $ctrl['description'];
            }
            // Repeater introspection: expose nested control fields too.
            if ( $type === $type_repeater && ! empty( $ctrl['fields'] ) ) {
                $field['repeater_fields'] = array();
                foreach ( $ctrl['fields'] as $sub_key => $sub_ctrl ) {
                    $field['repeater_fields'][] = array(
                        'key'     => $sub_key,
                        'type'    => $sub_ctrl['type'] ?? '',
                        'label'   => $sub_ctrl['label'] ?? '',
                        'default' => $sub_ctrl['default'] ?? null,
                    );
                }
            }
            $fields[] = $field;
        }
        return array(
            'widget_type' => $widget_type,
            'widget_name' => method_exists( $widget_obj, 'get_title' ) ? $widget_obj->get_title() : $widget_type,
            'categories'  => method_exists( $widget_obj, 'get_categories' ) ? $widget_obj->get_categories() : array(),
            'fields'      => $fields,
            'sections'    => $sections,
            'field_count' => count( $fields ),
        );
    }

    /**
     * FR 5: Set image on a widget — handles MEDIA control structure {url, id, alt, size, link}.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Widget ID.
     * @param array  $args       {url?, attachment_id?, alt?, size?, link?, image_field?}
     * @return array|WP_Error
     */
    public function set_widget_image( $post_id, $element_id, array $args ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $field = sanitize_text_field( $args['image_field'] ?? 'image' );
        $attachment_id = isset( $args['attachment_id'] ) ? intval( $args['attachment_id'] ) : 0;
        $url = $args['url'] ?? '';
        $alt = $args['alt'] ?? null;
        $size = sanitize_text_field( $args['size'] ?? '' );
        $link = $args['link'] ?? null;

        // Resolve URL from attachment_id when provided
        if ( $attachment_id && ! $url ) {
            $url = wp_get_attachment_url( $attachment_id );
            if ( ! $url ) {
                return new WP_Error( 'attachment_not_found', 'Attachment ID not found: ' . $attachment_id, array( 'status' => 404 ) );
            }
        }
        if ( ! $url && ! $attachment_id ) {
            return new WP_Error( 'missing_image', 'Either url or attachment_id required', array( 'status' => 400 ) );
        }

        $found = false;
        $widget_type_seen = '';
        $data = $this->walk_elements_modify( $data, function ( &$element ) use ( $element_id, $field, $url, $attachment_id, $alt, $size, $link, &$found, &$widget_type_seen ) {
            if ( ( $element['id'] ?? '' ) !== $element_id ) {
                return;
            }
            $found = true;
            $widget_type_seen = $element['widgetType'] ?? '';
            if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
                $element['settings'] = array();
            }
            $image_setting = isset( $element['settings'][ $field ] ) && is_array( $element['settings'][ $field ] )
                ? $element['settings'][ $field ]
                : array();
            $image_setting['url'] = esc_url_raw( $url );
            if ( $attachment_id ) {
                $image_setting['id'] = intval( $attachment_id );
            }
            if ( $alt !== null ) {
                $image_setting['alt'] = sanitize_text_field( $alt );
            }
            if ( $size ) {
                $image_setting['size'] = $size;
            }
            $element['settings'][ $field ] = $image_setting;
            if ( $link !== null && is_array( $link ) ) {
                $element['settings'][ 'link' ] = array(
                    'url'         => esc_url_raw( $link['url'] ?? '' ),
                    'is_external' => ! empty( $link['is_external'] ),
                    'nofollow'    => ! empty( $link['nofollow'] ),
                );
            }
            // Persist alt to attachment when both provided (best-effort)
            if ( $attachment_id && $alt !== null && $alt !== '' ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
            }
        } );
        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }
        $saved = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        return array(
            'status'        => 'updated',
            'post_id'       => intval( $post_id ),
            'element_id'    => $element_id,
            'widget_type'   => $widget_type_seen,
            'field'         => $field,
            'url'           => $url,
            'attachment_id' => $attachment_id,
        );
    }

    /**
     * FR 6: Write Elementor "Advanced" tab fields (css_classes, css_id, motion, z-index, attributes).
     *
     * Whitelist: only known advanced keys allowed.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element ID.
     * @param array  $advanced   Allowed keys: css_classes, custom_css_classes, css_id, _element_id,
     *                            _animation, _animation_delay, motion_fx_motion_fx_scrolling,
     *                            _z_index, _attributes, hide_desktop, hide_tablet, hide_mobile.
     * @return array|WP_Error
     */
    public function set_widget_advanced( $post_id, $element_id, array $advanced ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        // Whitelist of Elementor advanced-tab setting keys (internal Elementor naming).
        $whitelist = array(
            'css_classes', 'custom_css_classes', '_css_classes',
            '_element_id', 'css_id',
            '_animation', '_animation_delay',
            'motion_fx_motion_fx_scrolling', 'motion_fx_motion_fx_mouse',
            '_z_index',
            '_attributes',
            'hide_desktop', 'hide_tablet', 'hide_mobile',
            '_position', '_offset_orientation_h', '_offset_orientation_v',
            '_offset_x', '_offset_y',
        );

        // Aliases — friendly names → Elementor internal keys
        $aliases = array(
            'class'      => 'css_classes',
            'classes'    => 'css_classes',
            'id'         => '_element_id',
            'animation'  => '_animation',
            'animation_delay' => '_animation_delay',
            'z_index'    => '_z_index',
            'z-index'    => '_z_index',
            'attributes' => '_attributes',
        );

        $found = false;
        $applied = array();
        $rejected = array();
        $data = $this->walk_elements_modify( $data, function ( &$element ) use ( $element_id, $advanced, $whitelist, $aliases, &$found, &$applied, &$rejected ) {
            if ( ( $element['id'] ?? '' ) !== $element_id ) {
                return;
            }
            $found = true;
            if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
                $element['settings'] = array();
            }
            foreach ( $advanced as $k => $v ) {
                $key = isset( $aliases[ $k ] ) ? $aliases[ $k ] : $k;
                if ( ! in_array( $key, $whitelist, true ) ) {
                    $rejected[ $k ] = 'key not in advanced-tab whitelist';
                    continue;
                }
                if ( $key === 'css_classes' || $key === 'custom_css_classes' || $key === '_css_classes' ) {
                    $element['settings'][ $key ] = sanitize_text_field( $v );
                } elseif ( $key === '_attributes' ) {
                    // _attributes is a string in Elementor — "key|value, key2|value2"
                    $element['settings'][ $key ] = wp_kses_post( $v );
                } elseif ( is_numeric( $v ) ) {
                    $element['settings'][ $key ] = $v;
                } elseif ( is_array( $v ) ) {
                    $element['settings'][ $key ] = $v;
                } else {
                    $element['settings'][ $key ] = sanitize_text_field( (string) $v );
                }
                $applied[ $key ] = $element['settings'][ $key ];
            }
        } );
        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }
        $saved = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        return array(
            'status'     => 'updated',
            'post_id'    => intval( $post_id ),
            'element_id' => $element_id,
            'applied'    => $applied,
            'rejected'   => $rejected,
        );
    }

    /**
     * FR 7: Diff two snapshots (or one snapshot vs current).
     *
     * @param int    $post_id     Post ID.
     * @param string $snapshot_a  Snapshot ID (the "before" / baseline).
     * @param string $snapshot_b  Snapshot ID for "after" — if empty, compares against current.
     * @return array|WP_Error
     */
    public function snapshot_diff( $post_id, $snapshot_a, $snapshot_b = '' ) {
        $snapshots = get_post_meta( $post_id, '_luwipress_elementor_snapshots', true );
        if ( ! is_array( $snapshots ) ) {
            $snapshots = array();
        }
        // Snapshots use 'id' key (NOT 'snapshot_id'); payload is in 'data_b64' (base64-v1)
        // for new snapshots OR legacy 'data' (raw JSON string) for pre-3.1.42-hotfix3.
        $find_snapshot = function( $sid ) use ( $snapshots ) {
            foreach ( $snapshots as $s ) {
                if ( ( $s['id'] ?? $s['snapshot_id'] ?? '' ) === $sid ) {
                    return $s;
                }
            }
            return null;
        };
        $decode_snapshot_payload = function( $snap ) {
            // Prefer base64-encoded payload (slash-safe).
            if ( ! empty( $snap['data_b64'] ) ) {
                $raw = base64_decode( $snap['data_b64'], true );
                if ( is_string( $raw ) ) {
                    $decoded = json_decode( $raw, true );
                    if ( is_array( $decoded ) ) return $decoded;
                }
            }
            // Legacy raw-JSON payload fallback.
            if ( isset( $snap['data'] ) ) {
                if ( is_array( $snap['data'] ) ) return $snap['data'];
                if ( is_string( $snap['data'] ) ) {
                    $decoded = json_decode( $snap['data'], true );
                    if ( is_array( $decoded ) ) return $decoded;
                    // Try unslashing (Elementor stores with extra slashes sometimes)
                    $decoded = json_decode( wp_unslash( $snap['data'] ), true );
                    if ( is_array( $decoded ) ) return $decoded;
                }
            }
            return array();
        };

        $snap_a = $find_snapshot( $snapshot_a );
        if ( ! $snap_a ) {
            return new WP_Error( 'snapshot_not_found', 'Snapshot A not found: ' . $snapshot_a, array( 'status' => 404 ) );
        }
        $data_a = $decode_snapshot_payload( $snap_a );

        if ( $snapshot_b ) {
            $snap_b = $find_snapshot( $snapshot_b );
            if ( ! $snap_b ) {
                return new WP_Error( 'snapshot_not_found', 'Snapshot B not found: ' . $snapshot_b, array( 'status' => 404 ) );
            }
            $data_b  = $decode_snapshot_payload( $snap_b );
            $b_label = $snapshot_b;
        } else {
            $current = $this->get_elementor_data( $post_id );
            if ( is_wp_error( $current ) ) {
                return $current;
            }
            $data_b  = $current;
            $b_label = 'current';
        }

        // Flatten both trees to {id => {widget_type, settings_hash, settings}}
        $flatten = function( $tree ) {
            $out = array();
            $walker = function( $nodes ) use ( &$walker, &$out ) {
                if ( ! is_array( $nodes ) ) return;
                foreach ( $nodes as $node ) {
                    if ( ! is_array( $node ) ) continue;
                    $id = $node['id'] ?? '';
                    if ( $id !== '' ) {
                        $settings_json = isset( $node['settings'] ) ? wp_json_encode( $node['settings'] ) : '{}';
                        $out[ $id ] = array(
                            'el_type'       => $node['elType'] ?? '',
                            'widget_type'   => $node['widgetType'] ?? '',
                            'settings_hash' => md5( $settings_json ?: '' ),
                            'settings'      => $node['settings'] ?? array(),
                        );
                    }
                    if ( isset( $node['elements'] ) ) {
                        $walker( $node['elements'] );
                    }
                }
            };
            $walker( $tree );
            return $out;
        };

        $flat_a = $flatten( $data_a );
        $flat_b = $flatten( $data_b );

        $added    = array();
        $removed  = array();
        $modified = array();
        foreach ( $flat_b as $id => $b_el ) {
            if ( ! isset( $flat_a[ $id ] ) ) {
                $added[] = array( 'id' => $id, 'widget_type' => $b_el['widget_type'], 'el_type' => $b_el['el_type'] );
            } elseif ( $flat_a[ $id ]['settings_hash'] !== $b_el['settings_hash'] ) {
                $diffs = array();
                $a_set = $flat_a[ $id ]['settings'];
                $b_set = $b_el['settings'];
                $all_keys = array_unique( array_merge( array_keys( $a_set ), array_keys( $b_set ) ) );
                foreach ( $all_keys as $k ) {
                    $av = $a_set[ $k ] ?? null;
                    $bv = $b_set[ $k ] ?? null;
                    if ( $av !== $bv ) {
                        $diffs[] = array(
                            'field'  => $k,
                            'before' => is_scalar( $av ) ? $av : wp_json_encode( $av ),
                            'after'  => is_scalar( $bv ) ? $bv : wp_json_encode( $bv ),
                        );
                    }
                }
                $modified[] = array(
                    'id'          => $id,
                    'widget_type' => $b_el['widget_type'],
                    'el_type'     => $b_el['el_type'],
                    'changes'     => $diffs,
                );
            }
        }
        foreach ( $flat_a as $id => $a_el ) {
            if ( ! isset( $flat_b[ $id ] ) ) {
                $removed[] = array( 'id' => $id, 'widget_type' => $a_el['widget_type'], 'el_type' => $a_el['el_type'] );
            }
        }

        return array(
            'post_id'        => intval( $post_id ),
            'snapshot_a'     => $snapshot_a,
            'snapshot_b'     => $b_label,
            'a_element_count' => count( $flat_a ),
            'b_element_count' => count( $flat_b ),
            'added'          => $added,
            'removed'        => $removed,
            'modified'       => $modified,
            'summary'        => array(
                'added'    => count( $added ),
                'removed'  => count( $removed ),
                'modified' => count( $modified ),
            ),
        );
    }

    /* ───── FR REST handlers ───── */

    public function rest_get_widgets_bulk( $request ) {
        $post_id = intval( $request->get_param( 'post_id' ) );
        $ids     = $request->get_param( 'element_ids' );
        if ( ! is_array( $ids ) ) {
            return new WP_Error( 'invalid_params', 'element_ids must be an array', array( 'status' => 400 ) );
        }
        $result = $this->get_widgets_bulk( $post_id, $ids );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_get_custom_css( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) ?? '' );
        $result = $this->get_custom_css( $post_id, $element_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_get_widget_schema( $request ) {
        $widget_type = sanitize_text_field( $request->get_param( 'widget_type' ) ?? '' );
        if ( ! $widget_type ) {
            return new WP_Error( 'missing_params', 'widget_type required', array( 'status' => 400 ) );
        }
        $result = $this->get_widget_schema( $widget_type );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_set_widget_image( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) ?? '' );
        $args = array(
            'url'           => $request->get_param( 'url' ) ?? '',
            'attachment_id' => intval( $request->get_param( 'attachment_id' ) ?? 0 ),
            'alt'           => $request->get_param( 'alt' ),
            'size'          => $request->get_param( 'size' ) ?? '',
            'link'          => $request->get_param( 'link' ),
            'image_field'   => $request->get_param( 'image_field' ) ?? 'image',
        );
        if ( ! $post_id || ! $element_id ) {
            return new WP_Error( 'missing_params', 'post_id + element_id required', array( 'status' => 400 ) );
        }
        $result = $this->set_widget_image( $post_id, $element_id, $args );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_set_widget_advanced( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) ?? '' );
        $advanced   = $request->get_param( 'advanced' );
        if ( ! $post_id || ! $element_id || ! is_array( $advanced ) ) {
            return new WP_Error( 'missing_params', 'post_id, element_id, advanced (object) required', array( 'status' => 400 ) );
        }
        $result = $this->set_widget_advanced( $post_id, $element_id, $advanced );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_snapshot_diff( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $snapshot_a = sanitize_text_field( $request->get_param( 'snapshot_a' ) ?? '' );
        $snapshot_b = sanitize_text_field( $request->get_param( 'snapshot_b' ) ?? '' );
        if ( ! $post_id || ! $snapshot_a ) {
            return new WP_Error( 'missing_params', 'post_id + snapshot_a required', array( 'status' => 400 ) );
        }
        $result = $this->snapshot_diff( $post_id, $snapshot_a, $snapshot_b );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    /* ──────────────────── Static Helpers ─────────────────────────────── */

    /**
     * Check if a post has Elementor data.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function is_elementor_page( $post_id ) {
        return ! empty( get_post_meta( $post_id, '_elementor_data', true ) );
    }

    /**
     * Check if Elementor plugin is active.
     *
     * @return bool
     */
    public static function is_elementor_active() {
        return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );
    }
}
