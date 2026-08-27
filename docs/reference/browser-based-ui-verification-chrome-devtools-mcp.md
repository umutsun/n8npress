# Browser-based UI Verification (chrome-devtools MCP)

The chrome-devtools MCP server is installed and available. Use it when CSS / layout / responsive behaviour needs verification BEYOND raw HTML inspection — anything that touches computed styles, viewport-relative units, JavaScript-driven layouts, or visual regressions. Cheaper alternative paths first: curl for HTML, `mcp__chrome-devtools__evaluate_script` for computed-style probes, screenshots only when the eyeball view is what the operator asked for.

**Useful tools:**
- `mcp__chrome-devtools__new_page` / `navigate_page` — open + load URL (set `timeout: 30000` for heavy pages).
- `mcp__chrome-devtools__resize_page` — set viewport dimensions (e.g. 390×844 iPhone). Actual `window.innerWidth` may not match the requested width due to scrollbar / DPR handling — read `vw` from `evaluate_script` to be safe.
- `mcp__chrome-devtools__evaluate_script` — run a JS function in the page, return JSON. Best surface for: computed `getComputedStyle()`, `getBoundingClientRect()`, traversing CSSOM `document.styleSheets` to find which rule sets a property, ancestor padding chains, custom element matching.
- `mcp__chrome-devtools__take_screenshot` — save PNG/JPEG. **Path constraint:** must be inside one of the workspace roots — use `c:/xampp/htdocs/luwipress/temp/screenshots/` (already in workspace), NEVER `C:/tmp/...` (outside, returns Access denied).
- `mcp__chrome-devtools__navigate_page` with `type: "reload"` + `ignoreCache: true` — hard refresh after Kit CSS POST / theme deploy.

**Caveats:**
- DPR 1.25 on Windows distorts screenshots taken via chrome-devtools-mcp. For pixel-accurate brand assets (theme `screenshot.png`), use headless `chrome.exe --headless=new --window-size=1200,900` directly. See `feedback_headless_chrome_cli_for_screenshots.md`.
- LiteSpeed page cache + Kit CSS inline emit — even after `cache_purge`, browser may serve stale page. Reload with `ignoreCache:true` and confirm a marker selector (`document.getElementById('lwp-gold-mobile-90')` etc.) is present before measuring.
- When CSS specificity math says your rule should win but the browser disagrees, check **CSSOM order** (`document.styleSheets` traversal) — not just specificity. A later same-specificity rule wins.
- Kit CSS DB silently truncates around ~418 KB (`luwipress_kit_css` option). When V66/V67-style layers fail to persist their tail bytes, strip an older now-superseded layer (V42–V45 etc.) to make room before re-POST.
