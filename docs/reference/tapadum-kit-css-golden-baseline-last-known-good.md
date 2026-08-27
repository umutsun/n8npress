# Tapadum Kit CSS Golden Baseline (last known-good)

When visual state on tapadum.com (or new.tapadum.com) is disturbed by cascading Kit CSS edits, **do not try to patch forward with more layers**. Restore to the last golden baseline and rebuild additions from there.

> **Note (2026-05-05):** The golden baseline files were captured against legacy `tapadum.com`. When the operator works on `new.tapadum.com` (the active workspace), confirm the page IDs / section IDs match before applying — Elementor IDs are not necessarily identical between the two installs. If new.tapadum.com is a fresh staging copy, capture its own baseline before deviating.

### Current live state — **2026-05-06 (V48 intro-banner contrast restore)**

- Live size: 406,982 bytes (Kit ID 203, sha-16 `dd360131070a651e`)
- Pre-V48 backup: `temp/tapadum/kit-pre-v48-1778018346.css` (sha `2d1b5c1889c18e41`, 406,065 bytes)
- Push script: `temp/tapadum/push_v48.py` (re-runnable)

**V48 (2026-05-06):** Restored white-on-black + vertical-center on 13 hub/sub-category intro banners. V47 typography rule had set `color: var(--tp-text-secondary)` (gray) on every `main .elementor-widget-text-editor p`, busting contrast on dark-bg banners (customer reported repeatedly). Also stripped dead `PERCUSSIONS-FIX-V2` sections (1)(2) — they used `body.tax-product_cat .term-description` selectors that don't match Tapadum's wp-page DOM (these hub pages are post_type=page, not WC tax archives). Section (3) sub-cat card parity preserved. Headroom to 412KB silent-truncate threshold: ~5KB. Coverage details + widget ID list in `project_tapadum_v48_intro_banner_2026_05_06.md`.

**Coverage:** percussions, bowed-instruments, winds, accessories, darbuka, mey, frame-drums, travel-darbuka, persian-kamancheh, arabic-oud, santur, qanun, string-instruments. NOT covered: `/tanbur/`, `/oud/`, `/kamancheh/` — no `background_background:classic` container detected (or 404); verify visually if operator reports residue.

> **Previous baselines (2026-04-21 -> 2026-04-27, V23..V46 layer history + V35.5/V36 rollback forensics) archived to `docs/history/tapadum-kit-css-baseline-history.md`.** Baseline CSS files live in `temp/tapadum/kit-golden/`. Key surviving lessons: use `:is()` grouping for many-ID selectors (884KB -> 82KB; DB truncates ~412KB), never regex-strip IDs across live `:is()` blocks — rebuild the layer from a corrected scan instead.

### When to restore

Restore to golden baseline when any of these happen:
- Header layout breaks (menu wraps to 2 rows, caret shows garbage text, switcher disappears)
- Footer bg goes white on any breakpoint
- Product CTA button loses red color
- Content headings render Elementor default blue instead of dark
- Breadcrumb loses pill styling
- Commerce-critical elements (floating cart) get hidden

### How to restore

```bash
# Restore LATEST golden baseline (default)
PYTHONIOENCODING=utf-8 python temp/tapadum/kit-golden/restore-golden-baseline.py

# Or specify a dated baseline:
PYTHONIOENCODING=utf-8 python temp/tapadum/kit-golden/restore-golden-baseline.py 2026-04-21
```

The script:
1. Reads `temp/tapadum/kit-golden/tapadum-kit-golden-LATEST.css` (or dated variant)
2. Saves current live Kit CSS to `temp/tapadum/kit-golden/kit-pre-restore-<epoch>.css` (pre-restore backup)
3. POSTs to `/wp-json/luwipress/v1/elementor/global-css` with `append: false`
4. Runs `/cache/purge {targets:["all"]}`
5. Warms homepage + `/percussions/` to repopulate LiteSpeed page cache

### Updating the baseline (only when visual state is verified good)

After a stable, verified-good Kit CSS state:

```python
import json, urllib.request, time
req = urllib.request.Request('https://tapadum.com/wp-json/luwipress/v1/elementor/global-css',
    headers={'Authorization':'Bearer lp_...'})
css = json.loads(urllib.request.urlopen(req, timeout=60).read().decode('utf-8-sig')).get('css','')
date = time.strftime('%Y-%m-%d')
open(f'temp/tapadum/kit-golden/tapadum-kit-golden-{date}.css','w',encoding='utf-8').write(css)
open('temp/tapadum/kit-golden/tapadum-kit-golden-LATEST.css','w',encoding='utf-8').write(css)
# Update this CLAUDE.md section with new date + size + sha
```

Keep the last 2-3 dated baselines so rollback can step back further if a new baseline turns out flawed.

### Rules tied to this baseline (from memory)

- `feedback_kit_css_cascade_catastrophe.md` — strip layer before rewrite, full-replace always, no @media for critical rules, no unicode escapes
- `feedback_cart_dont_hide_xoo_wsc.md` — never hide `xoo-wsc-*` selectors
- `feedback_elementor_heading_h6_gotcha.md` — include h6 in footer heading rules
