# REST API (namespace: `luwipress/v1`)

### Core
- `GET  /status` — Plugin status
- `GET  /health` — Health check
- `GET  /logs` — Recent log entries

### AI Content
- `POST /product/enrich` — Trigger AI enrichment (Job Queue)
- `POST /product/enrich-batch` — Batch enrichment
- `POST /seo/meta` — Write SEO meta via Plugin Detector

### AEO
- `POST /aeo/generate-faq` — Generate FAQ schema (Job Queue)
- `POST /aeo/generate-howto` — Generate HowTo schema (Job Queue)
- `GET  /aeo/coverage` — Schema coverage report

### Translation
- `GET  /translation/missing` — Untranslated content
- `POST /translation/request` — Request AI translation (Job Queue)
- `POST /translation/quality-check` — Direct AI quality audit

### Elementor
- `GET  /elementor/page/{post_id}` — Full page structure (sections/columns/widgets)
- `GET  /elementor/outline/{post_id}` — Compact page outline with text previews
- `POST /elementor/translate` — AI translate page + create WPML copy
- `POST /elementor/widget` — Update widget text
- `POST /elementor/style` — Update element style (CSS-friendly)
- `POST /elementor/bulk-update` — Batch element changes
- `POST /elementor/add-widget` — Add widget to container
- `POST /elementor/add-section` — Add section with column
- `POST /elementor/delete` — Delete element
- `POST /elementor/move` — Move/reorder element
- `POST /elementor/clone` — Duplicate element with children
- `POST /elementor/copy-section` — Copy a top-level section across posts (cross-post; auto-snapshot, deep ID regenerate)
- `POST /elementor/custom-css` — Inject custom CSS (element or page-level)
- `POST /elementor/responsive` — Device-specific style overrides
- `POST /elementor/global-style` — Style all widgets of a type
- `GET  /elementor/kit` — Kit ID, breakpoints, custom CSS info
- `GET  /elementor/global-css` — Read Kit custom CSS
- `POST /elementor/global-css` — Write Kit custom CSS (append supported)
- `POST /elementor/batch-css` — Apply page CSS to multiple posts by IDs or post_type

### Knowledge Graph
- `GET  /knowledge-graph` — Full store intelligence data

### Vendors (Vendor / Maker / Atelier CPT — 3.5.2+; renamed from People in 3.5.2)
- `GET  /vendors/settings` — Read CPT config (slug, labels, entity_type, social toggles, legacy redirects)
- `POST /vendors/settings` — Update config (auto-flushes rewrite on slug change)
- `GET  /vendors` — List vendors with profile meta
- `GET  /vendors/{id}` — Single vendor + Schema.org preview
- `POST /vendors/{id}/meta` — Write profile fields
- `POST /vendors/sync-rewrite` — Manual rewrite flush

**CPT slug:** `lwp_vendor` · **Profile meta keys:** `_lwp_vendor_*` · **Product attribution meta:** `_lwp_vendor_ids` (JSON array of vendor IDs, **canonical format `["123","456"]` — quoted strings**; sanitize_callback enforces this at the meta API boundary since 3.5.3, every write through update_post_meta/REST/MCP/wp-cli is auto-normalized). Theme query side (single-lwp_vendor.php + lwp-master-grid widget) uses REGEXP with JSON-array-element-aware boundaries `(\[|,)\s*"?<id>"?\s*(,|\])` for cross-format tolerance during transition. See `feedback_register_post_meta_sanitize_locks_format.md`.

### CRM & Analytics
- `GET  /crm/overview` — Customer segments
- `GET  /review/analytics` — Review sentiment

### Authentication
- `POST /jwt-auth/v1/token` — Generate JWT
