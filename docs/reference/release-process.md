# Release Process

### Version Bump Policy (strictly enforced)

**NEVER bump a version unless the user explicitly says so.** Triggers: "bump", "release", "yayınla", "hazır", "versiyon ver", or naming a specific version like "3.1.4". Absence of objection is NOT permission.

**What warrants a bump:**
- **Patch (x.x.N):** batch multiple fixes/improvements together. One patch per session max — do not bump for each individual change. Wait until the user says the session's work is ready to ship.
- **Minor (x.N.0):** new module, new REST/MCP surface, or meaningful new capability visible to store owners.
- **Major (N.0.0):** breaking changes or architectural reorganization (rare).

**Anti-patterns this rule prevents:**
- Bumping 3.1.1 → 3.1.2 → 3.1.3 in a single session for incremental changes (happened 2026-04-21 — do not repeat).
- Bumping because "the code changed" — code changes accumulate into a single bump when ready.
- Bumping companions (webmcp, open-claw) for changes made only to core, or vice versa.

### Steps (once bump is authorized)
1. Bump version in the plugin's main file header + `*_VERSION` constant
2. Update `Stable tag` in its `readme.txt` + changelog
3. **Run pre-flight gate:** `./tools/release-preflight.sh` — lint + PHPStan + security-audit + quality-check + REST contract + WebMCP contract. Refuse to build if any new finding lands above the baseline.
4. Build ZIP:
   - **Plugins:** `php build-zip.php <version> <slug>` where `<slug>` is **`luwipress-core`** (the core — NOT `luwipress`, see "Core release identity" below) / `luwipress-webmcp` / `luwipress-marketplace` / `luwipress-agentic`. Output: `releases/<slug>-v<version>.zip`.
   - **Theme (`luwipress-gold`):** `php build-theme-zip.php <version>`. Source `themes/luwipress-gold-elementor/` is repackaged as ZIP slug `luwipress-gold/`. Output: `releases/luwipress-gold-<version>.zip` (no `v` prefix — distinct from companion convention; matches existing 17-release file naming).
5. After deploy, run `tools/rest-contract-test.py` + `temp/e2e-test/verify_*.py` against the live site. Cite `9999_/...` SHA-256[:16] of the deployed ZIP in the commit/notes when handing off.
6. Commit and push (range commit `feat(LAST -> NEW)` per `feedback_deploy_consolidates_backlog.md`).
7. **Tag-and-forget delivery (MANDATORY since 2026-06-11, operator request):** push the commit AND the tag in the SAME session as the ZIP build (`git tag v<version>` for core, `luwipress-<companion>-v<version>` for companions) → GitHub Actions builds the ZIP → GitHub Release asset `{slug}-v{version}.zip` → operator one-click deploys from the GitHub Release. **A ZIP left only in `releases/` is an INCOMPLETE release** — the operator's deploy flow starts at GitHub Releases, not the local folder. (Verified working: v3.13.5 + v3.13.6, Release CI green in ~1 min after tag push.) Once the luwi.dev webhook is wired, the same tag push also signs + registers the update so licensed sites see it (USER-TRIGGERED install: WP Updates screen or the dashboard "updates found — install?" pill; never silent).

### Core release identity: `luwipress-core` (RENAMED 2026-06-10 — read before any core release work)

The core plugin **releases as `luwipress-core`** (tag `v<ver>` or `luwipress-core-v<ver>` → asset `luwipress-core-v<ver>.zip` → license-server slug `luwipress-core`) and its **display name is "LuwiPress Core"** (`Plugin Name:` header + readme title, since 3.13.3 — the Plugins list shows "LuwiPress Core"; the admin menu/dashboard keep the suite brand "LuwiPress"). Its **source folder, ZIP internal root, and WP install directory all stay `luwipress/`**. Renaming the install folder would change the plugin basename (`luwipress/luwipress.php`) — WP's plugin identity — deactivating every existing install on update, so only the RELEASE identity was renamed.

Practical consequences:
- `php build-zip.php <ver> luwipress-core` → builds from `luwipress/`, outputs `releases/luwipress-core-v<ver>.zip` with internal root `luwipress/` (the `$dir_map` in build-zip.php; release.yml mirrors it).
- `LuwiPress_License::UPDATE_SLUG = 'luwipress-core'` (3.13.3+): sites on 3.13.3+ request manifest slug `luwipress-core`; sites on ≤3.13.2 still request the legacy `luwipress` slug — **the luwi.dev server must serve BOTH slugs until the fleet is past 3.13.2** (see `docs/luwi-dev-core-slug-rename-handoff.md`).
- Plugin basename references (`luwipress/luwipress.php` in companion activation gates, the WebMCP lifeline guard, `LUWIPRESS_PLUGIN_BASENAME`) are UNCHANGED — do not "fix" them to luwipress-core.
- A possible full folder rename (`luwipress/` → `luwipress-core/`) is deferred to a future MAJOR (4.0.0 / CodeCanyon fresh start) and needs a migration story — do not attempt it casually.
