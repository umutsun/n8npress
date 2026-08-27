# Snapshot & Cache Purge Protocol

Apply this protocol whenever mutating Elementor data (`_elementor_data`) or Kit CSS on any production site.

### Before ANY Elementor structural change
1. **Take explicit named snapshot** — don't rely solely on auto-snapshots:
   ```
   POST /elementor/snapshot  {"post_id": <id>, "note": "<what+why>"}
   → returns snapshot_id (e.g. "26cce5fc")
   ```
   Save the `snapshot_id` in your todo/notes. Rollback: `POST /elementor/rollback {"post_id": X, "snapshot_id": Y}`.
2. **Auto-snapshot endpoints** (don't take an extra one unless note is useful): `reorder-sections`, `sync-structure`, `translate`. They return a `snapshot_id` in the response.
3. For **batch ops across translations**, snapshot each target before `sync-structure` runs — the endpoint does this but log the IDs for rollback planning.
4. `GET /elementor/snapshots/{post_id}` lists available snapshots (retention is limited).

### After Elementor data change
1. `POST /elementor/flush-css {"post_id": X}` clears Elementor's per-post CSS cache. Required after style/widget edits on regular pages.
2. For **templates** (e.g. Single Product template 259), the template itself has no CSS file — Elementor regenerates per-visitor-post on next view. Still run flush-css on template post_id; it's a no-op but harmless.
3. LiteSpeed page cache is **separate** from Elementor cache. Hard-refresh in incognito bypasses it for visual verification. For a sitewide purge use the LiteSpeed admin UI; never script a mass-purge without user approval.

### After Kit CSS change
1. Kit CSS is stored in `luwipress_kit_css` option and echoed inline at `wp_head` priority 99 — **no file cache to flush**.
2. **LiteSpeed minify strips `/* comment markers */`** — do not grep the rendered stylesheet for your marker. Use **unique selectors or section IDs** as anchors when you need to locate a layer later (see `project_tapadum_kit_css_layers.md`).
3. Verify in **incognito** with cache-buster (`?cb=$(date +%s)` + `Cache-Control: no-cache`). Elementor editor preview + logged-in admin bar introduce their own stacking-context and cache quirks.
4. **ALWAYS USE `append: false` (full replace). NEVER `append: true`.** Confirmed root cause (2026-04-20): the `append:true` path calls `get_option('luwipress_kit_css')`, which returns a **stale cached value** from LiteSpeed Object Cache. The endpoint then writes `stale_existing + new_block` back to DB, silently overwriting newer content. Symptoms: write response reports correct length, next read returns smaller value containing only the OLD baseline + your new chunk. Reproduction: after a clean push the option keeps getting "reverted" to baseline+new every time you append. Fix: concat the full CSS locally (base + all layers) and push with `append: false`. Keep local backups of every layer file in `temp\tapadum\tapadum-*.css` and a consolidated `kit-full-YYYY-MM-DD.css` so recovery is single-shot. Incident: 2026-04-20 session — layers 06-14 silently dropped during single append:true call; recovered from local files via full replace.
5. Layer naming convention (numeric prefix for ordering): `06-wc-cta-unify`, `07-wc-categories-tile`, etc. Markers are write-time idempotency only; do not rely on them for runtime discovery.

### `:has()` selector caveat
- Avoid `:has()` inside `@media` blocks — Safari + some Chromium builds drop the whole @media rule silently.
- LiteSpeed minify can also mangle `:has()` in edge cases. If a `:has()` rule doesn't apply in production, assume it's stripped — don't add more `!important`; fix the structure instead (see Session 2 regression notes in memory).

### Rollback etiquette
- Before rollback, verify the snapshot you think you want is still the most recent good state (`GET /elementor/snapshots/{post_id}`).
- Rollback replaces `_elementor_data` and regenerates CSS. Translation posts (IT/FR/ES) are NOT rolled back in the same call — if the structural change was synced to translations, roll back source first, then re-run `sync-structure` to propagate, or roll back each translation explicitly.
