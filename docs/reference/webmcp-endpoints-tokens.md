# WebMCP Endpoints & Tokens
| Site | Profile | MCP Endpoint | Token |
|------|---------|-------------|-------|
| **tapadum.com** | **LIVE PRODUCTION (post DNS-swap 2026-06-08 — real customer traffic, this is now the primary site)** | `https://tapadum.com/wp-json/luwipress/v1/mcp` | `lp_gobm7nVO2xAnZpBfZv6DIq8M9tcwEbFJwbsPMGW4` |
| ~~new.tapadum.com~~ | **DECOMMISSIONED 2026-06-09 — do NOT use** (staging shut down; all work is on `tapadum.com`) | — | — |
| birikimengineering.com | **WC-less test profile** (3.1.38 soft-dep validation site) | `https://birikimengineering.com/wp-json/luwipress/v1/mcp` | `lp_TYxW8sBfWlKRn3yKGFQMuuT7D0MKIiTtP7TSYAHK` |
| osenben.com | Generic dev/test (legacy) | `https://osenben.com/wp-json/luwipress/v1/mcp` | `hhamvbmjxsvcohnpyajnykyqmxcfcikw` |
| **new.flybydeniz.com** | **Fly by Deniz LIVE site** on arsha-vps (167.233.74.186), migrated from demo 2026-06-14 (Amber theme; `fbd-connect` Duffel plugin → ops.flybydeniz.com). LuwiPress core **3.15.2**, PHP 8.5 host. **`demo.flybydeniz.com` DECOMMISSIONED — do NOT target it.** | `https://new.flybydeniz.com/wp-json/luwipress/v1/mcp` | `lp_5B85DeDEhUTxLlSNJcq60ELfiTmjlqIAjDsKmcLc` |

> **Token rotation note (2026-06-08):** the historical `tapadum.com` token `lp_QuDGnNHTmDWRp5Ng4HhrqEDhviwhQB4BbwhF2mEb` is **DEAD** (the pre-swap legacy DB it belonged to is gone). New live token is `lp_gobm7nVO2xAnZpBfZv6DIq8M9tcwEbFJwbsPMGW4` (verified: 288 MCP tools, REST + MCP both authenticate). Both REST routes and the `/mcp` JSON-RPC endpoint use the same Bearer token; `/status` is public (responds without auth) but everything else requires the Bearer.

**Site-by-site invariants (enforced by tools/webmcp-contract-test.py):**
- **tapadum.com** — **LIVE PRODUCTION (post DNS-swap 2026-06-08).** Core **3.13.0** (license management, deployed 2026-06-09), 288 MCP tools, WC + WPML on. Serves the migrated catalog. Default target for `/status`, `/elementor/*`, `/attribution/*`, `/marketplace/*`, `/license/*`, snapshot, translation, and Kit CSS endpoints. **This is real customer traffic** — snapshot before Elementor/Kit-CSS mutations, get explicit confirmation for destructive ops.
- **new.tapadum.com** — **DECOMMISSIONED (2026-06-09).** Staging/shadow site shut down; no remaining function. Do NOT reference or target it — all work is on `tapadum.com`.
- **birikimengineering.com** — 131 MCP tools (WC tools skipped because `register_woo_tools` early-returns when `class_exists('WooCommerce')` is false), 3.1.38 live, WebMCP companion 1.0.5. **Use this site for WC-less regression tests.** Mühendislik content site, no commerce data.
- Use the base domain (no `/fr/`) for language-neutral API calls.
- **Never run destructive ops** (enrich-batch with real options, /retranslate-all, /reorder-sections, kit CSS rewrites, force-retranslate, sync-structure) against `tapadum.com` (now LIVE production) without explicit user confirmation + a snapshot first. birikim is for WC-less regression; osenben is legacy and may be stale.
