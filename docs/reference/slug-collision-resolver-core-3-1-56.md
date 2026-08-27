# Slug Collision Resolver (core, 3.1.56+)

When a long-lived WooCommerce store has BOTH a `/<hub>/` page and a `/product-category/<hub>/` archive sharing the same slug, the page wins permalink resolution by default — visitors hitting the menu / search / bookmark land on the stale editorial page even though products live behind the archive. The core `LuwiPress_Slug_Resolver` (class file `includes/class-luwipress-slug-resolver.php`) runs a six-pass discovery (exact / WPML cross-language / plural-prefix fuzzy / Levenshtein-1 / menu-parent inheritance / empty-term ancestor fallback) and 301-redirects matching page URLs to their resolved product_cat archive at `template_redirect` priority 1. Was originally a `luwipress-gold` theme module; promoted to core so every theme inherits it for migrations.

**Toggle:** `update_option('luwipress_slug_resolver_enabled', true)` — falls back to legacy theme_mod `luwipress_gold_resolve_slug_conflicts` so theme-tier installs survive the upgrade without re-toggling.

**Diagnostic surface (cross-customer migration troubleshooting without server-side log access):**
- `GET /luwipress/v1/slug-resolver/diag` returns: toggle state, hook attachment, p1 callback count (conflict with other plugins/themes), map size, WPML/Polylang active, last build timestamp + duration + errors, transient state, sample slug probes (override default list with `probe[]` query param), legacy theme_mod value.
- `GET /luwipress/v1/slug-resolver/map` — full slug→target map preview (auto + overrides + composed).
- `POST /luwipress/v1/slug-resolver/rebuild` — bust transient + re-run six passes.
- `POST /luwipress/v1/slug-resolver/override` — explicit `slug → term_id | URL | true | false | null` override (wins over auto-discovery).
- `GET/POST /luwipress/v1/slug-resolver/settings` — toggle CRUD.

**MCP tools (webmcp 1.0.17+):** `slug_resolver_diag`, `slug_resolver_map`, `slug_resolver_force_rebuild`, `slug_resolver_override_set`, `slug_resolver_settings_set`.

**Theme compat:** `luwipress-gold/inc/template-redirects.php` returns early when `class_exists('LuwiPress_Slug_Resolver')` is true. The theme path remains intact for any install still running core ≤ 3.1.55.

**Customer migration playbook:** before a DNS swap, call `slug_resolver_diag` against the new site and confirm every operator-known page slug appears in `probe` with a resolved target. Any `in_map: false` slug = potential canonical / hreflang duplicate. Fix with `slug_resolver_override_set` for cases the six passes can't infer.
