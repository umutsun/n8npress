=== LuwiPress Core — AI-Powered WooCommerce Automation ===
Contributors: luwidev
Tags: woocommerce, ai, seo, translation, automation, product enrichment, multilingual
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.17.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered content enrichment, SEO optimization, and translation automation. WooCommerce is recommended for the full feature set; the plugin runs without it for content + chat workflows.

== Description ==

LuwiPress is a **standalone AI plugin** for WooCommerce stores — generates content, optimizes SEO, translates products, and automates store management without external dependencies.

**It doesn't replace your plugins — it makes them smarter.**

= What LuwiPress Adds =

| Your Plugin Does | LuwiPress Adds (via AI) |
|---|---|
| Rank Math stores SEO meta | AI generates optimized titles, descriptions, FAQ schema |
| WPML manages languages | AI translates with SEO keyword awareness |
| WooCommerce collects reviews | AI drafts professional review responses |
| WordPress schedules posts | AI generates fresh blog content + images |
| SEO plugins add basic schema | AI generates FAQ/HowTo/Speakable schema |

= Key Features =

* **AI Product Enrichment** — Generate rich descriptions, meta titles, FAQ schema, alt text
* **SEO-Aware Translation** — Translate with keyword density awareness via WPML/Polylang
* **Answer Engine Optimization (AEO)** — FAQ, HowTo, Speakable structured data
* **Content Scheduling** — AI blog posts with DALL-E generated images
* **AI Review Responder** — Auto-respond to WooCommerce product reviews
* **Open Claw AI Assistant** — Chat interface for managing your store with natural language
* **CRM Lifecycle** — Customer segmentation (VIP, at-risk, dormant) + win-back campaigns
* **WebMCP Server** — MCP Streamable HTTP server exposing 115+ tools to AI agents (Claude Desktop, Claude Code, any MCP client)
* **Plugin Auto-Detection** — Automatically detects Rank Math, WPML, Polylang, WP Mail SMTP, FluentCRM, Chatwoot
* **Cost Protection** — Daily budget limits, token tracking, per-workflow cost breakdown
* **Multi-Provider AI** — OpenAI (GPT-4o Mini), Anthropic (Claude), Google (Gemini)

= How It Works =

1. Install LuwiPress on your WordPress site
2. Enter your AI API key (OpenAI recommended for best cost/quality ratio)
3. Set a daily budget limit to control costs
4. Use Open Claw AI assistant or dashboard buttons to trigger automations — all AI calls run natively in WordPress, no external workflow engine required

= Open Claw AI Assistant =

Built-in chat interface with slash commands:

* `/scan` — Content opportunity scan
* `/seo` — Products missing SEO meta
* `/translate` — Start translation pipeline
* `/enrich` — Batch AI enrichment
* `/thin` — Thin content products
* `/stale` — Stale content list
* `/generate [topic]` — Generate blog post
* `/aeo` — AEO schema coverage
* `/help` — All available commands

= Cost Control =

LuwiPress includes built-in cost protection:

* **Daily budget limit** — AI features auto-pause when reached ($1.00 default)
* **Token usage tracking** — See exactly which workflows cost money
* **Model selection** — GPT-4o Mini is 20x cheaper than Claude Sonnet
* **Local commands** — /scan, /seo, /translate work without any AI cost
* **No hidden charges** — You bring your own API key, you control spending

= Requirements =

* WordPress 5.6+
* WooCommerce 5.0+ (recommended)
* AI API key (OpenAI, Anthropic, Google, or any OpenAI-compatible endpoint)

= Supported Plugin Integrations =

* **SEO:** Rank Math, Yoast, AIOSEO, SEOPress
* **Translation:** WPML, Polylang, TranslatePress
* **Email:** WP Mail SMTP, FluentSMTP, Post SMTP
* **CRM:** FluentCRM, Mailchimp for WooCommerce
* **Support:** Chatwoot

== Installation ==

1. Upload the `luwipress` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to **Settings → AI API Keys** and enter your OpenAI, Anthropic, Google, or OpenAI-compatible API key
4. Set your daily budget limit (recommended: $1.00/day)
5. (Optional) Install the **LuwiPress WebMCP** companion plugin if you want to expose the store to AI agents over the Model Context Protocol

== Frequently Asked Questions ==

= Do I need any external workflow engine? =

No. LuwiPress is fully standalone as of 2.0.1 — the AI engine, job queue, and prompt templates all run natively inside WordPress. No n8n, no Make, no Zapier required. You only need an AI API key.

= Which AI provider should I use? =

We recommend **OpenAI GPT-4o Mini** for the best cost/quality ratio. At $0.15 per million input tokens, you can run thousands of operations for under $1/day.

= Will this increase my hosting costs? =

No. LuwiPress is lightweight — async jobs run through WP-Cron and most AI calls complete in under a second of PHP time. The heavy lifting happens on the AI provider's side, not your server.

= Is my data sent to third parties? =

Product data is sent directly from your WordPress site to the AI provider you choose (OpenAI, Anthropic, Google, or an OpenAI-compatible endpoint you configure). No data is sent to LuwiPress servers or any intermediate service.

= Can I use this without WooCommerce? =

Yes, but WooCommerce-specific features (product enrichment, review responses) won't be available. Content scheduling, translation, and Open Claw still work.

= How do I control costs? =

Set a daily budget limit in Settings → AI API Keys. When reached, all AI features pause until the next day. Local commands (/scan, /seo, etc.) always work with zero cost.

== Screenshots ==

1. Dashboard with AI Token Usage tracking
2. Open Claw AI Assistant chat interface
3. Plugin auto-detection showing Rank Math, WPML, WP Mail SMTP
4. Settings page with model selection and daily budget limit
5. Translation coverage manager
6. Activity log with workflow results

== Changelog ==

= 3.17.6 — Fix: the new link search scopes were rejected =
* **Fixed: "links" and "all" search scopes returned an error.** The find-and-replace engine understood both new scopes and they were listed in the tool, but the request check still only accepted the three old ones — so the documented scope failed outright. Both now work as described.

= 3.17.5 — Link fields are editable, and edits stop being silently discarded =
* **Button and card links can finally be edited.** Elementor stores a link as a small object, and no editing tool could write one — so translated pages kept pointing at their source-language URLs. Links can now be set whole, or one part at a time (for example just the address), from the same tools as any other field.
* **Find-and-replace can now search links.** Two new scopes — "links" and "all" — reach the URLs inside buttons, cards and filter pills. Previously every scope skipped them, which made fixing a whole translated page one-by-one work.
* **Find-and-replace no longer corrupts repeated rows.** When a match sat inside a tab, list item or card, the replacement was written to a key the page never reads: the visible text stayed unchanged while the page quietly grew junk settings.
* **Custom fields keep their formatting.** Saving a custom field stripped every tag, so links and paragraphs inside a description were destroyed on save. Formatting is now preserved.
* **Page layout data is protected from the wrong tool.** Writing a page's Elementor layout through the generic custom-field tool wiped all formatting across the page. That write is now refused with a message naming the right tool.

= 3.17.4 — Translations stop overwriting themselves =
* **A half-finished translation can no longer replace a finished one.** When the AI service returned only part of a page, the pipeline still published the result — and because the untranslated parts fall back to the original language, a fully translated page could be pushed back to English, taking any manual corrections with it. A page is now only republished when enough of it actually came back; otherwise the live translation is left exactly as it was and the run is reported as refused.
* **Wording you already fixed by hand survives a re-run.** Fields the translator could not deliver now keep whatever the translated page currently shows, instead of reverting to the source language.
* **Background translations give up instead of looping.** A failing page is retried a limited number of times and then stops with a recorded reason, rather than being retried indefinitely — each retry used to rewrite the live page.
* **You can finally see and stop the translation queue.** Pending background translations are listed with their language, when they are due and how many attempts they have used, and a single page/language job can be cancelled on its own instead of clearing the whole queue. The translation status view now shows this queue too.
* **The page editor accepts structured content.** Tabs, accordions, bullet lists and link fields can now be written directly (as a whole list, or one row at a time); previously these requests failed with an unreadable error.
* **Link fields can no longer be emptied by accident.** Writing plain text into a button's link now sets the address instead of destroying the field — which used to make the button disappear from the page with no way to undo it from the editor.
* **Direct content writes are verified.** A write that does not reach the database is now reported as failed instead of being confirmed, and the rendered-content cache is cleared so corrected pages actually appear updated.
* **Translation requests now tell you when a language failed.** The response reports each language's outcome (completed / rejected / failed) with a reason, instead of always answering "completed" and leaving the rejection only in the log.
* **Pages with no translatable text no longer fail silently.** A request against a page whose visible copy comes entirely from a dynamic grid is answered with an explicit "nothing to translate" instead of appearing to vanish.
* **Protected terms (glossary).** Brand and product names you list are reproduced verbatim in every language, so "Tapadum Music Store" no longer becomes "Tapadum Musikladen" — and, because titles and slugs are derived from translated copy, the URLs stop drifting too.
* **Page titles are translated from the actual page title**, not scavenged from whichever widget happened to come first — a button reading "MEHR ANSEHEN" could previously become the page's title and slug.
* **Translations born without a URL slug are repaired.** The slug check missed empty slugs entirely, so those pages kept a broken permalink.
* **Many more theme fields are translated.** Section leads, stat labels, countdown labels, newsletter and FAQ copy, testimonials, info bars and several other blocks were silently skipped because the field map had drifted from the theme; the map has been audited against the shipped widgets and now tolerates renamed fields.

= 3.17.3 — Knowledge Graph for every site, not just shops =
* **The Knowledge Graph now works on sites without WooCommerce.** Stores were never the only thing worth mapping: directories, portfolios, real-estate listings and any other custom catalogue now appear in the graph with the same Catalog / Posts / Pages views, health scoring and one-click actions. Nothing to configure — the graph detects what your site actually publishes.
* **AI content actions work on any content type.** "Generate SEO Meta", enrichment, FAQ and HowTo are no longer limited to products — they run on posts, pages and custom content, writing through whichever SEO plugin you already use.
* **Clearer store-health readout.** The health panel now breaks the score into labelled, weakest-first bars so you can see at a glance where you are losing points, with a one-click jump to the weakest area. Milestone badges moved below the breakdown so they read as a reward, not as the main signal.
* **Vendor/brand attribution beyond products.** Vendors can now be credited for any content type your site opts in, so brand pages show real counts on non-shop catalogues.
* **Backup plugin now detected.** The dashboard status ribbon recognises UpdraftPlus, All-in-One WP Migration, Duplicator, WPvivid and BackWPup, and tells you when no backup plugin is installed at all.
* **Fixed: two stray checkboxes in the Autopilot panel.** The Off / Dry-run / Live mode cards had two leftover checkboxes showing through underneath them. They are internal controls and are now correctly hidden.
* **Fixed: customer chat no longer shows raw code.** If an AI reply was cut short by the length limit, the chat could print raw `{"reply": …}` text at the customer. It now recovers and shows the message. Links and bold text in chat replies also render properly instead of appearing as plain markdown.

= 3.17.2 — Knowledge Graph schema signal is now honest =
* **No more unfixable "missing Product Schema" tasks.** The Knowledge Graph used to flag products as missing structured data even when WooCommerce (or your SEO plugin — Rank Math, Yoast, AIOSEO, SEOPress) was already outputting it, leaving a task you could never clear. The graph now recognises that coverage: those products show as complete and the dead tasks disappear, so your store-health score reflects reality.
* **One-click Product schema where it's genuinely missing.** On stores with no other source of Product structured data, a new "Generate Product Schema" action builds valid JSON-LD (price, stock, rating) straight from your product data — instantly, with no AI cost — and only emits it when nothing else does, so you never get duplicate schema.

= 3.17.1 — Knowledge Graph actions run in the background (no more stuck buttons) =
* **Enrich & "Next Wins" actions no longer hang.** Triggering product enrichment from the Knowledge Graph or the Next Wins panel now hands the AI work to a background queue and returns instantly, instead of holding the request open for 30-90 seconds (which timed out and left the button stuck on "Working…"). The live progress monitor tracks each product as it completes, so the gamification loop — act, watch your store-health score climb — works reliably even on hosts with strict execution limits.

= 3.17.0 — Licensed builds: activation required =
* **Licensing is now enforced in distributed builds.** This build requires an active LuwiPress license to use plugin features (REST API, AI tools, admin tools). Activate your license key under LuwiPress → Settings → License — the License screen always stays reachable so you can activate at any time. Already-licensed sites are unaffected; a 14-day grace window covers any temporary licensing-server hiccup, so a paying site is never abruptly cut off.
* **Honest activation feedback.** Activating a license now tells you the truth: if a key is rejected — wrong key, wrong tier, or already bound to another site — you get the real reason instead of a misleading "done" message.
* **Brand logo on the Plugins & Updates screens.** LuwiPress and its companions now show the Luwi logo instead of the generic placeholder icon.

= 3.16.0 — Image Studio: one-click AI image retouch =
* **NEW — Image Studio.** A new panel in the post/page/product editor retouches your CURRENT featured image with AI: pick a style (Clean, Luxury, E-commerce, Vivid, B&W) and size, click Retouch, and a polished, standardised version is added to the Media Library (your original is never overwritten) and can be set as the featured image in one click. Non-destructive and cost-tracked.

= 3.15.7 — AI image generation restored (gpt-image-1) =
* **Fixed AI image generation.** OpenAI retired the older image model, so AI image features (DALL-E generation, e.g. blog/Journal hero images) had stopped working with a "model does not exist" error. LuwiPress now uses OpenAI's current image model and handles its response format, so AI-generated images work again. No settings change is needed.

= 3.15.6 — Packaging fix (Forms Bridge + Backup Bridge) =
* **Fixed a critical error that could occur on a clean install.** The 3.15.5 package was missing two module files (the Forms Bridge and Backup Bridge) that the plugin loads on startup, so a brand-new installation could fail to activate. Both modules are now bundled. Existing sites are unaffected — no action needed.

= 3.15.5 — Reliable translation of HTML-widget pages =
* **Fixed: pages with several Elementor HTML widgets (e.g. a homepage hero + sections) could still come back in the source language.** HTML widgets carry large markup blobs; batching several into one AI request overflowed the response, the AI returned malformed JSON, and the whole batch was dropped — so those widgets kept English. Each HTML widget is now translated on its own (small, reliable requests), like long text blocks already were. Re-run the page translation to fill any gaps.

= 3.15.4 — Tour Hero widget is translatable =
* **Added the theme "Tour Hero" widget to the Elementor translation map.** Its text fields (eyebrow, headline, sub, both button labels, and the search-bar labels) are now picked up by page translation, so a hero built with the native widget translates into every language out of the box.

= 3.15.3 — Elementor HTML widgets are now translated =
* **Fixed: pages built with Elementor "HTML" widgets stayed in the source language.** The Elementor page translator only translated standard widgets (Heading, Text Editor, Button, etc.) and skipped raw-HTML widgets — so a homepage or section built with custom HTML still showed English on every translated language. HTML widgets are now translated like Text Editor content (markup preserved, only visible text translated). As a safety guard, an HTML widget that carries a `<script>` or `<style>` block is left untouched so JavaScript/CSS can't be corrupted. Re-run the page translation (Translation Manager or `POST /elementor/translate`) to fill the previously-missed text.

= 3.15.2 — Forms plugin detection on the dashboard ribbon =
* **New: LuwiPress now detects your forms plugin.** The dashboard status ribbon shows a green pill for the active forms plugin — Fluent Forms, WPForms (Lite or Pro), Gravity Forms, Forminator, or Contact Form 7 — with what it can do (multi-step wizard, stored entries, native Elementor widget). When no forms plugin is present it shows a one-click "install Fluent Forms" pill (free — multi-step wizard + entries + Elementor widget). Like the other integrations, it's detect-only — LuwiPress reads your forms plugin, it never replaces it.

= 3.15.1 — Knowledge Graph action buttons no longer get stuck on "Working…" =
* **Fixed: recommendation action buttons (enrich, translate, FAQ, etc.) could sit on "Working…" for a long time and look frozen.** The underlying actions run synchronously and can take 10–20 seconds (longer on slow hosts), and the button had no timeout, no progress feedback, and no recovery. Now each action shows a live elapsed counter ("Working… 8s"), can never hang forever (hard client-side timeout), and if the request times out the panel recovers by refreshing — the queued work keeps running on the server, so nothing the operator started is lost. Applies to every Knowledge Graph action: product/category enrichment, translation, taxonomy translation, FAQ and HowTo.

= 3.15.0 — Theme string translation + activate "fuzzy" translations + remote tour booking config =
* **New: AI theme-string translation.** Translate a theme's interface strings (its `.pot`/`.po` catalogue) into every active language and compile the `.mo` automatically — a built-in stand-in for Loco Translate's paid auto-translate quota. Translates only the untranslated strings, preserving placeholders (`%s`, `%1$s`), HTML and whitespace, and writes to the update-safe system languages folder by default. REST: `GET /translation/theme-coverage`, `POST /translation/theme-strings`.
* **New: activate translations left marked "fuzzy".** Strings entered in Loco Translate but left "fuzzy" are excluded from the compiled `.mo`, so they never display even though the translation already exists. `POST /translation/theme-clear-fuzzy` finds the existing `.po` (system dir, the theme's `languages/` folder, or Loco's custom dir), strips the fuzzy flag from every translated entry, and recompiles the `.mo` so the translations go live — no re-translation, no AI cost. Use `dry_run` to preview the count first.
* **New: remote tour booking configuration.** REST + MCP surface to read and update the WooCommerce tour-booking settings remotely.

= 3.14.4 — Menu URL translation no longer breaks links to untranslated pages =
* **Fixed: custom menu links to pages that aren't translated yet returned 404.** 3.14.3 added the language prefix to every custom link, but for a page with no translation in that language WPML has nothing to serve, so the link 404'd. Menu URL translation now rewrites a link only when a real translation of the target exists (page, product, or category term) — otherwise it leaves the original link, which gracefully falls back to the default language instead of breaking. Category links also now use the fully translated term URL when available.

= 3.14.3 — Translated menus now point at the right-language pages =
* **Fixed: custom menu links stayed on the default language.** Category/page menu items already opened their translated page, but hand-made custom links (e.g. "Flights", "Tours") kept their original URL, so clicking them on a translated page jumped back to the default language. "Translate Menus" now rewrites each custom link to the current language — using the translated page's permalink when the link points at known content, or adding the language prefix for plain internal paths. External links are left untouched.

= 3.14.2 — Menu sync now copies the items into translated menus =
* **Fixed: translated menus were created but stayed empty.** 3.14.1 created and paired the per-language menus, but relied on WPML's `wpml_sync_custom_element` to populate the items — which is a no-op for menu items on current WPML versions, so every translated menu had zero items. Sync Menus now copies the items itself: it walks the source menu, recreates each item in the translated menu (pointing category/page items at their translated term/page, copying custom links as-is), preserves the parent/child hierarchy, and pairs each item to its source so "Translate Menus" can fill the labels. Runs safely more than once (already-copied items are skipped, never duplicated) and guards against WPML's empty-menu "orphan" pitfall.

= 3.14.1 — Menu sync now creates the translated menus =
* **Fixed: "Sync Menus" did nothing on sites that had never created translated menus.** It only updated menus that were already translated, so on a multilingual store whose navigation had never been synced it reported "all in sync" and changed nothing — leaving every language showing the default-language menu. Sync Menus (and the `menu_sync_wpml` tool) now register the source menu's translation group, create the missing per-language menus, pair them to the source, and copy the items — so "Translate Menus" has real menus to fill. Requires "Navigation Menus" to be translatable in WPML → Settings.

= 3.14.0 — DeepL translation engine + menu translation surface =
* **New: DeepL translation engine.** Add a DeepL API key (Settings → AI) and switch the translation engine to DeepL (Settings → Translation). DeepL — known for the most accurate machine translation — handles product/page names and descriptions, while your AI provider still writes SEO titles, descriptions, focus keywords and FAQs. Free and Pro keys are both supported, with the correct endpoint selected automatically. Languages DeepL does not support fall back to the AI provider, and any DeepL error transparently falls back too — a translation never fails because of DeepL.
* **New: `translation_engine` exposed via REST.** `GET/POST /translation/settings` now reads and writes the engine choice (`ai` or `deepl`) and reports whether a DeepL key is configured (the key itself is never returned).
* **New: navigation menu translation is now scriptable.** The "Sync Menus" and "Translate Menus" maintenance tools are now also available as reusable methods, powering new remote menu-translation tooling — useful for translating mega-menu labels across every language on multilingual (WPML) sites.

= 3.13.6 — Translation pipeline hardening: no more empty or truncated translations =
* **Fixed: an empty or truncated AI translation could overwrite existing content.** When the AI response was cut short, the pipeline could save an empty body over a real translation. Translations whose body comes back empty or implausibly short versus the source are now rejected and the existing content is preserved — the run is marked failed instead, ready to retry.
* **Fixed: long articles were translated with too small an output budget**, which caused exactly those truncated responses. The budget now scales correctly with content length.
* **Fixed: "missing translations" reports always showed zero.** Two separate causes — an omitted limit parameter silently producing an empty scan, and an omitted language list matching nothing. The scan now defaults to sensible limits and falls back to the configured translation languages (or every active language) automatically.
* **Fixed: large background translation batches could silently lose jobs.** Queued translation jobs are now spaced out so each one runs in a fresh worker — a mid-batch failure no longer takes the remaining jobs down with it.

= 3.13.5 — Elementor translation: orphan-page fix + retry protection =
* **Fixed: AI page translation could create orphan pages instead of linked WPML translations.** Creating a brand-new translation registered the WPML language record correctly, but WPML's per-request cache could re-stamp the new page as a default-language original on its own translation group during the final title update — the page looked translated but was invisible as a translation (and the next attempt created another copy). The language record is now registered through WPML's official API before any other lookup touches the new page, and re-verified after every save. Updating an existing translation was never affected.
* **New: translation run lock.** A page translation takes a minute or more; impatient clients that time out and blindly retry used to trigger a full extra AI pass (and, with the bug above, another orphan page) per retry. Concurrent or repeated requests for the same page + language now get an immediate "translation already in progress" answer instead.
* **New: Translation Manager cleans up ghost language records** left behind when translated pages are deleted outside WPML's normal flow.
* **More robust:** REST/MCP translation runs now extend the PHP time limit and survive client disconnects; a stuck run releases its lock automatically after 15 minutes.

= 3.13.4 — "LuwiPress Core" naming + a friendlier update experience =
* **The core plugin is now named "LuwiPress Core"** in the Plugins list, matching its place in the LuwiPress ecosystem (WebMCP, Agentic, Marketplace companions + the LuwiPress themes). Nothing else changes — same install folder, same settings, same data.
* **New: minimal update indicator on the dashboard.** When an update is available, a small amber dot appears on the version pill (plugin updates) or the theme pill (active theme update) in the dashboard header. Click it to review what's pending and install with one click — updates are always user-triggered, never silent.
* **Fixed: "Update failed: Forbidden" on one-click updates.** The secure download link for an update package is short-lived, but it could be cached for hours — clicking "update now" after the link expired failed with Forbidden. Installing now always requests a fresh download link first.
* **"Check again" on the Updates screen now performs a true re-check** against the update server instead of re-reading a cached answer.

= 3.13.3 — Vendor products + "Made by" line now multilingual (WPML) =
* **Fixed: a vendor/maker profile page showed its products only in the default language.** On a multilingual (WPML) store the translated vendor pages (and their Schema.org `makesOffer` rich-result data) came up empty because the product↔vendor link is stored against the source-language vendor, while the translated page queried with the translated vendor ID. Vendor product lookups now resolve to the source vendor and run language-neutrally, then localize each product to the current language — so French/Italian/Spanish vendor pages list the same products (with localized titles + links) and emit complete `makesOffer` structured data. Powers the theme "their work" grid and the master-grid widget count too.
* **The product page "Made by this …:" line is now translatable.** It is registered with WPML String Translation so the whole phrase (including your custom vendor label, e.g. "luthier") can be translated per language under WPML → String Translation, instead of always rendering in the default language.
* **Automatic updates now cover the companion plugins AND the LuwiPress themes**, not just the core plugin. Licensed sites now receive one-click, signature-verified updates for LuwiPress WebMCP, Agentic and Marketplace, and for the LuwiPress Gold / Emerald / Ruby themes — all from the same dashboard, with no manual ZIP uploads.
* **Fixed: Knowledge Graph stuck on the loading skeleton.** On large multilingual catalogues the "opportunities" data did expensive cross-language scans inside the page request, exceeding the server timeout — which also meant the result never got cached, so every load timed out and the graph never appeared. That heavy work now runs in the background (and is cached), so the graph renders immediately and the Action Queue fills in once the background pass completes.
* **Knowledge Graph: the Pages view now shows per-language coverage** like the Posts and Products views — pages cluster by language with translated/missing indicators instead of one undifferentiated group.
* **Fixed: misaligned buttons / icons across several admin screens.** The unified button styling only reached a handful of pages, so the Vendors, Events, Security/Bot-defense, CPT, Schema, Taxonomy, Slug Resolver, Image Alt and WebMCP screens fell back to stock buttons with off-centre icons. The unified button system now applies consistently on every LuwiPress admin screen.
* **New: AI menu translation.** The Translation Manager can now translate your navigation menu labels into every active language with one click ("Translate Menus"). Menu items that point to a page/category use that item's existing translation automatically; custom links and custom labels are AI-translated — so translated menus no longer show labels in the source language. (Run "Sync Menus" first so the translated menu items exist.)

= 3.13.1 — Reliable, no-wait page translation =
* **Translation Manager now translates pages instantly while open.** Elementor pages used to be handed to background processing (wp-cron), which on low-traffic sites could leave them showing "translating…" indefinitely. The Translation Manager now translates each page directly as you watch, with live per-item progress — no more stuck-looking queue. Background processing remains as an automatic fallback.
* **Fixed: long articles silently staying in the source language.** Very long pages/posts split their text into small pieces for the AI; an oversized piece (a long heading-less section) could exceed the request timeout and quietly fall back to the original text. Content is now hard-split into safe sizes so even 2,000+ word articles translate completely.
* **New WP-Cron health indicator** in the Translation Manager header — surfaces a stalled background scheduler instead of letting jobs sit silently.

= 3.13.0 — Licensing & automatic updates =
* **NEW: license activation.** Activate your CodeCanyon purchase code or LuwiPress license key under LuwiPress → Settings → License. A daily background check keeps the activation in sync, and a 14-day grace window means a brief licensing-server outage never interrupts your site.
* **NEW: one-click automatic updates.** Licensed sites receive LuwiPress updates straight from the dashboard — no manual ZIP uploads. Update packages are cryptographically signed (Ed25519) and verified before they install.
* **NEW: plan tiers.** Activation unlocks features by plan (Starter / Pro / Studio / Marketplace / Enterprise); the companion plugins (WebMCP, Marketplace Sync, Agentic) enable themselves automatically based on your plan. Licensing enforcement is off by default and fully under your control.
* AI provider API keys are now read directly from LuwiPress Settings (the experimental WordPress 7.0 Connectors bridge has been retired).

= 3.12.2 — Reliable Elementor page translation (no more stuck queue) =
* **Fixed: large pages failing to translate.** Page translation now sends text to the AI in small batches instead of one oversized request, so full pages (homepages with many sections, repeaters, FAQs) no longer hit the 60-second API timeout that previously left them untranslated. A slow or failed batch now only affects that batch — the rest of the page still translates.
* **Fixed: pages permanently skipped by the translation queue.** A transient AI timeout could wrongly flag a page as "no translatable text," after which the background queue skipped it forever. The queue now re-checks and self-heals: if a flagged page actually has content, it clears the flag and proceeds.
* Translating an Elementor page always rebuilds the translation's structure from the source language, so translations whose layout had drifted from the original are realigned automatically.
* The vendor "made by" manufacturer/author structured data and the attribution repair tool now read the product↔vendor link directly, so they work on multilingual (WPML) sites where the attribution was set only through the vendor category. Closes a case where, after 3.12.0, the rich result still stayed missing on multilingual product pages because the language filter hid the category link.

= 3.12.0 — Redirect manager, link audit, product snapshots, related-content fields =
* **Redirect manager (Rank Math):** create, bulk-create, list, update and delete URL redirects remotely — built for post-migration cleanup so old indexed links 301 to their new home. Bulk-seed up to 200 redirects in one call. Requires Rank Math with its Redirections module; degrades gracefully on sites without it.
* **Internal link audit:** find every page that links to a given URL, path or slug — with occurrence counts and a context preview — before you change a slug or move a domain. Read-only.
* **Product snapshot & rollback (WooCommerce):** capture a full snapshot of a product (content, all custom fields, gallery, categories and every variation) before a risky bulk edit, then roll it back in one step if something breaks. Keeps the last 10 snapshots per product; a safety snapshot is taken automatically before each rollback.
* **Related-content fields:** custom content types can now link to each other with a "relationship" field you can query in both directions (e.g. "which courses list this teacher?").
* **Vendor rich-result fix:** the "made by" manufacturer/author structured data now appears whenever a product is attributed to a vendor — whether the attribution was set through the product's vendor field or the vendor category — so it no longer goes missing on some products.
* **Attribution repair tool:** a scan-and-repair pass finds products linked to a vendor through the vendor category but missing the matching vendor field, and fills it in (preview first, additive — never removes a link) so vendor "their work" grids and the Knowledge Graph see every attributed product.
* **WPML auto-config (optional):** an opt-in setting can register operator-defined content types with WPML at runtime (the recommended path remains the ready-to-paste config on the Content Types screen).

= 3.11.2 — Custom content type Schema.org polish =
* JSON-LD for custom content types: array properties (knowsAbout, sameAs, keywords) now split on semicolons as well as commas, and string values are HTML-entity-decoded so e.g. "Founder & CEO" emits correctly instead of "Founder &amp; CEO". Pairs with WebMCP 1.0.42 (safer term meta + bulk meta + any-CPT create).

= 3.11.1 — Fix: custom content types now actually emit their Schema.org JSON-LD =
* Fixes a 3.11.0 timing bug where a custom content type's Schema.org mapping was wired up too early (before the type list had loaded), so no JSON-LD was emitted on the front-end. Custom types that have a Schema.org @type set now correctly output their structured data. The built-in Vendors and Events schema were unaffected.

= 3.11.0 — Custom content types emit Schema.org JSON-LD + Knowledge Graph polish =
* **Your own content types now output Schema.org structured data.** Set a Schema.org type (Person, Organization, LocalBusiness, Event…) on a custom content type and map each field to a schema property in the Content Types manager — LuwiPress now emits valid JSON-LD on that type's pages, the same rich-results signal the built-in Vendors and Events presets already produce. Several fields can collect into `sameAs` (social links), comma lists become arrays (e.g. `knowsAbout`), and dotted properties nest (e.g. a location into a postal address). Until now the mapping was saved but never rendered on the front-end.
* **Knowledge Graph sidebar actions are reliable again.** The quick actions on a node (enrich, write SEO, translate, run a category batch, Autopilot save/run…) no longer fail with "Cookie check failed" after a page has been open for a while — they now carry your API token alongside the session cookie, so a stale cookie no longer blocks them.
* **Friendlier AI Autopilot panel.** Autopilot settings are now a short guided panel — an Off / Dry-run / Live mode picker plus plainly-labelled "what it acts on" and "daily budget" steps — instead of a cramped row of abbreviations. The first Knowledge Graph load also shows an animated graph "x-ray" with a progress bar instead of a bare spinner.

= 3.10.0 — Content Types manager (CPT Engine UI) =
* **NEW Content Types manager** under LuwiPress → Site → Content Types. See every content type your store runs — Vendors, Events and any custom one — with its fields, taxonomies, Schema.org mapping and (when WooCommerce is active) product attribution, all in one place.
* **Build your own content types from the UI.** Add a custom type (Team, Artists, Venues, Recipes…) with a visual field builder over ten attribute types (text, number, URL, image, date, select, relationship and more), optional taxonomies, a Schema.org type mapping, and a one-field WooCommerce product binding — no code, no REST calls. New types are translatable and Elementor-ready automatically, and a ready-to-paste WPML configuration is generated for you.
* **Cleaner vendor attribution on multilingual stores.** The "Attribute this product to…" box on the product editor now lists each vendor once (previously it repeated the same vendor for every language), and always saves the canonical vendor reference so the storefront, Knowledge Graph and product filters stay in sync.
* **Friendlier Knowledge Graph loading.** While the graph builds for the first time, the canvas now shows an animated "x-ray" of the graph forming plus a progress bar with live status ("Reading your catalog…", "Scoring store health…") instead of a bare spinner — so a long first build no longer feels frozen.

= 3.9.1 — Events in the Site hub + clearer protected-account UI =
* **Events settings now live in LuwiPress → Site** (a new "Events" tab beside Vendors) instead of a separate menu, keeping every site tool in one place. The Events post type keeps its own top-level menu for adding and editing events.
* **Bot Defense → Accounts** now clearly marks accounts that are protected from deletion (a real first + last name, a verified email, or an existing order): they show a "Protected" badge, can't be selected, and are skipped by "Select all" — so it's obvious why a delete leaves them in place. Behaviour is unchanged (these accounts were always protected); the list just makes it visible instead of silently skipping them.

= 3.9.0 — CPT Engine: Events + automatic multilingual config =
* **NEW Events content type (CPT Engine preset #2).** Turn concerts, workshops and classes into a first-class content type with structured fields (date/time, venue, online link, ticketing) — disabled by default; enable it under LuwiPress → Events. Each event emits valid schema.org Event JSON-LD for rich results and offers a one-click downloadable `.ics` calendar file.
* **Link events to your Vendors** as organizers and performers — the relationship flows straight into the event's structured data, reusing your existing vendor profiles (supports multiple performers per event).
* **Automatic WPML & Polylang configuration.** LuwiPress now ships a language-configuration file and registers its content types with Polylang automatically, so Vendors, Events and their taxonomies + fields become translatable in the Translation Manager with no manual setup. Operator-defined content types are surfaced to Polylang too, and a ready-to-paste WPML configuration can be generated on demand.

= 3.8.2 — CPT Engine: WPML / Polylang-aware attribution terms =
* On multilingual stores the WooCommerce attribution taxonomy now keeps **one term per content entry** (keyed to its source language) instead of one per translation — so the Products filter lists each vendor once, not once per language. A one-time background sweep collapses any duplicate language-sibling terms a previous version created; products are never detached and the canonical data is untouched.

= 3.8.1 — CPT Engine: WooCommerce attribution as a first-class taxonomy =
* **Products are now indexed by their attributed content type (e.g. Vendors) as a real WooCommerce taxonomy**, not just a hidden meta field. Each CPT Engine type that attributes to products gets a `product_<type>` taxonomy mirroring its entries, so you can filter the Products list by vendor and feeds / Store API / term queries see the relationship natively (no more slow meta scans).
* Attribution stays editable from the same place (the product's Vendors box); the taxonomy is kept in lockstep automatically in both directions, and a one-time background sweep indexes existing products. Existing data and front-end output are untouched (the canonical meta is preserved).

= 3.8.0 — CPT Engine: translate + display any content type =
* **Custom content types are now first-class in the Translation Manager.** Every content type managed by the LuwiPress CPT Engine — Vendors today, plus operator-defined types (Team, Events, …) — and its taxonomies now appear in the Translation Manager's steps alongside Products / Posts / Pages, so they translate the same way (engine post types join the translatable whitelist via the `luwipress_translatable_post_types` filter).
* **New "CPT Grid" Elementor widget** (LuwiPress Gold theme) renders any engine content type as a responsive card grid on any page — pick a content type, optionally filter by a taxonomy term, map up to two meta fields onto each card. Reuses the Master Grid card chrome; the vendor-specific Master Grid widget is unchanged.
* Engine taxonomies surface in the "Translate Taxonomies" step using the same WPML visibility gate as the core taxonomies, so no no-op "Translate" buttons appear for types WPML isn't yet tracking.

= 3.7.5 — Per-group vendor URLs + slug-pattern cleanup =
* **Per-group archive slugs for Vendors.** Each vendor group (Vendor Groups taxonomy) can now carry its own URL base — set "Archive slug" on the group's edit screen (e.g. `team` → `/team/<vendor>/`, `music-academy-teachers` → `/music-academy-teachers/<vendor>/`). On the vendor edit screen, pick the **Primary group** to choose that vendor's canonical URL base. Vendors with no primary group keep the global Vendors archive slug, so existing vendor URLs don't change.
* The core Slug Resolver now leaves group bases alone (no accidental 301 to the global archive).
* **Fix:** the Vendors `single_slug_pattern` value is normalized on read/write — strips a stray trailing quote that could creep in and make the value look corrupted when read over MCP/REST.

= 3.7.4 — Admin design-system cohesion =
* **One consistent look across every admin screen.** Usage & Logs, Translation Manager, the Content Scheduler and six tool pages (Vendors, Slug Resolver, Image Alt Bulk, Schema Preview, Schema Picker, Content Audit) now share the same branded header (logo + version pill + Dashboard shortcut), tab strip and card styling as the Dashboard.
* **Connection settings folded into General.** The Settings nav drops to 5 tabs — "Connection" (API token, MCP & REST) is now a collapsible section under **General**, beside General and Content Health. Old `?tab=connection` links still resolve.
* All admin colours now flow through the design-token palette (no hard-coded hex); the Translation drift / sync-audit panels were re-tinted to the standard error / warning / info severities.

= 3.7.3 — One Site hub + collapsible admin everywhere =
* **Content tools folded into the Site hub + grouped.** The separate "Content" sidebar item is gone — the **Site** page now holds every tool under a handful of top sections (**Content** · **SEO & Taxonomy** · **Security** · **Theme** · **Vendors**). Pick a section, then a tool — it opens directly with its own collapsible panels (one tool loads at a time, so the page stays fast and nothing nests). One fewer sidebar row, nothing lost. Old `?page=luwipress-content` links (and every old tab deep-link) still resolve onto the right section.
* **Consistent collapsible UI across the admin.** Vendors, Slug Resolver and Theme pages — plus the Settings **AI** (API Keys + AI Content) and **General** (General + Content Health) tabs — now use the same clean accordion sections as Bot Defense and Cookie Consent. Top buttons, content opens below, collapse what you're not using.
* CSS now cache-busts on file modification time so admin styling updates appear immediately.

= 3.7.2 =
* Admin UI cohesion pass. Settings nav trimmed from 8 tabs to 6: "AI API Keys" now lives alongside "AI Content" under a single **AI** tab, and "Content Health" now lives under **General**. Old `?tab=api-keys` / `?tab=content-health` links still resolve.
* Reference/help cards are now collapsible (Schema Picker "Reference" and Schema Preview "Schema Registry" overview) so the working areas stay uncluttered.
* Dashboard **Customer Segments** pills are now clickable — click a segment to open a drawer listing that segment's customers.
* **Recent Activity** rows now show a minimal actor pill (WebMCP / ThemeBridge / Agent / System / You) so you can see who or what performed each action at a glance.
* Knowledge Graph **Vendors** and **Customers** stat cards now show their real counts (previously blank), and the counts are always present in the summary even when a section isn't requested.

= 3.7.1 =
* Admin UI modernization: unified card spacing via a container-gap rhythm (fixes uneven grey gaps between cards, e.g. the Content > Schema tab), glassmorphism hub tabs with the hover top-border bug fixed, standardized button sizing (32px / 26px small) so native and lp-btn buttons match, calmer card hover.
* Fix: Schema Preview "critical error" that could appear when a product-category quick-target permalink failed to resolve (WP_Error/false guard + cache key bump).

= 3.7.0 — Vendor manufacturer schema + per-vendor entity type + leaner admin =
* **NEW Product manufacturer/author schema on Rank Math sites:** when a product is attributed to a vendor, that vendor now lands in the product's JSON-LD as `manufacturer` (or `author` for person-type vendors) — including on sites where **Rank Math** manages product schema (previously the attribution only reached WooCommerce-native structured data, which Rank Math replaces). A strong vendor-attribution signal for Google.
* **NEW vendor `makesOffer` + `worksFor` schema:** each vendor profile page now declares the products that vendor makes (`makesOffer`) and the store they work for (`worksFor`) — richer E-E-A-T identity for makers, ateliers and luthiers. Language-scoped and cached.
* **NEW per-vendor Schema entity type override:** a single vendor (e.g. an atelier or workshop) can now emit `@type` Organization while every other vendor on the site keeps the global default. Set it from the new **Schema entity type** box on the vendor edit screen, via REST `/vendors/{id}/meta`, or the WebMCP `vendor_meta_set` tool. Copies to WPML translation siblings automatically.
* **Vendor profiles are now translatable** through the Translation pipeline (the vendor CPT was already WPML-registered; the request path now accepts it). Filterable via `luwipress_translatable_post_types`.
* **Leaner Settings:** the **CRM + Customer Chat** tabs are now one **Customers** group and **Security + Bot** one **Security** group, each with collapsible sections — fewer top-level tabs, less clutter. Old `?tab=` deep links still resolve and auto-open the right section.
* **Smarter Schema target picker:** the Schema tab's "Pick target" box replaces the type dropdown + raw numeric-ID field with a single **search-by-title** typeahead that resolves any post, page, product or category and shows it as a removable chip — no more hunting for IDs.

= 3.6.3 — Event schema + admin UI tidy =
* **NEW Event schema type (FR-024):** `schema.org/Event` is now a first-class built-in type in the Schema Registry — writable through `aeo_save_schema` / the Schema tab on any post (default target: a blog post). Fields: name, startDate, endDate, description, image, eventStatus, eventAttendanceMode, location (physical `Place` or, when only a URL is given, `VirtualLocation`), organizer, performer, offers (price/currency/url/availability). Short enum values are normalized to canonical schema.org URLs (e.g. `offline` → `OfflineEventAttendanceMode`, `Scheduled` → `EventScheduled`). WPML-aware exactly like FAQ — write per language-sibling `post_id` for multilingual event pages. Zero new files / hot-path load: it slots into the existing registry alongside Course/Service/Review.
* **Content hub:** the **Schema** and **Schema Preview** tabs are now a single **Schema** tab — the JSON-LD editor (Picker) and the live URL inspector (Preview) render stacked under one tab instead of two. The old `?tab=preview` / `luwipress-schema-preview` deep links redirect to the merged tab. Content-hub tabs can now declare multiple page files via a `files[]` array.
* **Vendors:** the Site → Vendors roster table (which duplicated the WordPress CPT list with fewer columns and empty specialty cells) is replaced by a compact panel that links to the native **Manage all <Vendors>** list table — the canonical roster that already ships WPML/Polylang language columns, SEO status, search, sort, bulk actions and pagination. Less code, no parallel half-table to keep in sync.
* No data or REST change — purely an admin-surface consolidation toward a leaner, less scattered UI.

= 3.6.2 — Agentic Commerce moved to the LuwiPress Agentic companion =
* **Architecture:** the Agentic Commerce modules (Google UCP feed + native checkout, AP2 mandate audit trail) introduced in 3.6.0 have moved out of core into the **LuwiPress Agentic** companion plugin (1.3.0+). The core stays lean for the majority of stores that don't sell through AI agents. No feature is lost — install/activate LuwiPress Agentic 1.3.0 to keep the `LuwiPress → Commerce` hub, the `/ucp/*` and `/ap2/*` REST routes, and the `ucp_*` / `ap2_*` WebMCP tools. The WebMCP tools already guard on `class_exists`, so they register only when the companion is active.
* **Upgrade note:** deploy core 3.6.2 **together with** LuwiPress Agentic 1.3.0. Updating core alone removes the Commerce hub until the companion is updated. The `wp_luwipress_ucp_sessions` table and all stored UCP/AP2 settings + order meta are untouched by the move — the companion reuses the same table and option names.

= 3.6.1 — Knowledge Graph: vendor node detail panel =
* **Fix:** clicking a vendor node in the Knowledge Graph opened an empty sidebar. The detail panel renderer had no `vendor` branch (and no fallback), so vendor clicks rendered nothing. Vendor nodes now open a full operational panel: entity-type badge (Organization / Person / Local Business), an E-E-A-T score health bar, profile stats (products attributed, specialty, location, role, group), severity-graded recommendations ("Attribute products" when none are linked, "Strengthen E-E-A-T profile" when the score is low), and Edit Vendor + View Profile actions — matching the product / segment / category panels.
* Vendor nodes are now color-tinted by E-E-A-T score (teal strong / amber partial / red weak) instead of falling through to neutral gray, and gain a hover tooltip (E-E-A-T, product count, specialty). JS-only change; cache-busts automatically on the asset version stamp.

= 3.6.0 — Agentic Commerce: Google Universal Commerce Protocol (UCP) + Agent Payments Protocol (AP2) =
* **NEW Agentic Commerce hub (`LuwiPress → Commerce`)** — a dedicated admin surface for selling through AI agents on Google AI Mode (Search) and Gemini. Tabs: Overview (UCP readiness checklist + eligibility coverage), UCP Feed (store + per-product settings + supplemental feed preview), Checkout (native checkout session tester), AP2 (mandate verifier + settings), Transactions (per-order mandate audit trail).
* **NEW `LuwiPress_UCP` module — Universal Commerce Protocol feed readiness.** Marks products eligible for Google's UCP "Buy" button via the `native_commerce` attribute, attaches a `consumer_notice` for regulated items, and maps a `merchant_item_id` when your feed id differs from your checkout id. Store-level return policy + customer-support settings (Merchant Center / Merchant-of-Record requirements). Per-product eligibility validator + store-wide coverage report. Supplemental feed generator (JSON / CSV / XML) that overlays your existing Merchant Center feed without touching primary product data. REST: `/ucp/settings`, `/ucp/eligibility`, `/ucp/product/{id}`, `/ucp/feed`.
* **NEW `LuwiPress_UCP_Checkout` module — UCP Native Checkout.** Implements the three UCP checkout endpoints (session create / update / complete) backed by the WooCommerce order engine, so an agent can build a cart, resolve real tax + shipping rates, and place an order without the buyer leaving the conversation. Each session is backed by a WooCommerce `checkout-draft` order, so all totals come from WooCommerce itself. Sandbox mode (default ON) simulates completion without creating a payable order; live mode hands off a pending order for the processor to capture. Totals are always recomputed server-side. New `wp_luwipress_ucp_sessions` table. REST: `/ucp/checkout/session` (+ `/{id}`, `/{id}/complete`).
* **NEW `LuwiPress_AP2` module — Agent Payments Protocol mandate audit trail.** When an agent presents a cryptographically-signed Cart Mandate at checkout, LuwiPress verifies it (structure, expiry, issuer allowlist, and an amount-match so the mandate total must equal the order total — "what you see is what you pay for") and stores the Intent → Cart mandate chain on the order as a non-repudiable audit trail. Cryptographic signature verification plugs in via the `luwipress_ap2_verify_mandate` filter for payment-processor SDKs. Optional strict mode aborts checkout on an unverified or mismatched mandate. REST: `/ap2/settings`, `/ap2/mandate/verify`, `/ap2/transaction/{order_id}`, `/ap2/log`, `/ap2/checkout/complete`.
* **15 new WebMCP tools** (companion 1.0.36) — `ucp_settings_*`, `ucp_eligibility_report`, `ucp_product_*`, `ucp_feed_preview`, `ucp_checkout_session_*`, `ap2_settings_*`, `ap2_mandate_verify`, `ap2_transaction_get`, `ap2_log_recent`. Checkout/complete tools are write-classified so the autonomous agent loop never auto-invokes them.
* **Default-OFF + sandbox-first** — every new surface is opt-in; UCP and AP2 ship disabled, sandbox enabled, so nothing changes on a store until the operator turns it on. WooCommerce stays a soft dependency: REST registers regardless, order-bound paths are guarded.

= 3.5.8 — Design system rollout: every admin page now speaks the Dashboard / Knowledge Graph language =
* **Design-token refactor across 6 admin pages** — Slug Resolver, Content Audit, Schema Picker, Schema Preview, Vendors, Image Alt Bulk. Every inline `style="color:#XXX"` and hardcoded hex literal has been replaced with `--lp-*` design tokens; every classic `button button-primary` is now `.lp-btn--primary`; every ad-hoc stat banner is now a `.lp-stat-row` of `.lp-stat--success/warning/error/info` semantic accent cards. Result: the entire LuwiPress admin reads as one continuous surface — Dashboard, Knowledge Graph, the hub tabs, and Settings all share the same border-left accent grammar, the same button shapes, the same focus rings, and the same dark-mode override.
* **New `.lp-btn` button family** — `.lp-btn--primary` / `.lp-btn--outline` / `.lp-btn--ghost` / `.lp-btn--danger` plus `.lp-btn--sm` / `.lp-btn--lg` sizing modifiers. Promoted from KG's `.kg-btn` so every admin page renders the same button geometry: 36 px min-height, 8 px radius, 1 px border with a `var(--lp-primary)` hover lift and an `outline: 2px var(--lp-primary-100)` focus ring. Disabled state preserves shape, drops elevation.
* **New `.lp-stat-row` / `.lp-stat` family** — semantic-accent stat cards used in every page hero. Five intent classes (`--success` / `--warning` / `--error` / `--info` / `--muted`) flip the border-left accent. Pairs with `.lp-stat-value--success/warning/error` for matching number colour and `.lp-stat-list` + `.lp-dot--success/warning/error/info/muted` for environment-check sublists.
* **New `.lp-switch` toggle** — iOS-style switch replacement for raw `<input type="checkbox">` settings toggles. Resolver enable / Cookies enabled / Bot Shield on use this. Keyboard focus ring intact, disabled state honoured.
* **New `.lp-form-row` / `.lp-form-input` / `.lp-form-textarea` / `.lp-form-select`** — uniform form-control set with `--lp-primary` focus border + `var(--lp-primary-100)` outer glow on focus. Replaces every page's ad-hoc `<label style="font-size:11px;text-transform:uppercase">` boilerplate.
* **Settings page header + tab strip modernised** — pre-3.5.8 Settings used WP's classic `<nav class="nav-tab-wrapper">`. 3.5.8 swaps in the same `lp-header` (logo + version pill + Dashboard/Usage shortcut icons) and `.lp-hub-tabs` (Connection / General / AI API Keys / AI Content / Translation / CRM / Customer Chat / Security / Bot / Content Health) used by the Content + Site hubs. Companion plugins hooking `luwipress_settings_render_tab_nav` continue to render unchanged; updating them to emit `<a class="lp-hub-tab">` instead of `<a class="nav-tab">` will pick up the new token styling.
* **Knowledge Graph stat row cleanup** — pre-3.5.8 metrics whose backing module wasn't active rendered as muted "N/A" cards in the 12-card top row (Media Health, Vendors, Customers were the typical offenders). 3.5.8 collapses any card whose value resolves to `null` entirely — `kg-stat-disabled` now sets `display: none`. The dashboard reads as the metrics that genuinely apply to this site instead of a wall of placeholders.
* **No backend behaviour change** — REST endpoints, WebMCP tool catalog, JSON-LD emitters, cron jobs, AI workflows untouched. 3.5.8 is purely an admin-UI design system rollout on top of 3.5.7's information architecture. Deploy is operator-only (admin pages); no schema migration, no transient bust, no cache flush required.

= 3.5.7 — Admin UI consolidation: 15 submenus → 7 hub-based menus + design-token tab strip =
* **NEW `LuwiPress → Content` hub** — single submenu that houses every content-side tool: Health Audit / Schema / Schema Preview / Taxonomy / Image Alt / Scheduler. Server-rendered tab strip (`?tab=` whitelist) using the new `.lp-hub-tabs` design-token component. Each tab pulls its existing page file with `LUWIPRESS_HUB_INCLUDED` constant defined so outer chrome (wrap + h1 / lp-header) is suppressed and only tab body renders.
* **NEW `LuwiPress → Site` hub** — single submenu for migration / identity / security tools: Slug Resolver / Vendors / Theme / Bot Defense / Cookie Consent. Same hub pattern as Content. Vendor module + Theme bridge appear only when their guard class is loaded; missing capabilities silently drop from the strip.
* **Information architecture reorg** — pre-3.5.7 the menu had 15+ flat submenus that overflowed the WP sidebar and split related tools across unrelated entries. 3.5.7 collapses to 7 visible items in the operator-intent order: Dashboard → Knowledge Graph → Content → Translations → Site → Settings → Usage & Logs. Operators see the menu at a glance instead of scrolling past a wall of submodule slugs.
* **Backwards compatibility — zero deep-link breakage** — every pre-3.5.7 submenu slug (e.g. `luwipress-slug-resolver`, `luwipress-content-audit`, `luwipress-bot-defense`, …) remains addressable as a hidden parent-less submenu whose callback `wp_safe_redirect()`s to the new hub home with the matching `?tab=` parameter. Bookmarks, WP-CLI deep links, documentation links, and MCP tool integrations continue resolving without operator action.
* **Design system extension** — new `.lp-hub-tabs` + `.lp-hub-tab` + `.lp-hub-tab--active` + `.lp-hub-tab-badge` CSS components added to `assets/css/admin.css`. Same `--lp-*` token system the Dashboard and Knowledge Graph already use (primary border on active, surface-hover on tab hover, focus outline, dark-mode-ready via the existing `prefers-color-scheme: dark` override block, responsive shrink below 782px).
* **Translation Sync deep-link** — the standalone `luwipress-translation-sync` slug (3.5.4+) now redirects to `luwipress-translations&tab=sync-audit`, the canonical location inside the Translation Manager page.
* **Scheduler relocation** — `LuwiPress_Content_Scheduler` submenu (registered by its own module) is now a hidden parent so the only visible entry point is Content hub → Scheduler tab. The `luwipress-scheduler` URL still resolves for any tool / script that already calls it directly.
* **No backend behaviour change** — REST endpoints, WebMCP tool catalog, JSON-LD emitters, cron jobs, AI workflows are all untouched. 3.5.7 is purely an admin-UI information-architecture release. Deploy is operator-only (admin pages); no schema migration, no transient bust, no cache flush required.
* **Note** — eski sayfaların iç gövdelerinde kalan `style="color:#XXX"` inline tarz ve hex code'leri 3.5.8 sprint'inde tüm sayfa-içeriği `--lp-*` token'lara çevrilerek hub içindeki görsel diline tam uyumlu hale getirilecek. 3.5.7 IA + hub structure release'idir.

= 3.5.6 — Pre-swap WebMCP↔UI parity sprint: 5 new admin pages + media alt bulk endpoint =
* **NEW `LuwiPress → Slug Resolver`** — admin UI over the six-pass page→product_cat redirect engine (3.1.56+). Status hero with map size / overrides count / template_redirect hook health / WPML+Polylang+WC env pills. Composed-map table with filter, override badges, and per-row remove. Probe textarea for pre-DNS-swap verification — paste swap-day slugs, every one resolves through the same six-pass logic the live redirect uses, hit-rate displayed. Add Override form with term-id / URL / true (auto) / false (suppress) target types. Force Rebuild button busts the resolver transient and rebuilds the map in one click. Critical pre-swap surface for migrations: closes the gap where the resolver was reachable only via `/luwipress/v1/slug-resolver/*` REST + 5 WebMCP tools.
* **NEW `LuwiPress → Vendors`** — settings UI for the generic Vendor / Maker / Atelier CPT module (3.5.0+). Identity card (archive slug, singular/plural labels, Schema.org entity_type, default occupation, menu icon). Permalink card (with_front toggle, archive_enabled, single_slug_pattern). Profile field visibility toggles (location, specialty, years, quote). Social link field toggles (8 platforms: Facebook / Instagram / YouTube / SoundCloud / LinkedIn / X / Behance / Website) — populated URLs flow into JSON-LD sameAs. Legacy URL redirects editor (dynamic row repeater, e.g. /masters/ → /luthiers/). Vendor roster table with profile-meta completeness indicators (social-link count, specialty pill, edit links to native WP admin). Closes the gap where vendor module config required `/vendors/settings` REST + 6 MCP tools.
* **NEW `LuwiPress → Taxonomy Editor`** — multi-language matrix editor (`LuwiPress_Taxonomy_Editor` class + REST `/taxonomy-editor/*` + admin page). Single screen edits term name + description + SEO meta (Rank Math title / description / focus_keyword) across every active WPML/Polylang language. Each term group renders as one accordion row with a (5 fields × N languages) cell grid. Click-to-edit cells; Save All collapses every dirty cell into one REST POST that fans out to the 3.5.5 `/taxonomy/seo-meta-bulk` handler + WPML-aware term name/description update path. Closes 52 cat × 4 lang × 5 fields = 1040 sequential MCP calls into 1 batch.
* **NEW `LuwiPress → Image Alt Bulk`** — Media Library scan + bulk alt-text editor. Hero stat row (total / missing / has-alt / coverage %). Filter pills (Missing / Has alt / All) with counts. Search by filename or title. Per-row "Use parent title" auto-fill button (one-click default from attached post/product). Inline alt-text editor with empty-state badge + char count. Save All collapses 50+ row edits into one `POST /media/alt-bulk` round trip. Closes the gap where bulk alt-text editing required either opening every attachment modal one at a time in native Media Library or scripting through WebMCP `media_update` calls.
* **NEW `LuwiPress → Schema Picker`** — operator UI for the seven non-FAQ Schema Registry types (HowTo, Speakable, LocalBusiness, Service, Course, Review, AggregateRating). FAQ has its own dedicated metabox (3.5.5); ItemList is auto-generated on category archives. Object selector (post or term + ID). Existing-schemas panel with per-type edit + delete buttons. Starter JSON templates per type — operator opens the template and edits inline. Validate-JSON button for syntactic check before save. Cross-links to Schema Preview for post-save verification + Google Rich Results Test handoff. Closes the gap where the 7 non-FAQ schema types were saveable only via `aeo_save_schema` MCP tool.
* **NEW `POST /media/alt-bulk`** — sibling of `/taxonomy/seo-meta-bulk`. Up to 500 attachment alt-text rows per request. Empty / null alt_text values DELETE the meta (operator may want to clear a placeholder); non-empty values write through `sanitize_text_field`. Powers the Image Alt Bulk admin page and is usable from headless callers (scripts / WebMCP).
* **Pre-swap relevance** — this sprint targets the new.tapadum.com → tapadum.com DNS-swap window. Slug Resolver lets the operator probe every swap-day URL slug before flip; Vendors confirms the Tapadum luthiers archive labels + social toggles before customers see it; Taxonomy Editor closes the 52-category multi-language sweep into one screen; Image Alt Bulk lets a single pass cover the legacy uncovered media; Schema Picker brings the non-FAQ schema types (LocalBusiness for the atelier address, Service for custom-build inquiry pages) into the standard mass-market UX path.

= 3.5.5 — Content Health Score rubric + Sprint 1A surfaces + FAQ Tab Editor + bulk taxonomy SEO meta + Translation Sync hoist =
* **NEW `LuwiPress_Health_Score`** — six-pillar weighted composite scoring engine that turns the gamified KG dashboard into a measurement rubric instead of a vibe. Pillars (default weights configurable per-site): **SEO Coverage 25%**, **AEO Coverage 20%**, **Translation Health 15%**, **Schema Coverage 15%**, **Brand Voice 10%**, **Content Depth 10%**. Each pillar returns `{score, target, action_threshold, findings[], pillar_weight, weighted_contribution}` — operators see not just "your store is at 67/100" but also which pillar dragged the score and what the highest-leverage next action is. 15-minute transient cache (`luwipress_health_score_cache`) on the composite; pillar-level findings cached per-pillar so a single product enrichment busts only the relevant pillar entry. Knowledge Graph hero now reads `summary.health_score` from PHP first, falls back to the legacy JS-side formula on stale cache only. New REST surface: `GET /health/score` (full composite), `GET /health/pillars` (per-pillar drill-down), `POST /health/reset` (force cache rebuild). New **Content Health** settings tab in LuwiPress → Settings exposes per-pillar weight + target + action_threshold + per-CPT word count targets for the Content Depth pillar.
* **NEW `POST /taxonomy/seo-meta-bulk` + `taxonomy_seo_meta_bulk` MCP tool** — bulk write Rank Math / Yoast / AIOSEO / SEOPress meta on up to 500 taxonomy terms in a single call. Mirrors the post-side `seo_meta_bulk` endpoint. Each row carries `{term_id, taxonomy, title?, description?, focus_keyword?}` (any combination of fields). Unblocks the multi-language taxonomy editor (Sprint 2): a 52-category × 4-language × 3-field sweep that previously needed 624 sequential `taxonomy_meta_set` calls now ships in one batch.
* **Sprint 1A — Content Audit admin page** (`LuwiPress → Content Audit`). Three-tab unified UI: **Promo Phrases** (3.4.1 detector), **AI-Tells** (new), **Word Count** (new). Hero stat row drives the Brand Voice + Content Depth pillars. Findings rendered with severity bands + per-item edit-link buttons. The AI-Tells tab covers the §1.8 AI-tell blacklist from the Tapadum SEO yazım rehberi — phrases like "In the world of…", "stands as one of the most…", "In conclusion," in five languages (en/tr/fr/it/es) — extensible via `apply_filters('luwipress_ai_tell_phrases', $bank, $lang)`. New REST: `POST /content/ai-tell-audit` + `GET /content/ai-tell-bank`. Word Count tab reads the new per-CPT word count target settings (default: product 500-650-800, post 1200-1500-2200, page 300-400-600) and flags posts below target.
* **Sprint 1A — Schema Preview admin page** (`LuwiPress → Schema Preview`). Schema Registry overview (every registered type with status pill) + URL inspector that wraps the 3.4.1 Frontend Inspector and parses each `<script type="application/ld+json">` block on the rendered URL + Google Rich Results Test handoff link. Closes the chrome-devtools-mcp round-trip cycle for non-WebMCP customers who need to verify JSON-LD output after an `aeo_save_*` call.
* **Sprint 1A — Translation Sync hoist** (`LuwiPress → Translation Sync` submenu). Deep-link redirect to the existing Translation Manager page with `?tab=sync-audit` query param — the existing `<details>` sync-audit panel auto-opens + scrolls into view, so the 3.1.54 Translation Sync Audit module is now reachable in one click from the LuwiPress menu instead of buried inside Translations.
* **Sprint 1B — FAQ Tab Editor metabox** (`LuwiPress_FAQ_Editor`). Inline metabox on `product` / `post` / `page` edit screens (filter-extensible via `luwipress_faq_editor_post_types`). Row repeater UI: question + answer inputs, **AI generate** button (proxies `/aeo/generate-faq` async pipeline + polls status), word count badges per row (50–80 words = green, 30–49 or 81–110 = amber), status pill (empty / manual / completed / processing / failed), reorder via ↑/↓ buttons. Save through standard `save_post` hook — no extra REST round-trip needed. Read-tolerant write-canonical: accepts 3 legacy storage formats on read (canonical array / serialized array / JSON string — covers 92.5% of legacy installs + every MCP write path) but always writes the canonical `[{question, answer}, …]` array shape. Hard cap 20 rows; empty rows dropped silently; full clear → meta deleted entirely (no orphan empty FAQPage schema). Cache invalidation chain on save busts `luwipress_aeo_coverage` transient + Health Score cache + Plugin Detector post cache. **Closes the biggest non-WebMCP UI gap** — until now `_luwipress_faq` was MCP/REST-only writable; classic-editor and Gutenberg operators on the CodeCanyon mass-market path could not author FAQs without dropping into the API.
* **Knowledge Graph `summary.health_score` injection** — `build_summary()` now embeds the full Health Score composite (overall score + per-pillar scores + top findings) into every `/knowledge-graph` response, so the dashboard hero, Action Queue, and external KG consumers see a single source of truth.

= 3.5.3 — Vendor-FR-016 fix wave (bidirectional vendor↔product render robustness) =
* **Vendor-FR-016 root cause + fix.** Vendors module's `_lwp_vendor_ids` product meta was previously written through different paths producing TWO incompatible JSON shapes: the canonical save handler emitted `["123"]` (quoted strings, what `LuwiPress_Vendors::save_product_vendor` writes), while direct seed scripts + raw MCP meta_set calls produced `[123]` (integers, no quotes). The vendor profile template's LIKE query used `sprintf( '"%d"', $vendor_id )` which only matched the quoted shape — so vendors seeded via third-party scripts had `product_count > 0` in the Knowledge Graph but rendered empty "work grids" on their profile pages (Tapadum's Feramis Aktas hit this with 4 attributed products that never showed up). **Three-part fix:** (1) `register_post_meta` registration for `_lwp_vendor_ids` on the `product` post type with a new `normalize_product_vendor_ids` sanitize_callback that accepts ANY shape (JSON / comma-list / array / single integer) and normalizes to canonical `["123","456"]` — every future write through update_post_meta, REST, MCP, wp-cli, or third-party hooks gets normalized. (2) Companion theme query (`single-lwp_vendor.php` + `class-master-grid.php`) switched from LIKE to REGEXP with JSON-array-element-aware boundaries (`(\[|,)\s*"?<id>"?\s*(,|\])`) so both formats match AND `36633` doesn't false-positive against `366331`. (3) One-time idempotent migration `maybe_migrate_vendor_ids_format` runs on `init` p20 (skips AJAX/cron/REST), walks every product with the meta, and re-saves through the sanitize callback — pre-existing integer-format data normalizes automatically on next page load after upgrade. Flag stored in `luwipress_vendor_ids_normalized` option so subsequent loads no-op.
* **Knowledge Graph `made_by` edge fix.** When `/knowledge-graph?sections=vendors` was called without `products` in the same request, the `build_vendor_nodes` method used `load_vendor_product_counts` which produced correct `product_count` per vendor but returned ZERO `made_by` edges — so the KG vendor view rendered isolated nodes instead of the supply graph. New helper `load_vendor_product_counts_with_edges` runs ONE bulk DB query that returns BOTH the counts AND the edge list, so the vendors-only KG call now returns a complete supply graph topology.
* **`lwp-product-grid` translation registry entry.** New theme widget added in luwipress-gold 1.10.2 (`heading` field) gets a slot in `LuwiPress_Elementor::TRANSLATABLE_WIDGETS` so the WPML translation pipeline picks up the heading text on multilingual stores.

= 3.5.2 — Vendors module (E-E-A-T author/maker CPT) + Knowledge Graph vendor + customer x-ray =
* **NEW `LuwiPress_Vendors` module** — generic vendor/maker/atelier/team CPT (`lwp_vendor`) with E-E-A-T trust signals. One module, many verticals: a music store calls them "Luthiers", a restaurant "Chefs", a gallery "Artists", an agency "Team". Each site picks the right vocabulary via Settings (singular_label / plural_label / archive_slug). Entity type toggleable per site — `organization` (atelier), `person` (individual maestro / author) or `localbusiness` (physical store). Verified social URLs (Facebook, Instagram, YouTube, SoundCloud, LinkedIn, X, Behance, Website) flow into the Schema.org JSON-LD `sameAs` array via the Schema Registry, giving Google a strong author/vendor identity signal. WooCommerce integration: meta box on the product edit screen attaches vendors via `_lwp_vendor_ids` JSON meta, PDP "Made by" line, Schema.org `manufacturer` (or `author` when entity_type=person) injected into the WC Product schema.
* **NEW REST surface (luwipress/v1/vendors/*):** `GET/POST /vendors/settings`, `GET /vendors`, `GET /vendors/{id}`, `POST /vendors/{id}/meta`, `POST /vendors/sync-rewrite`. All vendor profile fields editable remotely; slug + label changes auto-flush rewrite rules. Legacy URL redirect pairs supported (e.g. `/masters/ → /luthiers/`) for seamless rename migrations.
* **6 NEW MCP tools (webmcp 1.0.34):** `vendor_settings_get`, `vendor_settings_set`, `vendor_list`, `vendor_get`, `vendor_meta_set`, `vendor_flush_rewrite`. AI agents can now read + write vendor profiles via the unified MCP surface.
* **Knowledge Graph `vendors` section.** New section in `GET /knowledge-graph?sections=vendors` — one node per published vendor with `eat_score` (0-100 weighted social + bio + image + location + product-attribution signals), `product_count` (back-reference from `_lwp_vendor_ids` meta), `social_count` + 8 verified social URLs, archive URL + edit URL. Product → vendor `made_by` edges connect the supply graph. The Knowledge Graph admin gets a new **Vendors view tab** + a **Vendors stat card** so the operator x-rays the supply-side of their store in one click.
* **Knowledge Graph `customers` section (privacy-gated).** New section in `GET /knowledge-graph?sections=customers` — individual top-N customers by lifetime spend, attached to their lifecycle segment. Default OFF behind two options: `luwipress_kg_show_customer_details` (gate the section entirely; default false) and `luwipress_kg_customer_names_full` (gate name masking; default false → names rendered as initials `J*** D**` for GDPR safety). Email addresses NEVER included in the graph payload — only WP user ID, masked display name, segment, order_count, total_spent, last_order_date. HPOS-aware: queries `wp_wc_order_stats` when present, falls back gracefully otherwise. New **Customers stat card** in the admin KG dashboard.
* **Theme adaptation (luwipress-gold 1.8.4+):** `archive-lwp_vendor.php` + `single-lwp_vendor.php` templates (renamed from `archive-lwp_person.php` / `single-lwp_person.php`); the `lwp-master-grid` widget now queries the new CPT slug and meta keys. Pulls live data from the new vendor module — switch entity type or archive slug per vertical without touching theme code.

= 3.4.1 — Promotional Phrase Audit + Frontend Render Dump + FR-013 A leg (Category FAQ archive render) =
* **NEW `LuwiPress_Content_Audit`** — promotional-phrase detection across post titles, SEO meta titles, meta descriptions, excerpts and body content. Built for daily Google Merchant Center compliance QA — catches phrases like "free shipping", "limited time", "şimdi al", "livraison gratuite", "spedizione gratuita", "envío gratis" before the product feed exports. Severity ladder: **high** (meta_title / meta_description — GMC-prohibited), **medium** (post_title / post_excerpt — feed-syndicated fallback), **low** (post_content body — flagged for editorial sweep, not feed-blocking). Multilingual phrase bank covering en/tr/fr/it/es with Unicode-aware word boundaries (Turkish ı/ş/ğ + diacritics match cleanly without false positives). Per-post language auto-detect via WPML/Polylang. Extensible via `apply_filters('luwipress_content_audit_phrases', $bank, $lang)`. New REST endpoints: `POST /content/promotional-phrase-audit` (sweep — accepts `post_id`, `post_type`, `category_id`, `lang`, `scope`, `limit`, `offset`, `only_violations`) and `GET /content/promotional-phrase-bank` (introspect the phrase bank).
* **NEW `LuwiPress_Frontend_Inspector`** — fetch a live URL with cache-bypass and dump structured data across four scopes in one call: **head** (title, canonical, robots, meta description / keywords / viewport / charset, hreflang chain, OpenGraph + Twitter card meta), **content** (word count, heading hierarchy h1..h6, image count with missing-alt count, internal/external/nofollow link counts, text/HTML ratio), **meta** (response headers — cache layer markers, X-Robots-Tag, content-language, link headers, Last-Modified, ETag) and **schema** (every `<script type="application/ld+json">` block parsed + summarised by @type — same shape as Schema Registry's diagnostic endpoint). Replaces ~5 chrome-devtools-mcp round-trips per audit with a single REST/MCP call. Designed for daily SEO QA, post-write verification, multilingual render parity probes and GMC pre-export checks. New REST endpoint: `POST /frontend/render-dump` (accepts `url` or `post_id` or `term_id+taxonomy`; optional `scopes` array to restrict).
* **FR-013 A leg — Category FAQ archive render (Tapadum follow-up).** Closes the visible UX side of FR-013: when a category term has `_luwipress_faq` term meta (saved via `aeo_save_faq` with `term_id+taxonomy` in 3.4.0), the archive page now renders a collapsible `<details>/<summary>` accordion via `woocommerce_archive_description` priority 20. Same data shape as the product FAQ tab (`{question, answer}` array), same `.luwipress-faq` CSS class so existing theme styles cascade. Wrapped in a `<section>` with an `<h2>` heading (filterable via `luwipress_term_faq_render_heading`; rendering itself filterable via `luwipress_term_faq_render_enabled`). FAQPage JSON-LD already shipped in 3.4.0 via Schema Registry's `faq` type — this leg adds the visible UX side. Unblocks Tapadum's 52-category × 4-language sweep (drop the body H3 fallback once content is migrated to term meta).

= 3.4.0 — Schema Registry + Category FAQ + ItemList + x-default hreflang (Vendor-FR-1 / FR-2 / FR-012 / FR-013 / MCP-3 / MCP-4) =
* **NEW `LuwiPress_Schema_Registry`** — generic JSON-LD schema type registry. Replaces the hard-coded FAQ/HowTo/Speakable branches in `LuwiPress_AEO::output_aeo_schema()` with a single context-aware emitter that supports BOTH posts AND taxonomy terms, and lets any module (or third-party plugin) register additional schema types via a filter. Eight built-in registrations: `faq` (FAQPage), `howto` (HowTo — Google-deprecated but still emitted for AI search), `speakable` (SpeakableSpecification — beta, opt-in), `localbusiness`, `service`, `course`, `review`, `aggregaterating`, plus `itemlist` (auto-generated for category archives — no save path needed). Adding a 9th type elsewhere is now config, not code: hook `luwipress_schema_registry_init`, call `$registry->register_type('event', [...])`.
* **NEW REST surface** — `POST /aeo/save-schema`, `GET /aeo/get-schema`, `DELETE /aeo/delete-schema`, `GET /aeo/schema-types`, `POST /aeo/schema-render`. Generic save/read/delete works on posts AND terms via `{object_type: post|term, object_id: N, schema_type: <slug>, data: {...}}`. Convenience aliases: `post_id`, `term_id` (forces object_type=term). The `schema-render` diagnostic fetches the rendered URL with cache-bypass headers and parses every `<script type="application/ld+json">` block — saves the round-trip through chrome-devtools-mcp for schema audits (Vendor-MCP-4).
* **FR-013 — Category FAQ Tab Support.** Closes the gap Tapadum surfaced after Session 128's frontend probe: Rank Math does NOT auto-parse `<h3>` Q&A blocks inside category descriptions into FAQPage schema. Now `aeo_save_faq` accepts `term_id` + `taxonomy` (default `product_cat`) and writes to term meta `_luwipress_faq`; the registry renders a proper FAQPage JSON-LD block on the category archive — same shape as the per-product version. Backwards-compatible: existing `product_id`-only callers keep working unchanged.
* **FR-2 — Category ItemList JSON-LD.** Every `product_cat` archive now auto-emits an `ItemList` schema listing up to 30 products in the term (filterable via `luwipress_itemlist_product_limit`). Cached per-term in `_luwipress_itemlist_cache` for 1 hour. Closes the gap where Rank Math's CollectionPage schema didn't include any product mention — search engines (and AI crawlers) can now read the category→product graph directly.
* **FR-1 — Schema family expansion (LocalBusiness / Service / Course / Review / AggregateRating).** All five schema.org types — still active for Google rich results — get first-class storage + emission via the generic `aeo_save_schema` endpoint. Operators submit a fully-formed schema.org array; the registry sanitizes recursively (URLs → `esc_url_raw`, strings → `wp_kses_post`, structure preserved) and emits at `wp_head` priority 6. Tapadum Part 5 deferral items (#3 Contact LocalBusiness, #4 Workshop Service, #5 Music Academy Course) are now unblocked from the platform side — operator data is the only remaining input.
* **FR-012 — x-default hreflang supplement (WPML-aware).** New `output_xdefault_supplement` callback registered at `wp_head` priority 99 — runs AFTER WPML, Polylang, and our own `output_hreflang_tags` have emitted. Detects the default-language URL for both singular posts (via `apply_filters('wpml_permalink', ...)`) and taxonomy archives (via `wpml_object_id` + brief `wpml_switch_language` context for term_link resolution), then emits a single `<link rel="alternate" hreflang="x-default" href="..." />` if missing. Works without WPML SEO addon. Option `luwipress_hreflang_xdefault` (default `auto`) — set to `off` to disable. Tapadum reported this on `new.tapadum.com/product-category/string-instruments/tar/` where the `wpml_hreflangs` filter didn't fire in their code path; the supplement runs unconditionally so the gap closes regardless of which WPML internal produced the rest of the hreflang block.
* **MCP-3 — WPML SEO settings get/set MCP tools.** New `wpml_seo_settings_get` (read) + `wpml_seo_settings_set` (partial update). Surfaces the SEO-relevant subset of `icl_sitepress_settings` (which WPML stores as a deeply nested array) plus standalone option keys WPML uses internally: `default_language`, `language_url_type` (1=subdir / 2=subdomain / 3=different-domain), `use_directory`, `hide_default_lang`, `sync_post_taxonomies`, `sync_post_date`, `sync_page_template`, `wpml_seo_settings`, `hreflang_show`. Partial update — only keys present in the payload are written. Closes the migration / config-audit gap operators previously had to handle via the WPML admin UI.
* **Backwards-compat preserved.** `LuwiPress_AEO::output_aeo_schema()` still emits FAQ/HowTo/Speakable on product pages at priority 5 (unchanged behavior); the registry runs at priority 6 and skips any schema type the legacy emitter has already output, so existing sites keep working even if a custom plugin disables the registry.

= 3.3.5 — KG dashboard: metric cards + Next Wins Review button fixes =
* **FIX Knowledge Graph stat cards reading 0 for Products / Enriched / Opportunities** even though the live data is healthy (e.g. 126 products / 38.9% enriched / 2,505 opportunity points on a representative store). Root cause: the two-phase progressive loader in `knowledge-graph.js` issues a second request with `sections=design_audit,order_analytics` once Phase A returns; the REST handler was rebuilding the full `summary` block on that request too, which — with no product nodes loaded — emitted zeros for every product-derived field. The JS merger then `Object.assign`ed those zeros over Phase A's real values. Fix: the handler now skips `build_summary()` entirely when no product-bearing sections were requested, so Phase B simply has no `summary` key and the Phase A values stand.
* **FIX trend baseline poisoning** — same bug also wrote today's snapshot to `luwipress_kg_summary_history` with zeros for `total_products`, `seo_coverage`, `enrichment_coverage`, and `opportunity_total` every time Phase B fired. That snapshot drives the "Last 7 days" delta line in the Store Health subtitle, which is why operators saw misleading numbers like "-89.7% SEO, -38.1% enriched, -2878 opportunity pts" on healthy stores. After this fix the first KG page load overwrites today's row with real values; older poisoned rows age out of the 30-day ring over the next week.
* **FIX Next Wins Review button no-op on server-side v2 candidates** — clicking Review on a v2 Action Queue card (the ones with the ⚡ primary-signal chip) did nothing. The click handler called `openDetailPanel('product', sc.entity_id)` but no function by that name exists in the bundle (the real one is `showDetailPanel(node)` and it takes a node object with a different shape). The else-branch fallback queried `[data-preset="high-opportunity"]` with a hyphen, but the dropdown button uses `data-preset="high_opportunity"` with an underscore, so it was also dead code. Fix: the handler now looks up the matching product in `data.nodes.products` and invokes `window.lpKg.showDetailPanel(...)` with a properly hydrated node, and the preset fallback selector is corrected to use the underscore form.

= 3.3.4 — Marketing-script interceptor: pre-consent pixel gating (Vendor-Tapadum FB-Pixel) =
* **NEW marketing-script interceptor** — closes the GDPR gap where pixel plugins (Meta pixel for WordPress, Meta for WooCommerce, PixelYourSite, raw GTM snippets, etc.) emit their `<script>` tags straight into `wp_head` / `wp_footer` without consent gating, fire `fbq("track", "PageView")` before the banner even renders, and leave operators glueing manual `<script type="text/plain">` wraps via Code Snippets. New `LuwiPress_Cookie_Consent` interceptor opens an output buffer at `wp_head` / `wp_footer` priority 0 (before any pixel plugin emits), closes it at priority 9999 (after everything), and regex-rewrites matching `<script>` tags to the `type="text/plain" data-luwipress-consent="marketing"` form so the existing `cookie-banner.js unblockScripts()` releases them on consent.
* Two pattern lists drive the rewrite (default + filterable via `luwipress_intercept_src_patterns` / `luwipress_intercept_inline_patterns`): **external loaders** (`connect.facebook.net`, `fbevents.js`, `googletagmanager.com/gtm.js`, `snap.licdn.com/li.lms-analytics`, `sc-static.net/scevent`, `analytics.tiktok.com/i18n/pixel`, `static.ads-twitter.com/uwt.js`) and **inline body matchers** (`fbq(`, `_paq.push(`, `gtm.start`, `snaptr(`, `lintrk(`, `ttq.load(`, `twq(`). Manual opt-out: add `data-lwp-no-intercept` to any `<script>` tag and the regex skips it. Already-wrapped tags (those with `data-luwipress-consent` already present) are skipped — no double-wrapping.
* **NEW Settings → Cookie Consent → Marketing scripts** toggle (default ON when Cookie Consent module enabled). Below the toggle the admin panel surfaces a **"Detected pixel / analytics plugins"** list (via existing `LuwiPress_Plugin_Detector` results — meta-pixel / meta-for-woocommerce / pixelyoursite / Site Kit / GTM4WP / MonsterInsights) so the operator sees exactly which scripts will be wrapped. A running counter (total intercepted + 3 most-recent samples) ships alongside so the operator can verify the interceptor is actually firing on their store.
* Audit state persisted as a single option (`luwipress_marketing_intercept_state`) — one DB write per request that has any hits, not per-hit. Daily counts (last 14 days), recent samples (last 40), and lifetime total.
* No JavaScript change required on the release side — `cookie-banner.js` already scans for `<script type="text/plain" data-luwipress-consent="…">` tags on consent fire and re-inserts them as live `<script>`s with the proper `src` (from `data-src`) or `textContent` (for inline snippets) preserved.
* **Closes the operator-side glue problem at the platform level** — Vendor-Tapadum (Özgür) flagged that the official Meta pixel for WordPress plugin (v5.1.0) doesn't respect WP Consent API and fires PageView pre-consent. Every LuwiPress operator running any pixel plugin had the same issue. With 3.3.4, the interceptor catches them automatically — no manual Code Snippets wrap needed.

= 3.3.3 — Next Wins single-row queue + Review button silent-fail fix + WPML product loader fallback =
* **NEW single-row Next Wins queue** — operator-requested "tek satırda olsun, yapıldıkça sola doğru yüklenmeye devam etsin". The Action Queue went from a 2-row grid to a horizontal flex queue: 12 candidates render into DOM, ~5 fit visually per row at standard admin widths (responsive: 4 at 1280px, 3 at 1024px, 2 at 768px, 1 at 540px). When an action resolves, the card now **collapses** (`flex-basis: 0 + opacity 0`) over 450ms — the queue automatically shifts left and the next pending candidate slides into the visible window. Continuous flow rather than a static board.
* **FIX Review button silent failures** — long-standing reports of "Review tıklayınca bir şey olmuyor" traced to an uncaught TypeError inside `showDetailPanel`'s product branch: opportunity-driven Next Wins candidates passed a fake product object with no `.seo` / `.aeo` / `.enrichment` subkeys, the very first health-calculation line crashed (`p.seo.has_title` against undefined), and the click handler died silently with nothing visible to the operator. Two-layer fix: (a) `showDetailPanel` now hydrates the four expected subobjects to empty maps when missing, so the renderer always has a working shape; (b) the click handler now wraps `c.onClick()` in try/catch + flashes a red error banner on the card + `console.warn`s the exception, so any future regression is visible at the source instead of a silent dead button.
* **FIX WPML product loader fallback** — Knowledge Graph product node count was reading 0 on sites where products exist in `wp_posts` but aren't registered in `wp_icl_translations` (typical failure mode when WPML's "Multilingual Content Setup → Custom Posts" never had the `product` post type enabled, or when icl_translations was partially purged during a WPML reinstall). The product loader's INNER JOIN was eliminating every row. Now: if the WPML query returns 0 but `wp_posts` has published products, the loader falls back to the non-WPML query and logs a diagnostic warning ("KG product loader: WPML JOIN returned 0 but wp_posts has N published products — check WPML Settings → Custom Posts"). KG dashboard stays usable while the operator repairs WPML separately.

= 3.3.2 — Next Wins gamification redesign: 8 compact cards + score float animation =
* **NEW compact Next Wins layout** — the Knowledge Graph Action Queue went from 3 large cards to **8 compact cards** in a denser `repeat(auto-fill, minmax(200px, 1fr))` grid so the gamification loop stays alive longer between refreshes. Operator-requested: "küçük kartlar + daha çok task verebiliriz." Card padding 12px → 9px, label 13px → 12px, description clamped to 2 lines, meta chips downsized to 9.5px uppercase tags, CTA button 12px → 11.5px and full-width inside the card so the click target stays generous even at the smaller scale.
* **NEW score-float animation** — every successful AI action (enrich, SEO meta, FAQ generate, HowTo generate, translation request) now pops a "+N pts" badge above the resolved card and floats it upward toward the Store Health hero, fading out at ~88px travel over 1.6s. Tier-based fixed payouts: high-priority cards = +15 pts, medium = +10 pts, low = +5 pts (matches the existing color stripe). Pure CSS keyframe animation, no library. Card also gains a `kg-aq-resolved` green-bordered dimmed state so the operator can see exactly which tasks they cleared during this session.
* **Pairs with 3.3.1's CRM Campaign Launcher** — same gamification loop pattern: every action gives a visible score signal, every resolved card gets demoted to show progress, every refresh re-surfaces the next 8 wins.

= 3.3.1 — CRM Campaign Launcher: one-click sales conversion from KG Customers view =
* **NEW `LuwiPress_CRM_Campaigns` module** — replaces the legacy "Export CSV → take to Mailchimp by hand" friction on the Knowledge Graph Customers view with a one-click in-platform email + WooCommerce coupon dispatcher. Click a segment node (One-Time, At-Risk, New, VIP, Loyal, Dormant) in the KG sidebar → primary "Send win-back to N customers →" CTA opens a modal. AI drafts a segment-tuned email (win-back / re-engagement / onboarding / VIP perk — four new prompt templates with distinct rhetoric tuned per cohort), the modal suggests segment-aware coupon defaults (One-Time: 15%/30d, At-Risk: 20%/14d, VIP: free shipping/90d, etc.), operator reviews + optionally edits the subject / body / coupon settings, one click fires `wp_mail()` per recipient + creates a single shared `WC_Coupon` with `usage_limit_per_user = 1` so each customer can only burn the perk once. Hard cap of 200 recipients per send (inbox-reputation safety + AI budget control). Dry-run mode logs the planned send without firing emails.
* **NEW conversion tracking** — every `woocommerce_order_status_completed` hook checks if the buyer was emailed via a campaign in the last 30 days; if so, marks the send as converted + records a `campaign_converted` `kg_event` so the dashboard can show sent → opened → purchased funnel ratios per cohort. Per-campaign revenue attribution stored in `wp_luwipress_campaigns.conversion_revenue`.
* **NEW REST surface** — `GET /crm/campaign/preview?segment=X` (recipient count + suggested coupon + AI-drafted envelope), `POST /crm/campaign/send` (executes the dispatch + creates coupon + records sends), `GET /crm/campaign/history` (list past campaigns with conversion stats).
* **NEW two DB tables** — `wp_luwipress_campaigns` (one row per send batch: segment, coupon code/%, recipient/sent/failed/conversion/revenue counts) and `wp_luwipress_campaign_sends` (one row per recipient with sent_at + converted_at + order_id for attribution).
* **Knowledge Graph Refresh → CRM re-classification chain** — Shift+Refresh on KG now also fires `POST /crm/refresh-segments` before the graph fetch so a stale `luwipress_crm_segment_counts` cache (only refilled by weekly cron) doesn't keep showing "58 customers" on a 200-customer store. Plain Refresh is unchanged (cache-aware reload).
* **Email shell** — AI body is wrapped in a minimal branded HTML shell (store name eyebrow, white body, dashed coupon callout block with code + perk-line); plain enough that Gmail/Outlook don't strip it, expressive enough to look intentional. Token substitution supports `{{first_name}}` (falls back to "there"), `{{store_name}}`, `{{coupon_code}}`, `{{coupon_url}}` (cart URL with coupon pre-applied).
* **Safety invariants** — per-recipient `usage_limit_per_user = 1` always; admin token required for both preview + send; dispatch logs every send to `wp_luwipress_logs` for audit trail; on AI failure, falls back to a generic localized envelope (never sends "undefined").
* **FIX Clarity Consent v2 bridge** (Tapadum follow-up — 4 overlapping bugs the 3.3.0 ship missed): cookie body is `base64(JSON(...))` per `cookie-banner.js writeCookie()` but the replay path skipped the `atob()` step, so every page load silently threw `SyntaxError`. Choices live under the `c` field of the envelope but the bridge read `detail.analytics` top-level. Microsoft Clarity's `consentv2` API uses CamelCase keys (`analytics_Storage`, `ad_Storage` — capital S) not the Google Consent Mode lowercase variant we'd shipped. Adapter now normalizes both event-and-envelope shapes, sends the correct CamelCase payload, and emits a `[LuwiPress Clarity Bridge]` console channel (auto-enabled when `WP_DEBUG`, or via `luwipress_clarity_bridge_debug` filter) so operators can confirm the bridge is firing without reverse-engineering the call.
* **UX `lp-cta` button family** — new design-system-aligned admin button family (`.lp-cta`, `.lp-cta--primary`, `.lp-cta--secondary`, `.lp-cta-row`, `.lp-cta-status`) replaces ad-hoc WP `.button` / `.button-primary` usage inside LuwiPress admin pages. 32px height, 12px padding, 6px radius, 14px icons at .85 opacity, solid primary (no gradient/shadow lift), neutral-text secondary that shifts to primary on hover. First adopter: Cookie Consent → AI Policy tab CTAs. Drop-in for any future admin page.

= 3.3.0 — WordPress 7.0 Connectors + Bot Shield comment review + Theme Bridge token-auth fix + Clarity Consent v2 bridge =
* **NEW Microsoft Clarity Consent v2 bridge** (Tapadum vendor request): Cookie Consent gains a `clarity_consent_v2_enabled` toggle that, when on, forwards every visitor consent decision to Microsoft Clarity's native `clarity('consentv2', {…})` API. Maps LuwiPress's analytics category to `analytics_storage` and the marketing category to the three ad-side signals (`ad_storage`, `ad_user_data`, `ad_personalization`) so Clarity respects GDPR/ePrivacy toggles in lock-step with the banner — no extra glue plugin needed. Bridge is a small inline JS adapter enqueued after the consent banner; replays the stored cookie on every page load so visitors with an existing consent record never get reset. Settings → Cookie Consent → "Microsoft Clarity" row exposes the toggle.
* **NEW Bot Shield comment review layer**: a 7th defence wing wired into the existing Bot Shield. Bot Shield can now intercept every comment submission (`preprocess_comment` p1) and either silently reject, hold-for-moderation, mark-as-spam, or pass-through based on a multi-signal score. Signals: anonymous + author-link present + bot-shaped author name (digits / random consonant runs / known scraper handles), comment body link-density (configurable, default >2 links = spam), URL-only body, repeated content within the rate-limit window per IP, IP already on the Bot Shield block list (instant spam), language mismatch heuristic (latin-only body on non-latin posts), known spam tokens (configurable allow/deny lists). Logged-in users + operators on the allowlist bypass. Mode is operator-selectable: `off` / `moderate` (default, hold for manual review) / `spam` (route to spam queue) / `reject` (silent 403). Counters surface on Bot Shield → Stats. New REST surface: `GET /bot-shield/comments/recent`, `POST /bot-shield/comments/test`, plus the existing `/settings` patch adds `comments` keys. WebMCP companion exposes 3 paired MCP tools (`bot_shield_comments_recent`, `bot_shield_comments_test`, `bot_shield_settings` patch surface). Default state ships ON in `moderate` mode — non-destructive, fully reversible from Bot Shield admin.
* **FIX Theme Bridge token-auth capability gate** (Vendor-FR-008): `LuwiPress_Theme_Bridge::run_tool()` no longer locks every tool behind a 403 for valid MCP/token callers. Token-authenticated REST requests pass the route-level permission check but never set a current WP user (`check_token()` is stateless by design), so the per-tool `current_user_can($cap)` gate was falsely returning false and blocking 25 maintenance/audit tools (`kit_css_health`, `seo_triangle_health`, `wpml_structure_sync`, `canonical_audit`, `hreflang_reciprocity_audit`, `redirect_chain_detector`, `broken_internal_links`, `sitemap_indexation_parity`, `page_speed_signals`, and 16 others) when called via WebMCP. Bridge now treats a valid token as admin-equivalent — token holders are operators by definition, same trust level as a logged-in admin. New helper `LuwiPress_Permission::is_token_authenticated()` reads Authorization / X-LuwiPress-Token from `$_SERVER` and validates against the stored option with `hash_equals()`. Restores DNS-swap pre-flight diagnostic surface for MCP-driven operations on Tapadum-class deployments.
* **NEW WordPress 7.0 Connectors bridge** (`LuwiPress_Connectors`): every AI provider (OpenAI, Anthropic, Google) now reads its API key from WP 7.0's native Settings → Connectors layer first, falling back to the legacy `luwipress_{provider}_api_key` option only when Connectors is absent or unconfigured. Detection uses a multi-probe (`wp_ai_client_get_provider` function, `WP_AI_Client` class, WP version >= 7.0) plus an operator override filter `luwipress_wp7_connectors_active` so early-adopter / beta builds can be toggled manually. OpenAI-compatible vendors (DeepSeek, Kimi, Groq, Together, self-hosted Ollama/vLLM) stay in the existing LuwiPress option layer — they're out of Connectors scope.
* **NEW one-click migration UI**: when WP 7.0 Connectors is detected and the site still holds legacy LuwiPress API keys, the "AI API Keys" settings tab shows a "Move legacy keys into Connectors…" button. The modal previews exactly which keys move where (per-provider checklist, never silently overwrites a Connectors-side key), and on confirmation calls two new REST routes — `GET /luwipress/v1/connectors/migrate-preview` and `POST /luwipress/v1/connectors/migrate-execute` — that register the key natively and only then delete the LuwiPress option, with audit log entries per provider.
* **NEW Settings → AI API Keys redesign**: when WP 7.0 Connectors is active, native-scope provider key inputs (OpenAI/Anthropic/Google) are replaced with read-only "Managed by Connectors" / "Stored in legacy LuwiPress option" status pills + a link to native Connectors UI. The model picker, per-workflow provider override, and daily-budget cap remain — these are LuwiPress-specific moats Connectors doesn't replicate.
* **NEW Dashboard status pill**: when an AI key resolves from native Connectors, the status ribbon shows `Connectors · <Provider> (<Model>)` in green and links to the native Connectors UI instead of LuwiPress Settings. When Connectors is detected but unconfigured, the pill reads `No AI key (Connectors)` and points there too.
* **NEW Abilities API filter** `luwipress_abilities_for_wp7`: operators or third-party plugins can subset the WebMCP tool registry before it's mirrored into WP 7.0's Abilities API, letting them limit native-MCP exposure while keeping headless WebMCP HTTP access. Abilities annotation block now emits both LuwiPress short-form keys (`readonly/destructive/idempotent/open_world`) and MCP 2025-03-26 canonical spellings (`readOnlyHint/destructiveHint/idempotentHint/openWorldHint`) so consumers using either name see the hints.
* **NEW WebMCP page info-box**: when WP 7.0 Abilities API is detected, the WebMCP admin page surfaces a "Dual-registry" panel — operator sees at a glance whether Automattic's official MCP Adapter is active, and that LuwiPress tools are mirrored through both surfaces. WebMCP HTTP endpoint stays available for headless/agentic clients.
* **FIX latent Google option-name mismatch**: settings UI persisted keys under `luwipress_google_ai_api_key` while the Google provider class historically read `luwipress_google_api_key`. The new Connectors helper canonicalises on the UI name and walks aliases for legacy reads — Google Gemini provider now works without the operator having to manually duplicate the option.
* **FIX Settings save logic**: API-key form fields now use `isset()` guards so when WP 7.0 hides the input rows (Connectors-managed mode), an empty POST submit no longer wipes the legacy keys.
* **NEW missing pill variants**: `pill-success`, `pill-warning`, `pill-info`, `pill-danger` CSS rules added (previously referenced across Settings / Bot Defense / Cookies / Theme pages without backing styles).
* **COMPAT**: `Tested up to: 7.0`. `Requires at least: 5.6` unchanged — WP 7.0 is opportunistic, not required. WebMCP companion bumped to 1.0.26 with paired `Tested up to: 7.0`.

= 3.2.10 — BM25 index extended beyond products (IMP-002) =
* **NEW multi-post-type BM25 index** (IMP-002): the search index that powers `search_products`, the storefront chat widget's RAG layer, and `search_reindex` can now ingest posts and pages in addition to products. Default scope stays `['product']` for backwards compatibility; operators opt-in by setting the `luwipress_search_index_post_types` option (or filter), or by passing `post_types: ["product","post","page"]` to the new `search_reindex` MCP argument. Result rows now carry a `post_type` field so chat callers can distinguish product hits (with price + stock + SKU) from blog/page hits (price/stock/sku empty, description = trimmed body). Closes a long-standing Tapadum request — "how to tune a ney" style questions can now be answered from the blog instead of returning a product mismatch.
* Field extraction adapts per post type: products keep their `product_cat` + `product_tag` + WC attributes; posts/pages use the native `category` + `post_tag` taxonomies. FAQ + title + content + excerpt apply uniformly.
* `index_product` (still named for backwards compatibility) and `reindex_all` honour the configured set; auto-indexing hooks subscribe to the appropriate `save_post_<type>` actions.

= 3.2.9 — Translation FAQ scope + Customer Chat session/history surface =
* **NEW translation pipeline scope** (ISSUE-032): `translation_request` now translates `_luwipress_faq` (Q&A round-trip) and tightens `rank_math_focus_keyword` enforcement. The AI prompt is extended with the source FAQ block (Q + A per entry) and a matching-length response schema so the writer can persist the translated set straight to the target post's `_luwipress_faq` meta. Both WPML and Polylang sibling writers honour the new field. Focus-keyword prompt rule now explicitly forbids leaving source-language tokens (e.g. "silent" → must become "silencieux"). Closes the multilingual-AEO gap where FR/IT/ES sibling products rendered empty FAQ tabs + empty FAQPage JSON-LD even when the AI had produced a perfectly good translation set. New filter `luwipress_prompt_translation` already exists for further customisation.
* **NEW Customer Chat session/history endpoints** (FR-003): two admin-only REST routes expose the existing chat conversation tables for audit + analysis:
  * `GET /chat/sessions` — paginated list with filters (status, escalated_only, customer_email partial, since-datetime). Each row carries session_id, customer_*, status, escalated_to, page_url, ip_address, timestamps, and message_count.
  * `GET /chat/messages/search` — plain-LIKE content search with snippet centring (≤240 chars around match) + role + since filters. For chat-tone reviews, pain-point analysis, brand-voice audits across FR/IT/ES.
* **NEW CRM segmentation rules documentation** — repo-root `LUWIPRESS-CRM-SEGMENTATION.md` documents the eight-bucket decision tree (VIP / Loyal / New / Active / At Risk / Dormant / Lost / One Time), default thresholds, and the common coverage-gap causes (imports without orders, guest checkouts, trashed orders). Closes BUG-003 transparency request.

= 3.2.8 — Plugin Detector: robust Easy WP SMTP 2.x detection =
* `detect_email()` no longer relies on a single constant (`EASY_WP_SMTP_VERSION`) to identify Easy WP SMTP. Adds OR-conditions for the SendLayer/Awesome Motive rebranded version: bootstrap function `easy_wp_smtp()`, namespaced classes `EasyWPSMTP\Plugin` and `EasyWPSMTP\Core`. Fixes the dashboard "No SMTP" false-positive when Easy WP SMTP 2.x is installed and active but the version constant defers to autoloader.
* Version reported falls back to `'unknown'` instead of throwing when only the class is detectable.

= 3.2.7 — Bot Defense: 3-tier protection + animated bulk sweep =
* **3 protection tiers** now enforced automatically in `score_user()` — a user matching any tier short-circuits to score=0 and is never deletable:
    - Tier 1: **Has WooCommerce orders** (pre-existing, hardened).
    - Tier 2 (NEW): **Email verified** — detected via `_wc_email_verified` / `wc_email_verified` / `email_verified` / `is_email_verified` / `_email_verified` user meta, or the `luwipress_user_email_verified` filter for custom integrations.
    - Tier 3 (NEW): **Realistic first + last name** — both populated, 2–40 chars, ≥1 vowel, no digits, no half-repetition ("asdasd"), no keyboard-mash sequences ("asdf", "qwer", "zxcv"), no 3+ char repeats ("aaaa"), and not equal to user_login. Unicode-aware (Turkish, accented chars supported). Override via `luwipress_user_has_realistic_name` filter.
* New REST endpoint `POST /luwipress/v1/bot-accounts/delete-by-filter` — cursor-paginated chunked bulk delete across every account at a score threshold. Args: `min_score` (≥40, required), `confirm`, `limit` (default 10, max 50), `after_user_id` (cursor). Returns `{deleted, skipped:[{id,reason}], processed, total_remaining, next_cursor, complete}`.
* New "Sweep matching" admin button on Bot Defense → Accounts. Animated minimal sweep: each row pulses red and fades out on delete, or goes green with a `✓ has orders` / `✓ email verified` / `✓ realistic name` pill on keep. Live counters (Processed / Deleted / Kept) + progress bar + Pause/Resume + Stop. Cursor-by-id pagination so deletes never cause row-skipping and protected rows don't loop forever.
* Settings: new non-toggleable invariants `protect_email_verified` and `protect_realistic_name` (default true).

= 3.2.6 — Dark mode form label visibility fix =
* Fixed Settings page form labels (Custom System Prompt, Target Word Count, etc.) being invisible in dark mode due to insufficient contrast
* Added `@media (prefers-color-scheme: dark)` overrides for `.form-table th` and description text

= 3.2.5 — Full-user-table bot scan + two new signals =
* Bot Account Cleaner scan now iterates through **every** eligible user (was capped at the newest 500). Each REST call processes batches up to a 25-second time budget and returns a `next_offset` cursor; the admin UI re-fires until `complete:true`, showing live "Scanned X / Y" progress.
* New scoring signals:
  - `numeric_only_email` (+12) — email local part that's entirely digits (e.g. `382947@gmail.com`)
  - `numeric_only_username` (+8) — username that's entirely digits
* Dashboard ribbon: added a **Security** pill that surfaces Wordfence (or invites installing it). `LuwiPress_Plugin_Detector::detect_security()` was returning data but `get_environment()` had never exposed it — fixed.

= 3.2.4 — Settings consolidation + leaner Plugin Detector =
* New extension points `luwipress_settings_render_tab_nav` + `_tab_content` let companion plugins register tabs inside the core Settings page (used by the Agentic companion to retire its standalone "Agentic Settings" submenu)
* Plugin Detector no longer probes for FluentCRM / Mailchimp for WooCommerce / Klaviyo / LiveChat / Tawk.to — LuwiPress is its own content-analysis + segmentation surface; operators export cohorts via the segment CSV
* Removed redundant Wordfence delegation banner from the Bot Defense page (still shown on the Dashboard and Plugin Detection)
* Bot Defense Accounts table: signal column compacted to top-3 inline chips with a "+N more" overflow tooltip

= 3.2.3 — Bot Defense unified UI + compound bot-detection signal =
* Merged Bot Accounts + Bot Shield into a single **Bot Defense** admin entry with Overview / Accounts / Shield tabs
* Moved all bot-related settings into the main **Settings → Bot** tab for a single configuration surface
* Added an indeterminate progress bar + animated "working" pill during account scans (matches `--lp-primary` design tokens)
* Stat-card grid restyled as compact horizontal mini-tiles (column-shaped, 22px value + 11px uppercase label)
* New **compound zero-activity bonus** (+15) on the bot scorer: when stale + never-logged-in + no-comments + no-real-name + default-display-name all fire together, score climbs by 15 — surfaces the typical "user123@gmail.com" passive-bot signature that used to land at 48 and slip under the threshold
* Default account-flag threshold lowered from 60 → 50 for fresh installs (existing operator config preserved)
* Legacy menu slugs (`luwipress-bot-accounts`, `luwipress-bot-shield`) kept as hidden routes — old bookmarks and MCP integrations still resolve

= 3.2.2 — Plugin Detector coverage: RexTheme PFM + Easy WP SMTP =
* **Plugin Detector — `detect_product_feed`**: detects RexTheme Product Feed Manager (Free + PRO) via `WPPFM_VERSION` / `WPPFM_PRO_VERSION` constants + `Rex_Product_Feed` class fallback. Returns `rextheme-pfm` or `rextheme-pfm-pro` slug with feature flags (google_shopping, facebook, bing PRO-only, custom_templates, multi_channel PRO-only). Closes a Tapadum feedback gap (IMP-008) where `site_config.plugins.product_feed` reported `none` on installs with RexTheme PFM active.
* **Plugin Detector — `detect_email`**: detects Easy WP SMTP 2.x (`EASY_WP_SMTP_VERSION` constant since the 2.0 rewrite) + 1.x legacy (`SWPSMTP_VERSION_NUM`). Reads `easy_wp_smtp` option to surface mailer + from-address. Closes Tapadum feedback gap (IMP-004) where `email.method` reported `php_mail` on installs running Easy WP SMTP 2.14.0.

= 3.2.1 — Wordfence-aware Bot Shield + Security plugin detection =
* **NEW (`LuwiPress_Plugin_Detector::detect_security`)**: detects Wordfence (free + premium via `WORDFENCE_VERSION` + `WORDFENCE_PREMIUM` constants). Returns `{plugin, version, is_premium, delegate_layers}` shape so other modules can adapt.
* **NEW (`LuwiPress_Bot_Shield::delegated_layers`)**: when Wordfence is active, Bot Shield automatically delegates three layers to avoid double-blocking the same request — `ua_blocklist`, `rate_limit`, `honeypot`. LuwiPress still handles cookie consent, REST user enumeration block, XML-RPC kill, allowlist, manual blocks, and dry-run testing (Wordfence Free does not provide these). Operators can override via `apply_filters('luwipress_bot_shield_delegated_layers', [], $detect)` if they want LuwiPress to keep running every layer regardless.
* **Admin UI**: Bot Shield page now shows a green "Wordfence detected" banner with the active version + the list of skipped layers. Operator immediately understands which defence handles what.
* **MCP / REST**: `bot_shield_stats` payload now includes `friendly_security` (the detector result) + `delegated_layers` arrays — no API change needed; clients automatically see whether complementary mode is on.

= 3.2.0 — Cookie Consent + Bot Shield =
* **NEW MODULE `LuwiPress_Cookie_Consent`** — GDPR/ePrivacy compliant cookie banner + preferences modal + consent log + AI policy generator + script-blocking helper. Three operating modes (info, opt-in EU default, opt-out US default) × four fixed categories (necessary, analytics, marketing, personalization). Banner is vanilla JS (no jQuery), bottom/top/corner positions, light/dark/auto theme, multi-language via WPML/Polylang. Third-party tags are blocked by wrapping in `<script type="text/plain" data-luwipress-consent="analytics">…</script>` — runtime rewrites `type` to `text/javascript` after consent. Consent log stores SHA-256-salted IP hash (raw IP never persisted) + UA + choices + country (CF header) + language + ISO timestamp. Public REST endpoint `/cookies/consent` is throttled to 5 writes/min per IP. AI policy generator composes a site-specific paragraph by passing the LuwiPress_Plugin_Detector's detected analytics/marketing/Meta tags as context to the AI engine.
* **NEW MODULE `LuwiPress_Bot_Shield`** — Six-layer front-edge bot filter hooked at `init` priority 1. (1) UA blocklist (default 22 known scrapers: AhrefsBot/SemrushBot/MJ12bot/PetalBot/sqlmap/nmap/WPScan/…), (2) per-IP rate limiter on sensitive paths (`/wp-login.php`, `/xmlrpc.php`, `/wp-json/wp/v2/users`) with configurable threshold + window, (3) REST user-enumeration block (refuses `/wp-json/wp/v2/users` + `?author=N` for non-logged-in callers), (4) XML-RPC kill switch + pingback-multicall removal, (5) honeypot trap (auto-bans any IP probing `/wp-admin/install.php`, `/.env`, etc. for 24h), (6) Googlebot/Bingbot reverse-DNS verification (defeats UA spoofing). Hard guards: logged-in admins + RFC1918 private IP space are never auto-blocked. Active blocks live in `wp_luwipress_bot_blocks` with UNIQUE(ip) + indexed on (reason, expires_at). Dry-run REST endpoint `/bot-shield/test` previews any (IP, UA, path) verdict without firing the shield — use to tune thresholds without lockout risk.
* **REST surfaces** — `/luwipress/v1/cookies/{config (public), consent (public+throttled), log, stats, settings, policy-text}` and `/luwipress/v1/bot-shield/{stats, blocks, block, unblock, allowlist, settings, test}`.
* **WebMCP** (companion 1.0.21+): 12 new tools — 5 cookie consent (`cookie_consent_settings_get/_set`, `cookie_consent_stats`, `cookie_consent_log`, `cookie_consent_policy_generate`) + 7 bot shield (`bot_shield_stats`, `bot_shield_settings_get/_set`, `bot_shield_blocks_list`, `bot_shield_block`, `bot_shield_unblock`, `bot_shield_test`). Destructive ops (`bot_shield_block`) carry `destructiveHint: true`.
* **Admin UI** — two new submenus under LuwiPress: "Cookie Consent" (Settings / Log / AI Policy tabs with category accept-rate stat row) and "Bot Shield" (Status / Blocks / Settings tabs with active-block table + manual block form + quick-test dry-run form + allowlist editor).
* **Databases** — new `wp_luwipress_cookie_consent_log` (ip_hash, choices JSON, source, country, language, timestamp) + new `wp_luwipress_bot_blocks` (ip UNIQUE, reason, hit_count, ua_sample, path_sample, first/last_seen, expires_at, source). Both created on `activate()` + idempotent upgrade path via `luwipress_db_version` migration.
* **Both modules ship DISABLED by default** — operator must explicitly enable in admin to avoid disrupting existing installs.

= 3.1.60 — Bot Account Cleaner =
* **NEW MODULE `LuwiPress_Bot_Account_Cleaner`**: score-based detection (0–100) and safe bulk deletion of fake / spam user accounts. Signals: disposable / temp-mail domain (200+ domain blocklist), `+` alias on common providers, high-entropy random local part, numeric-tail patterns, 0 WooCommerce orders + 0 comments + never-logged-in + stale registration age, missing real name / default display_name, burst-registration windows (10+ accounts in same UTC hour).
* **Safety invariants (hard-coded, non-toggleable)**: `administrator`, `editor`, `shop_manager`, `author`, `contributor` are NEVER scored. Any WooCommerce customer with at least one order is auto-excluded. Whitelisted users are permanently skipped. `wp_delete_user` reassigns content to user ID 1.
* **Dry-run by default**: bulk delete is preview-only unless caller explicitly passes `confirm=true`. Server re-scores each user before deletion to enforce the protection invariants even if the caller tries to bypass them.
* **REST surface** (`/luwipress/v1/bot-accounts/*`): `scan`, `list`, `score/{id}`, `delete`, `whitelist`, `stats`, `settings` (GET + POST partial-update). All routes admin-token gated via `LuwiPress_Permission::check_token_or_admin`.
* **Admin UI**: new "Bot Accounts" submenu under LuwiPress with Review tab (5 stat cards: high / medium / low / deleted / whitelisted + scan button + dry-run/CONFIRM delete row + flagged table with per-row whitelist) and Settings tab (threshold / min_age_days / scan_batch_size + safety-invariant explainer).
* **WebMCP** (companion 1.0.20+): 7 new tools — `bot_account_scan`, `bot_account_list`, `bot_account_score`, `bot_account_delete`, `bot_account_whitelist`, `bot_account_stats`, `bot_account_settings_set`. `bot_account_delete` carries `destructiveHint: true` so MCP clients can warn before invocation.
* **Database**: new `wp_luwipress_bot_account_scores` table created on activate + idempotent upgrade path (`luwipress_db_version` migration). Stores user_id (unique), score, signals JSON, status (scored/whitelisted/deleted), scanned_at. Indexed on `(score, status)` for fast threshold lookups.
* **Extensibility**: `apply_filters('luwipress_bot_account_score', $score, $signals, $wp_user)` to adjust or augment scoring; `apply_filters('luwipress_bot_disposable_domains', $list)` to extend the temp-mail blocklist.

= 3.1.59 — Slug resolver: skip when path is already a product_cat archive + loop-guard query-string fix =
* **FIX (`LuwiPress_Slug_Resolver::maybe_redirect`)**: The 3.1.58 nested-leaf-fallback was firing on URLs that were already `product_cat` archives — e.g. `/product-category/accessories/` had leaf `accessories`, which was in the map and resolved back to the same URL. The loop-guard at the end didn't catch it because `home_url($req)` retained any cache-buster query string while `$target` did not, so the equality check missed. Result: every `/product-category/...` URL (and the WPML-translated equivalents `categorie-produit`, `categoria-prodotto`, `categoria-producto`) entered a 5-hop redirect chain until the browser gave up. Now the resolver short-circuits with `skip-prodcat-base` trace as soon as it sees the path begins with a known product_cat base.
* **NEW helper `get_product_cat_bases()`**: introspects `wc_get_permalink_structure()` for the default base, then probes a sample populated term in every WPML/Polylang language to discover translated bases automatically. Cached in transient `luwipress_slug_resolver_prodcat_bases_v1`, busted by the same hooks as the map. Extendable via `apply_filters('luwipress_slug_resolver_prodcat_bases', $bases)`.
* **FIX loop guard**: now compares URL PATHS only (strips query strings via `wp_parse_url($x, PHP_URL_PATH)`) so cache-buster params (`?cb=…`, `?_ga=…`, marketplace UTM tags) can't unmask the guard.
* **Diagnostic**: new trace header value `X-LWP-SR: skip-prodcat-base:<seg>` appears whenever the resolver bails out for this reason.

= 3.1.58 — Slug resolver: nested-leaf-fallback for legacy blog URLs =

= 3.1.55 — AEO target_language auto-detection from WPML/Polylang post language =
* **FIX (`LuwiPress_AEO::dispatch_aeo_generation`)**: AEO FAQ/HowTo generation now auto-detects the target post's WPML/Polylang language and forwards it to the prompt builder as `target_language`. Previous behavior fell back to the `luwipress_target_language` option (typically "English") which caused FAQ generation on translated post_ids (FR/IT/ES siblings) to produce English content even though the post itself was in another language. Discovered during 2026-05-16 audit when `aeo_generate_faq` was run on 24 translated products and produced English Q&A across the board. Operators can still pass an explicit `target_language` in options to override.
* Map of supported language codes: en/fr/it/es/de/pt/tr/ar/ja/zh/nl/ru — extend `$lang_names` if more are needed.

= 3.1.54 — Translation Sync Audit — cross-language gap detector + auto-fix orchestrator =
* **NEW (`LuwiPress_Translation_Sync` module)**: Unified orchestrator for four cross-language sync issues — language drift (target body still in source language), outdated translations (source edited after translating), structural gaps (translation missing sections source has), and schema parity (FAQ/HowTo/Speakable on source but not on translation). Each gap surfaces as a uniform "finding" with severity, gap_summary, and a ready-to-execute fix_action.
* **NEW (`GET /translation/sync-audit`)**: Run one or all detect-routines. Returns ranked findings with `finding_id` strings. Caps to `limit` (default 200, max 500).
* **NEW (`POST /translation/sync-fix`)**: Dispatch fixes for an array of finding_ids. Server re-resolves the finding (does not trust client-provided fix_args) and routes to force-retranslate / sync-structure / copy-schema as appropriate. Async by default.
* **NEW (`GET/POST /translation/sync-settings`)**: Drift threshold (0..1, default 0.45), hourly sweep toggle, autofix toggle, next sweep time, last audit summary.
* **NEW (KG Action Queue integration)**: Sync gaps surface as KG candidates via the `luwipress_kg_action_queue_external_candidates` filter — operators see "5 drift in FR" / "12 structural gaps in IT" alongside core enrichment opportunities. Uses 1h cached last-audit to avoid re-scanning on every dashboard load.
* **NEW (hourly wp_cron sweep)**: Opt-in via setting. Runs audit, optionally auto-fixes high-severity findings (capped at 20/tick), logs a summary to the activity log.
* **NEW (Admin UI)**: "Translation Sync Audit" collapsible panel on Translation Manager. Shows last-audit summary pills, threshold/sweep controls, "Run audit now" button, per-finding Fix buttons. Drops in cleanly alongside existing Quality Audit + Outdated panels.

= 3.1.53 — FAQPage JSON-LD double-emit fix =
* **FIX (AEO schema)**: Product pages were emitting the FAQPage JSON-LD `<script>` block twice — once in `<head>` (the canonical AEO module emitter) and again before `</body>` (a legacy footer-hooked duplicate in the content tab module). Google saw two identical FAQPage records per product, which counts as a schema redundancy warning in Rich Results Test. Removed the wp_footer p20 duplicate; `LuwiPress_AEO::output_aeo_schema` on wp_head p5 remains the single canonical emitter. FAQ tab UI (`woocommerce_product_tabs` filter) is unaffected — only the schema script was duplicated.

= 3.1.52 — Chat session bootstrap no longer consumes rate-limit budget =
* **FIX (Customer Chat rate limit)**: `check_chat_permission` was incrementing the per-IP hourly counter on every endpoint hit, including the `GET /chat/session/{id}` bootstrap that fires on every page load. A normal browsing session blew through the default 30/hour cap within a dozen page views, returning HTTP 429 for the chat widget sitewide. Restrict the increment to POST/PUT/DELETE (actual writes — messages and escalation). GET bootstraps now pass freely.

= 3.1.51 — Customer chat widget icon-only launcher + soft pulse =
* **CHANGE (Customer Chat launcher)**: The floating chat launcher is now a 56×56 circular icon button (48×48 on mobile) instead of a pill with a "Chat" label. The text label was overlapping storefront content on narrow viewports and read as a hard CTA where a discreet launcher fits better. Icon-only matches modern e-commerce chat patterns (Intercom/Crisp/HubSpot) and recovers ~80px of safe-area on mobile product pages.
* **CHANGE (Customer Chat pulse)**: New low-frequency 3.6s `lp-chat-pulse` keyframe halo on the closed-state launcher to invite engagement without distracting from page content. Respects `prefers-reduced-motion` — users with motion sensitivity disabled see a static button. Pulse pauses on hover.
* **CHANGE (Critical CSS)**: The inline critical CSS injected by `luwipress-chat.js` (the optimizer-resistant fallback for the launcher) was kept in sync with the stylesheet so optimizer plugins that defer the main CSS still render the new circular shape immediately.

= 3.1.50 — Ghost-Elementor translation routing fix + Theme admin stat-card layout =
* **FIX (Ghost-Elementor translation routing)**: posts whose `post_content` carries the actual editorial body but whose `_elementor_data` meta only holds nominal kit-overlay widgets (legacy Hello Elementor migration residue, hero-only Elementor decorations, etc.) were being routed through the Elementor chunked translator. That pipeline only rewrites Elementor widget fields, leaving the `post_content` body untouched — so translated posts kept their source-language paragraphs even after a successful "translation completed" cron event. New ghost-Elementor guard in `request_translation()` detects the pattern (≥200 chars stripped post_content + < 5 widget instances in `_elementor_data`) and forces the standard translation path so the body actually gets rewritten. Pairs with the 3.1.49 drift detector — drift sweeps now produce visible content changes.
* **FIX (Theme admin Status tab stat-cards layout)**: 7 stat cards were wrapping the last (Kit CSS Headroom) onto a second row on standard 1366-1500px admin content widths because `.luwipress-stats-row` minmax was 200px (default). Scoped override on `.luwipress-theme-page .luwipress-stats-row` drops min to 145px + tightens card padding so all 7 fit on one row down to ~1100px content width. Auto-fit gracefully wraps below.

= 3.1.49 — Translation Language Drift sweep + admin design system unification =
* **NEW (`/translation/language-drift`)**: Detects translated posts whose body content is still in the source language — the silent failure mode that makes existence-based coverage report 100% even when blogs are broken English. Scores each body via 10-language stop-word ratio (EN/ES/IT/FR/DE/TR/NL/PT/RU/AR); flags posts whose target-language score falls below 0.45. Walks Elementor `_elementor_data` widgets so Elementor pages get scored on actual content, not the empty `post_content`.
* **NEW (`/translation/force-retranslate`)**: Bypass `/translation/missing-all` gating with an explicit `post_ids` whitelist + `languages` array. Resolves translation IDs back to default-language source via WPML trid lookup; clears the Elementor "already-translated" guard meta (`_luwipress_elementor_translated`) so the AI pipeline actually re-runs instead of skipping the post. Async wp_cron path kicks in automatically when work units > 5.
* **NEW (cron handler `luwipress_force_retranslate_single`)**: Per (post_id, lang) worker fired by force-retranslate. Logs success/error per dispatch and updates progress meta.
* **NEW (Translation Manager admin panel)**: New "Translation Quality Audit" red-accent card surfaces drifted posts grouped by source, with per-language score chips (link to live translated URL, score percentage on hover) + per-row "Re-translate" button + sweep-all toolbar. Async dispatch keeps the dashboard responsive.
* **NEW (Theme `theme-page.php` redesign)**: Refactored to match LuwiPress design system — `lp-header` + `luwipress-stat-card` semantic accent borders + `lp-pill` badges + `--lp-*` tokens throughout. All hardcoded hex removed; dark-mode-ready.
* **NEW (Marketplaces companion redesign)**: Full design-system pass on `marketplace-settings-page.php` — hero stat row (live channels / configured / untouched / adapters), `lp-pill` semantic status badges (Live/Ready/Off), `--lp-*` tokens replacing all hex. Brand identity dot colors (Amazon orange, eBay red, Trendyol orange) preserved on purpose.
* **NEW (Open Claw companion redesign)**: `lp-header` chrome on the chat page with provider+model pill, dashboard back-link.
* **CHANGE (Settings page)**: All inline hex codes (8 instances: budget bar, language fallback, success ticks) swapped to `--lp-*` design tokens.
* **NEW (heuristic `LuwiPress_Translation::score_text_language`)**: Public static method that scores any text body against an expected target language. Stop-word signatures cover the 10 most-shipped translation languages. Used by drift detection but exposed for any downstream module.
* **NEW (helper `LuwiPress_Translation::clear_retranslate_guards`)**: Public static. Walks WPML translations of a source post, deletes `_luwipress_elementor_translated` + `_luwipress_elementor_chunked` + per-lang status meta on each target so the AI pipeline can re-run cleanly.

= 3.1.48 — Theme Tools framework (Theme Bridge primitive) =
* **NEW (`LuwiPress_Theme_Bridge`)**: Singleton primitive that lets companion themes register maintenance tools and theme_mod proxies via two filters (`luwipress_theme_tools` + `luwipress_theme_settings`). Tools expose scan/execute/restore callbacks; the bridge handles capability gates, nonces, WPML/Polylang sibling expansion (trid auto-expand), and a 20-entry backup ring buffer in `wp_options.luwipress_theme_tool_backups`. Active companion themes get a new `LuwiPress → Theme` admin tab (Status / Tools / Settings) auto-rendered from the registered tools.
* **NEW (REST `/luwipress/v1/theme/*`)**: 7 endpoints — `GET tools`, `POST tools/scan`, `POST tools/run`, `POST tools/restore`, `GET tools/backups`, `GET|POST settings`, `GET status`. All token-or-admin gated.
* **NEW (Admin AJAX endpoints)**: `luwipress_theme_tools_{scan,run,restore,backups}` + `luwipress_theme_settings_{save,reset}` for the in-dashboard UX.
* **NEW (KG Action Queue integration)**: `luwipress_kg_action_queue_external_candidates` filter so the bridge can inject theme-tool findings as Action Queue candidates alongside core enrichment/SEO opportunities. Severity-graded ROI rubric (canonical/hreflang/redirect-chains at high tier; orphan landings + empty archives at low). Cached 1h; busts on `luwipress_theme_tool_executed` action.
* **NEW (Hero stat-bar + Reset-to-defaults)**: Status tab shows 7-card snapshot (theme/companion/tools/findings/untranslated/backups/kit-css headroom). Settings tab gains per-group reset button. Cached 5min.
* **NEW (assets/js/theme-tools.js)**: Vanilla JS (no jQuery dep) — scan/execute/restore + run-all-audits sequential runner + per-group reset.
* **CHANGE**: Admin `LuwiPress` menu auto-adds the Theme submenu only when the active theme registers via the companion contract. Hidden otherwise — keeps the menu honest.

= 3.1.47 — KG Autopilot (Track D.3) =
* **NEW (`LuwiPress_KG_Autopilot`)**: Third layer of the KG middleware backbone. Reads candidates from `LuwiPress_KG_Opportunities` (Track D.2), filters by `min_confidence`, enforces per-workflow daily caps, and dispatches AI workflows asynchronously via `wp_schedule_single_event`. Default OFF; first run after enable defaults to dry-run (logs `would_dispatch` only, no real workflow fires) so the operator validates before flipping to live.
* **NEW (Confidence scoring on candidates)**: `LuwiPress_KG_Opportunities::build_candidates()` now attaches a `confidence_score` (0..100) per candidate, derived from the candidate's signal strength. RECENTLY_REGRESSED scales with pct_change; STALE_ENRICHED scales with days since enrichment. Autopilot's `min_confidence` setting gates dispatch on this value.
* **NEW (REST endpoints — `/knowledge-graph/autopilot/*`)**: `GET|POST /settings` (partial-update), `POST /run-now` (manual cycle trigger, respects caps + dry-run), `GET /log?limit=50` (recent dispatches with full context). All token-or-admin gated like the rest of `/knowledge-graph/*`.
* **NEW (Daily cron `luwipress_kg_autopilot_cycle`)**: Auto-registered on `init`. Reads `enabled` flag — exits immediately if false so cron is cheap when off.
* **NEW (Idempotency meta `_luwipress_kg_autopilot_dispatched_at`)**: Per-entity timestamp prevents double-dispatch within `dispatch_window_hours` (default 24h). Window is configurable per setting.
* **NEW (Admin UI on KG dashboard)**: New "AI Autopilot" panel above the activity feed — enabled / dry-run toggles, min-confidence + window inputs, per-workflow caps (enrich / seo / translate), Save + Run-now buttons, recent dispatches log with action icons (🚀 dispatched, 👁 would-dispatch, ✅ completed, ⚠ failed). State pill (Disabled / dry-run / LIVE) updates on save.
* **NEW (Audit log integration)**: All dispatches + would-dispatches + dispatch outcomes go through `LuwiPress_Logger` with `level='kg_autopilot'`. Cap counter reads the same rows so cycle accounting is single-source-of-truth. Daily cleanup cron prunes per existing 30-day retention.
* **NEW (v1 workflow support — `enrich`)**: Dispatched candidates whose `workflow=enrich` actually fire `LuwiPress_AI_Content::handle_enrich_request()` synthetically (full validation chain: WPML guards, locks, auto-snapshot). `seo` + `translate` candidates record a `pending_implementation` outcome — operator can see what autopilot would have done, ready for v2 release wiring those workflows.

= 3.1.46 — KG Middleware Backbone (Signal Layer + Opportunities v2) =
* **NEW (`LuwiPress_KG_Signals`)**: Subscribes to the 3 plugin action hooks added in 3.1.45 (`luwipress_after_product_enrich` / `_seo_meta_write` / `_translation_request`) and writes structured `kg_event` rows into `wp_luwipress_logs` with full context payload (event_type, entity_type, entity_id, snapshot_at, optional kg_score_after). Fires `luwipress_kg_event_recorded` so companions can subscribe without re-hooking source events. Auto-busts the KG response cache so the next dashboard refresh picks up the change deterministically.
* **NEW (`GET /knowledge-graph/events`)**: Filtered KG event stream (limit / since / event_types[] / entity_id) backed by the logger table. `?summary=1&hours=24` returns aggregate counts.
* **NEW (`LuwiPress_KG_Opportunities`)**: Action Queue v2 with two new server-computed candidate types: **RECENTLY_REGRESSED** (entity coverage that lost ground in the last 7 days vs the existing `luwipress_kg_summary_history` 30-day ring) and **STALE_ENRICHED** (products enriched > 90 days ago whose source content has been edited since). Each candidate carries a `why` payload (primary_signal, supporting_signals, baseline_comparison) so the UI can explain ranking instead of just showing a number.
* **NEW (Persistent candidate state)**: Snooze (default 24h, max 30d), dismiss (auto-prunes after 30d), in_progress states stored in `luwipress_kg_candidate_state` option keyed by stable candidate ID. Endpoints: `POST /knowledge-graph/candidate/{snooze|dismiss|clear}`. Action Queue cards now render a snooze 💤 + dismiss ✕ button for v2 candidates; cards fade out and the queue refreshes.
* **NEW (Action Queue v2 in KG response)**: `next_wins_v2` array added under `opportunities` in the main `/knowledge-graph` response — additive (existing `by_type` + `top_products` keys unchanged so old clients keep working).
* **NEW (Activity feed kg_event styling)**: kg_event rows in the activity feed render a per-event-type icon (🪄 enrich, 🎯 seo, 🌐 translate) plus a gold-accent gradient row so structural KG activity stands apart from generic info logs.
* **CHANGE**: `luwipress_daily_cleanup` cron now also prunes the KG candidate state option (drops dismissed entries older than 30 days, auto-clears expired snoozes).

= 3.1.45 — Theme companion contract + reciprocal awareness =
* **NEW (`LuwiPress_Plugin_Detector::detect_theme()`)**: Mirrors `detect_seo()` / `detect_translation()`. Returns `{detected, slug, name, version, author, is_child_theme, template, is_official_companion, official_themes}`. Drives a green "✓ Theme paired: {name} v{version}" pill on the LuwiPress admin dashboard when an official companion theme is active.
* **NEW (`luwipress_official_themes` filter)**: Registry of official theme slugs. Defaults to `['luwipress-gold']`. Themes (or third-party plugins) hook in to register additional official themes — drives the companion pill + future capability lookups.
* **NEW (Action hooks for theme cache busting)**: First-class signals replace generic `save_post_*` inference for downstream consumers.
  * `luwipress_after_product_enrich( $product_id, $updated_fields )` — fires after `class-luwipress-ai-content.php::enrich_product()` completes.
  * `luwipress_after_seo_meta_write( $post_id, $fields )` — fires after `POST /seo/meta`.
  * `luwipress_after_translation_request( $product_id, $language, $status )` — fires after a translation callback writes meta + creates the WPML/Polylang translated post (`saved` or `partial`).
* **NEW (`luwipress_theme_companion` filter contract)**: Capability matrix the active theme can populate so plugin admin (and companion plugins like WebMCP, Marketplace Sync, Open Claw) can introspect what storefront features the paired theme ships. Foundation for tiered packaging; already useful as single source of truth.

= 3.1.44 — Marketplace + Open Claw companion split =
* **CHANGE (Marketplace split)**: Multi-marketplace publishing (Amazon, eBay, Trendyol, Hepsiburada, N11, Etsy, Walmart, Alibaba) moved to a dedicated companion plugin: **LuwiPress Marketplace Sync** (v1.0.0). Stores that don't sell on third-party marketplaces no longer carry ~80 KB of dormant adapter code. All `luwipress_*` option keys and the `wp_luwipress_marketplace_listings` table are unchanged — install the companion and previously-saved credentials light up instantly. The Marketplaces settings tab in core is removed; the companion ships its own "LuwiPress -> Marketplaces" submenu. REST endpoints (`/wp-json/luwipress/v1/marketplace/*`) move with the companion.
* **CHANGE (Open Claw split)**: The admin-side AI chat assistant (Open Claw) is now a separate companion plugin: **LuwiPress Open Claw** (v1.0.0). The "Ask AI" link on the dashboard becomes a soft reference — visible only when the companion is active, hidden otherwise. CSS for the chat UI is retained in core for backward compatibility (no impact on stores that don't install Open Claw).
* **NOTE**: No data migration needed. Both companions read existing options. Operators upgrading from 3.1.43 to 3.1.44 should install whichever companions they actually use; missing companions just disable that feature surface.

= 3.1.43 — WordPress Abilities API + ACP attribution bridge (paired with WebMCP 1.0.9) =
* **NEW (Abilities API bridge)**: New `LuwiPress_Abilities` class registers every WebMCP tool as a `luwipress/<tool-name>` ability via `wp_register_ability()` on the `wp_abilities_api_init` hook. Available on WordPress 6.9+ — older WP soft-skips silently. Read-only abilities default to public (visible in REST and the WC MCP namespace), mutating abilities default to private. Per-ability override via `luwipress_abilities_public_overrides` option. Token-based auth keeps living in WebMCP (Abilities API permission_callback only sees `$input`, not the request headers); both registries run side-by-side.
* **NEW (WooCommerce MCP namespace inclusion)**: `woocommerce_mcp_include_ability` filter wires LuwiPress public abilities into the WC MCP server (the official adapter shipped Feb 2026). Operators using a single WC MCP endpoint can now reach LuwiPress tools through it without a second integration. Filter is no-op when the WC MCP adapter isn't installed.
* **NEW (ACP attribution bridge — GA4 + Meta CAPI)**: New `LuwiPress_ACP_Attribution` class reconstructs server-side conversion tracking for orders that arrive via Stripe's Agentic Commerce Protocol (ACS) — those orders bypass the browser entirely so GA4 gtag, Meta Pixel, and Google Ads gclid never fire. The bridge dispatches to GA4 Measurement Protocol and Meta Conversions API on `woocommerce_payment_complete` / `_processing` / `_completed`, with the AI agent name extracted from ACP `affiliate_attribution` order metadata, hashed user_data (email/phone/name) for CAPI match, and a stable `event_id` for cross-channel deduplication. Async dispatch via `wp_schedule_single_event` so checkout pages don't block on outbound HTTP. Default OFF + debug mode (logs payloads instead of firing) until operator validates credentials. Endpoints: `GET|POST /attribution/settings`, `GET /attribution/log?limit=N`, `POST /attribution/test`, `POST /attribution/dispatch {order_id, force}`. Tools: `attribution_settings_get`, `attribution_settings_set`, `attribution_log_recent`, `attribution_test_send`. Audit log retains last 200 dispatches with channel-by-channel result codes. Idempotency meta `_luwipress_attribution_dispatched` prevents double-fire on hook overlap.
* **NEW (Google Ads Enhanced Conversions)**: ACP attribution bridge now includes a third channel — server-side click conversion upload via the Google Ads v17 REST API. Because ACP orders carry no GCLID, conversions go up as Enhanced Conversions with hashed `userIdentifiers` (email/phone/firstName/lastName) which Google's docs explicitly endorse for GCLID-less attribution. OAuth refresh tokens are exchanged on demand and cached for 50 minutes (10-minute headroom under the 1-hour expiry); refresh-token rotation is automatic. Manager (MCC) account flows supported via the optional `login_customer_id` setting.
* **NEW**: `LuwiPress_WebMCP::get_tool_registry()` public getter — read-only access to the MCP tool registry for cross-plugin consumers (used by the Abilities API bridge to mirror tools without duplicating definitions). Schema unchanged for existing integrations.

= 3.1.42 — Customer feedback batch hotfix (paired with WebMCP 1.0.8) =
* **NEW (Theme Builder template MCP)**: Six new endpoints + tools for managing Elementor Pro Theme Builder templates from MCP / REST. Lets AI agents enumerate, create, clone, configure conditions, and delete templates (header / footer / single-post / single-product / archive / single-404 / search-results / cart / checkout / my-account / popup). Bridges the gap so the operator can ask "create a Single Post template cloned from Single Product, then apply it to all posts" in one MCP call. Endpoints: `GET /elementor/templates`, `POST /elementor/template/create`, `POST /elementor/template/clone`, `GET|POST /elementor/template/conditions`, `POST /elementor/template/delete`. Tools: `elementor_templates_list`, `elementor_template_create`, `elementor_template_clone`, `elementor_template_conditions_get`, `elementor_template_conditions_set`, `elementor_template_delete`. Active header/footer/kit are protected from accidental deletion (force=true to override).
* **FIX (BUG-007)**: `seo_enrich_product` accepts `force_regen_faq=true` option (or `options.force_regen_faq` body param). When set, deletes the existing `_luwipress_faq` meta before the AI call so the pipeline genuinely regenerates instead of echoing cached/identical answers. WebMCP tool schema updated to expose the new flag.
* **FIX (BUG-008)**: Knowledge Graph `opportunities.top_products` now exposes both `id` and `product_id` (same value) plus `issue_count`. Admin UIs can render clickable rows without a second roundtrip.
* **FIX (BUG-009)**: Knowledge Graph `store.top_sellers` filters out trashed/deleted products. The aggregate query joins on order_itemmeta which retains rows after product delete; without the filter ~50% of returned entries had empty `name` on Tapadum. Now skips any seller whose post is missing or not in `publish` status.
* **FIX (BUG-013)**: Taxonomy translation save no longer fails silently. Both the missing-field skip path and the underlying save failure now produce structured error entries with a `reason` field (`missing_term_id`, `missing_language`, `missing_name`, or `save_failed: <message>`) plus the WP_Error code. Failed `post_tag` translations were previously impossible to debug.
* **FIX (Snapshot slash discipline, finally)**: Earlier 3.1.42 attempts removed `wp_slash()` then re-added it; both broke rollback in different ways. The actual root cause was that `update_post_meta($snapshots_array)` strips slashes deep into nested string values, so the legacy `data` field stored the JSON without escapes — Elementor's parser then fails on embedded HTML quotes. New snapshots now also store `data_b64` (base64-encoded payload, opaque to slash sanitization) and the rollback path prefers it when present. Legacy snapshots fall back to `data` and can be recovered via the JSON-repair pipeline shipped in `temp/tapadum/restore_30_hubs_via_repair.py` (dev tool).


* **FIX (BUG-001)**: `crm_segment_customers` MCP tool returned "Unknown segment: " on every call. Internal `WP_REST_Request` was not receiving the URL-path-extracted `segment` parameter — set explicitly via `set_url_params()` and `set_query_params()`. Same pattern fixed for `crm_customer_profile`.
* **FIX (BUG-005, BUG-006)**: AEO FAQ pipeline (`aeo_save_faq`, `aeo_generate_faq`) returned "Invalid product ID" on every call regardless of the actual product. Root cause: `proxy_rest_post()` populated body params but handlers used `get_json_params()` which returned null. Now sets all four channels (body, query, individual params, JSON body) so any handler style works.
* **FIX (BUG-010)**: `content_stale` returned `cutoff: 1970-01-01 00:00:00` and zero results because `days` parameter had no default — `strtotime("-NULL days")` evaluates to Unix epoch. Added defaults (180 days, product post type, 50 per_page) and exposed `days/post_type/per_page` in the MCP schema.
* **FIX (BUG-011)**: PHP fatal "trim(): Argument #1 must be of type string" in Elementor translation cron. Some widget settings carry non-scalar values (typography arrays, link objects) — added `is_scalar()` guard before `strip_tags()/trim()`.
* **FIX (BUG-014)**: Cron loop on shortcode-only / no-translatable-text pages. Each cron tick re-queued the same post forever, bloating snapshot lists and consuming AI tokens. The translate path now writes `_luwipress_no_translatable_text` meta on completion; the cron entry point checks this flag and skips immediately.
* **FIX (Elementor rollback double-encode bug)**: `/elementor/rollback` corrupted `_elementor_data` because `update_post_meta()` was called with `wp_slash($target['data'])` while WordPress auto-slashes a second time. Removed the explicit `wp_slash()` call. Rollback is now safe to use again. (Resolves the 2026-05-01 Tapadum Darbuka incident root cause.)
* **CHANGE (IMP-006)**: Knowledge Graph opportunity scoring no longer counts `missing_howto` (Google deprecated HowTo for product pages) or `missing_speakable` (deprecated entirely late 2024). Removes ~12% of inflated priority signal so the queue surfaces real wins. Tracks the Schema Reality v1.1 doctrine.
* **NEW**: `GET /crm/suspicious-bots` (read-only) — lists customer accounts with bot-like signals (random-looking email local parts, zero orders, no login activity). Operator reviews + deletes via standard WP user admin. An in-product "purge selected" action is planned for 3.1.43 once thresholds are tuned with the operator.

= 3.1.41 — Raw post_meta recovery surface (paired with WebMCP 1.0.7) =
* NEW: **`GET /post-meta/raw?post_id=X&meta_key=Y`** — read a single post_meta value as base64-encoded raw bytes, plus diagnostic shape (length, sha256[:16], slash run counts, json_decode probe with stripslashes-pass index). Whitelist-enforced — only `_elementor_data`, `_elementor_page_settings`, `_elementor_css`, and `_luwipress_elementor_snapshots` are exposed. Use to inspect actual stored bytes when `/elementor/*` endpoints return `parse_error` and you need to measure escaping depth before remediating.
* NEW: **`POST /post-meta/raw`** — write a post_meta value from base64-encoded bytes. RECOVERY-ONLY DESTRUCTIVE SURFACE — caller must pass `confirm_token = "I_KNOW_WHAT_IM_DOING"`. Same whitelist as the GET. Always backs up the prior value into a parallel meta key (`_luwipress_raw_pre_write_<key>_<epoch>`) before overwriting, so a follow-up restore is possible without DB access. For `_elementor_data` writes, regenerates Elementor CSS + purges page cache automatically. Every call is logged at `warning` level.
* CONTEXT: Added because the existing `/elementor/rollback` handler was found to corrupt `_elementor_data` on Tapadum Darbuka (page 8819) on 2026-05-01 via a write-path encoding bug. None of the existing inspect endpoints could see the actual stored bytes (they all parse first), so a recovery surface that bypasses parsing was needed. This release ships ONLY the recovery surface — the rollback bug fix itself will follow once the raw bytes are inspected and the encoding double-application is confirmed.

= 3.1.40 — Elementor inspect endpoints (paired with WebMCP 1.0.6) =
* NEW: **`GET /elementor/outline-deep/{post_id}`** — deep variant of `/elementor/outline` that walks every section, container, column, and widget. Returns a tree with element IDs, types, widget types, text previews, and (optional) background color/image info. Use this to locate elements when the lighter outline does not include the level you need. Optional params: `include_bg_info` (default true), `include_settings` (heavy, default false), `preview_chars` (20-200, default 80).
* NEW: **`GET /elementor/find/{post_id}/{element_id}`** — locate an element by its ID and return its full ancestor chain (root → element), type, widget type, text content, and style summary. Eliminates the need to scrape rendered HTML when you have an `elementor-element-XXX` id and want to learn what it actually represents in the page tree.
* NEW: **`POST /elementor/find-by-text`** — search every translatable widget text in a page and return matching elements with their ancestor chain. Match modes: `contains` (default), `exact`, `starts`, `ends`. Optional `case_sensitive` and `limit` (1-50, default 20). Locates which widget owns a piece of rendered text without DOM scraping.
* NEW: **`POST /elementor/kit-css/preflight`** — preflight check for Kit CSS payloads before pushing. Returns candidate size, current live size, headroom against the conservative option-size limit (~412 KB), paired-angle-bracket count (`wp_strip_all_tags` risk indicator), and a list of existing layer markers (`V47`, `V48`, `PERCUSSIONS-FIX-V2`, etc.) that could be stripped to make room. Run before `elementor_kit_css_set` when you suspect option-size pressure.
* All four endpoints are read-only / non-destructive. Pair them with the existing `/elementor/outline`, `/elementor/widget`, and `/elementor/global-css` for a full inspect → mutate workflow.

= 3.1.39 — Cross-post section copy endpoint =
* NEW: **`POST /elementor/copy-section`** — copies a top-level Elementor section from one post into another at a given position, with deep ID regeneration and an automatic snapshot on the target before mutation. Sister endpoint to `/elementor/clone` (which only operates within a single post). Use this to remediate WPML drift cases where a translation is structurally missing a section (e.g. breadcrumb) without touching the rest of the translated body — the existing `/elementor/sync-structure` rebuilds the whole tree and can lose translated text when target is missing leading sections (verified 2026-04-28 on Tapadum FR pilot). Payload: `{source_post_id, source_section_id, target_post_id, target_position}` — `target_position: 0` prepends, `-1` or out-of-range appends. Response includes `new_section_id` (regenerated), `snapshot_id` (rollback target), `target_position` (final insertion index).

= 3.1.38 — WooCommerce is now optional + dashboard ribbon UI =
*Post-3.1.38 in-place hotfixes (folded into 3.1.38, no version bump per project policy):*
* FIX: **Shortcode-only pages (Cart / Checkout / My Account) no longer fail translation.** WooCommerce shortcode pages contain no Elementor widget text — only the shortcode that renders the cart/checkout UI at runtime. Previously these pages threw `no_text: No translatable text found on this page` and stayed eternally in the missing-list. Now `translate_page` falls back to creating a structure-only WPML translation pair (clones source `_elementor_data`, no AI calls, $0 cost) so WPML knows the language pair exists and the missing-list clears. Operator can still localize shortcode args via WPML String Translation if needed.
* FIX: **WPML hook race re-stamp loop.** Auto-cleanup used to DELETE bad icl_translations rows when WPML overwrote `language_code` on freshly-created translation posts. Result: source kept appearing in missing-list -> re-translated -> WPML mis-stamped again -> infinite loop (one full create+translate per Translation Manager page render). Now `create_translation_post` writes `_luwipress_translation_source` + `_luwipress_translation_language` meta on every translation post, and auto-cleanup uses those to RE-STAMP the row with correct language_code + source_language_code instead of deleting it. Loop closed permanently.
* FIX: **Translation cascade-duplicate eradication.** WPML hook race could overwrite the language_code of freshly-created translation posts to 'en' immediately after insert, causing them to look like fresh EN sources on the next missing-list query and get re-translated infinitely. Five-layer defense: (1) REST/AJAX guard rejects non-source posts, (2) cron worker guard catches stale queued jobs, (3) inline `translate_page` chokepoint with auto-cleanup of bad icl_translations rows, (4) Translation Manager auto-cleanup on every page render, (5) source-list SQL `NOT EXISTS` against `_luwipress_elementor_translated=1` meta as the strong source-vs-translation discriminator (the meta is only set on translation posts, so its presence means "not a source" regardless of what icl_translations says).
* NEW: **Outdated translation detection (semi-automatic).** Every successful translation stamps the target post with `_luwipress_synced_source_modified` (source's modified timestamp at sync time). New `GET /translation/outdated` REST endpoint returns sources whose `post_modified_gmt > stamp`, grouped by source with per-language `lag_hours`. Translation Manager renders an amber panel listing each outdated source with two one-click actions: **Sync structure** (copies new structure from source while preserving existing translations for unchanged texts — safe, no AI cost) and **Re-translate** (full re-translation, costs AI tokens — confirmation prompt).
* FIX: **Fix Orphans Type 4 — multi-EN-row trid cleanup.** New maintenance routine catches the "two posts in one trid both flagged source_language_code=NULL" case that existing Type 1/2/3 detection couldn't handle. Oldest row stays as legit EN source; later rows have their WPML metadata removed (post itself preserved).
* FIX: **dispatch() return-shape fatal.** `LuwiPress_AI_Engine::dispatch()` returns an array `{content, input_tokens, ...}`, not a bare string. Two call-sites (Elementor title fallback, taxonomy single-term translate) were doing `trim($result)` directly which threw `trim(): Argument #1 must be of type string, array given` — silently aborting the translation post-processing pipeline mid-write. Now extracts `$result['content']` first.
* IMPROVEMENT: **Maintenance Tools UI minimal redesign.** Collapsed by default into a single line; opens to a compact icon-grid (7 utilities × ~140px tiles). Hover for full description tooltip. Replaces the previous 7-card vertical stack that pushed actual translation rows below the fold.
* IMPROVEMENT: **Translation Manager fully bypasses admin-ajax cache.** Inline missing-list is server-side pre-fetched at page render — JS reads from `lpInlineMissing` and never round-trips through admin-ajax for the missing-fetcher. The "X missing in DB but unreachable" amber state can no longer be caused by stale AJAX responses.
* IMPROVEMENT: **WPML hook race protection in `create_translation_post`.** Direct DB insert of icl_translations is now followed by explicit WPML cache invalidation + verify-then-force-correct loop.

*Original 3.1.38 changelog:*
* CHANGE: **WooCommerce is no longer a hard install-time requirement.** The `Requires Plugins: woocommerce` header was removed. LuwiPress now activates and runs on any WordPress site. WC-dependent features (product enrichment, AEO product schema, marketplace publishing, CRM customer segmentation, product Knowledge Graph nodes, review analytics) self-disable when WooCommerce is missing; the rest of the plugin (content scheduler, customer chat with site-content RAG, AI provider settings, token tracking, generic SEO/AEO writer for any post_type) keeps working.
* NEW: **Status ribbon on the dashboard** — one compact pill per integration category (AI key, WooCommerce, SEO, Translation, SMTP, Cache, Page Builder, Analytics, Google Ads, Meta Pixel, Product Feed). Green dot = friendly plugin detected, red dot = empty slot. Hover any pill for the "what does this enable, what's recommended" tooltip. Click a red pill to open the standard WordPress plugin-information modal (screenshots, description, Install Now button — operator decides). Click a green pill to open the detected plugin's settings page. Single chip row replaces three legacy UI surfaces (banner notices, install prompts, plugin docs links).
* NEW: **Endpoint registration is WC-aware.** Product-bound REST routes (`/product/enrich`, `/product/enrich-callback`, `/product/enrich-batch`, `/product/enrich-batch/status`) only register when WooCommerce is active, so `/wp-json` route map reflects what the install can actually do. Generic routes (`/seo/schema`, `/seo/faq`, `/enrich/settings`, `/content/stale`) always register.
* NEW: **`LuwiPress::is_wc_active()` helper** — single source of truth wrapping `class_exists('WooCommerce')`. Used at module level to gate WC-only hooks (product meta box, `woocommerce_new_product` auto-enrich, etc.).
* IMPROVEMENT: **AEO save handlers + Review Analytics now guard `wc_get_product` calls** so a misconfigured cron or external integration calling them on a WC-less site returns a clean 501 / null instead of a fatal undefined-function error.
* IMPROVEMENT: **Admin notices are suppressed inside LuwiPress admin pages.** Third-party notices (theme TGM "recommended plugins" prompts, WP core update nags, plugin promo banners) used to inject between the dashboard `<h1>` and content body, physically pushing the hero around. They now hide on LuwiPress screens (Dashboard, Knowledge Graph, Settings, Translations, Content Scheduler, Usage & Logs, WebMCP) and reappear on native WP screens (Plugins list, WP Dashboard home) where the operator expects them.
* IMPROVEMENT: **Dashboard menu position fixed** — the LuwiPress entry now sits right under the WordPress Dashboard menu (top of sidebar), no longer fighting other plugins for slots in the 25-55 position cluster where heavy themes register their custom-post-type menus.
* IMPROVEMENT: **Header pills consistency** — Knowledge Graph shortcut, version badge, and Settings cog in the dashboard header all share the same compact pill styling as the status ribbon below; tooltips hover-overlay correctly without being trapped under stat-card stacking contexts.
* FIX: **Build pipeline now hard-fails on UTF-8 BOM.** Editors that silently re-save PHP files with a BOM (Notepad++ save-with-BOM, some FTP file managers) used to ship 3 bytes of stdout that broke WordPress activation with "headers already sent" / "plugin generated unexpected output". `build-zip.php` now scans every PHP file's first 3 bytes and refuses to produce the ZIP when a BOM is detected, with the offending file path listed.
* FIX: **Module instantiation timing** — modules now instantiate synchronously inside the plugin constructor instead of via a late-priority hook that could race with `admin_menu` and produce a parent menu without its submenus on first activation.

= 3.1.37 — Hotfix: LiteSpeed cache bypass delivery fix + declare WooCommerce dependency =
* SECURITY: **Hotfix for 3.1.36's REST cache hardening.** The 3.1.36 release added `X-LiteSpeed-Cache-Control: no-cache` as a PHP-level response header via `header()`, but on some hosts (Hostinger LSWS + LiteSpeed Cache) `rest_post_dispatch` fires after PHP response headers have been flushed, so the `header()` call was a silent no-op. As a result, LiteSpeed continued to cache authenticated REST responses by URL and could replay the cached body to subsequent anonymous requests. 3.1.37 routes the signal through `WP_REST_Response::header()` instead, which is applied by WordPress REST's own header-flush stage and reaches LiteSpeed reliably. **All `luwipress/v1/*` authenticated endpoints now carry the LS bypass header correctly. Existing customers on LiteSpeed: after upgrading, run a one-time `Toolbox → Purge All` in the LiteSpeed Cache admin so previously cached entries are flushed.**
* IMPROVEMENT: **Core plugin now declares its WooCommerce dependency** via the `Requires Plugins: woocommerce` plugin header. WordPress 6.5+ honours this tag and prevents activation when WooCommerce is missing, replacing the old "install and figure out later" behaviour with a clear install-time gate. The runtime `class_exists('WooCommerce')` guards are unchanged — this is a UX improvement for hosts running WP 6.5+, not a functional change.

= 3.1.36 — REST cache hardening + batch status persistence + AEO/Token polish =
* SECURITY: **Closed a cache-layer data visibility gap on authenticated REST responses.** All `luwipress/v1/*` endpoints now stamp `Cache-Control: no-store, private, must-revalidate` plus a matching `X-LiteSpeed-Cache-Control: no-cache, no-store, private` header on every response, so upstream page caches (LiteSpeed, Varnish, NGINX FastCGI, Cloudflare in cache-everything mode) cannot replay an admin's authenticated body to a subsequent unauthenticated visitor. Truly public routes (`/status`, `/health`, `/chat/config`) keep their existing cacheable behaviour. **Recommended additional defence-in-depth:** if your host runs LiteSpeed Cache or another full-page cache, also add `wp-json/luwipress` to the "Do Not Cache URIs" list — the headers shipped here are the primary fix, the rule is belt-and-braces.
* FIX: **`/product/enrich-batch/status` now keeps reporting `total / completed / progress` after the batch finishes.** Previously, once every product in a batch flipped to `completed` and downstream workflows replaced the status postmeta, the status endpoint returned `total: 0, progress: 0` — making it look like the batch had vanished. The endpoint now persists a 24-hour batch-level summary at queue time and merges it back into the response, so `Enrich all products in category` (and the floating progress monitor) keep showing accurate post-completion numbers.
* FIX: **`/aeo/coverage` now exposes both single-language and multilingual numbers** so the figures in the Usage page line up with the Knowledge Graph instead of looking 4× higher just because WPML/Polylang translations are counted as separate posts. The response gains two new blocks — `primary_language` (WPML/Polylang originals only — matches the Knowledge Graph total) and `all_languages` (every published product across every language — the previous behaviour). Top-level fields are unchanged for back-compat.
* FIX: **`/token-recent?limit=N` query parameter is now honoured.** The endpoint hard-coded `limit=20` regardless of what the caller asked for; admin dashboards that requested e.g. 5 rows had to client-side slice. Limit is now read from the query string and clamped to `1..100` with a default of 20.

= 3.1.35 — Knowledge Graph: queued actions lock until refresh =
* FIX: **Queued action buttons no longer re-enable while the backend is still processing.** When you hit "Translate all" on a taxonomy batch (or Enrich category, or any kgAction), the button used to flip to "Queued (2/2)" and then reset itself to its original label 3–25 seconds later — inviting the operator to mash it again while the first batch was still running. Now the button stays locked at its success state (dimmed, cursor `not-allowed`, no hover lift) until the panel refreshes; the refresh re-renders the whole panel from fresh data, producing a new button that reflects the current state (usually disabled because there's nothing left to do). Only error paths reset the button and prompt "retry?" so failed calls are still recoverable.
* FIX: **Taxonomy Translate all** reports the actual terms queued (`Queued (12 terms)` instead of `Queued (2/2)` which conflated taxonomy types with terms). Refresh delay bumped 8→12s to give slower WPML string-translation endpoints a chance to land.
* UI: **Disabled kg-btn styling** — dimmed opacity + `cursor: not-allowed`, no hover translate lift. Makes the locked state visually unmistakable instead of a button that looks interactive but does nothing.

= 3.1.34 — Knowledge Graph: stat card hover truly shift-free =
* FIX: **Stat card height is now identical at rest and on hover.** The previous fix moved the cue ("Needs enrich →" / "Show all →") from a height transition to an opacity fade, but because the cue was centered in a flex column its baseline shifted ~2px when it appeared, dragging the graph with it. Cue is now rendered at its full height unconditionally with `visibility: hidden` + `opacity: 0` at rest — hover flips both to visible. The card's bounding box never changes; the graph canvas sits at the exact same y-position whether your cursor is on the stats or not.

= 3.1.33 — Settings → API Keys: compact 2-column layout + Knowledge Graph stat hover stability =
* FIX: **Knowledge Graph stat cards no longer shift layout on hover.** The "Show all →" / "Needs SEO →" action cues used to slide in on hover with a max-height transition, which pushed the graph canvas down by ~15px every time the cursor crossed a card — visibly jittery and annoying when you're trying to scan the graph. Cue space is now reserved up front (fade-in only, no height change) and the card has a fixed `min-height` so its bounding box never changes. Hover only swaps the border colour and adds a subtle shadow.
* UI: **Provider card went compact.** Pill height trimmed (padding 16→12px), pill font 15→14px, pill body gets one tight label + vendor line instead of a spacious two-line stack. Card padding 28→22px. Result: the four provider pills + active key + model all fit on one viewport height on a 1366px screen — no more scrolling to see the model picker.
* UI: **Key input + model picker now share a 2-column layout on desktop.** Left column: API key input + "Get your API key →" link. Right column: model grid (single-column list of cards for tight vertical alignment). On tablet/mobile (<880px) they stack back to one column. Custom (OpenAI-Compatible) provider keeps its single-column stack since it needs preset + base URL + key + model in sequence.
* UI: **Cost Protection card removed** from the API Keys tab. Four bullet points reiterating what the Daily Budget Limit already does below — redundant marketing copy that pushed useful settings below the fold. The Cost & Limits card above already delivers the same information directly through the actual controls.
* UI: API key input now fills its column width instead of capping at 520px — long keys (OpenAI sk-proj tokens regularly hit 160+ chars) fit without truncation.

= 3.1.32 — Knowledge Graph: legend removed =
* UI: **The static legend box in the graph's bottom-left corner is gone.** It listed eight node-type / status colors that operators don't actually reference in practice — hovering a node already shows its type + key stats in the tooltip, which is more informative. Removing it gives the graph canvas an extra ~200×180px of clean space and the view no longer has a floating UI element competing with the data.

= 3.1.31 — Knowledge Graph: customers radial layout + minimal labels =
* UI: **Customers view now lays out as a radial cluster.** The "All customers" hub is anchored at the center (fixed y-force, 80% strength) and every populated segment sits on a circle around it at a computed angle (clockwise from 12 o'clock, evenly spaced by lifecycle order). Reads like a clock: New at the top, walking clockwise through the health path, ending at Lost. Reliably stays inside the viewport regardless of how many cohorts are populated. Segment radius 14–28px, hub 28px.
* UI: **Node labels shrunk one more notch** — regular labels 9.5→8px, anchor labels (categories / languages / hubs) 11→10px. Combined with a slight opacity drop (0.85 for regular), the graph now reads as "shapes first, text on demand."
* UI: **Post and page labels hidden at rest, revealed on hover.** The full title is still always available in the tooltip; the persistent on-canvas label was causing a wall-of-overlap in Posts view where 57 titles competed for the same pixels. Hover a node → its label fades in. Product nodes never had labels — same principle.
* UI: **Hubs are tooltip-only.** The "All customers" / "Site pages" hubs now explain themselves via tooltip ("click a segment around the hub for a breakdown") and don't open a detail panel on click — they're visual anchors, not drill targets.

= 3.1.30 — Settings → API Keys: model picker lives with the provider =
* UI: **Model selection moved inside the provider card.** Before, you picked a provider (e.g. Gemini) at the top and then had to scroll to a separate "AI Model & Cost Control" card with a dropdown showing all three providers' models — so you could end up with "Provider: Gemini" and "Model: GPT-4o Mini" saved together. The new layout puts a **per-provider model card grid** right under the API key input. Only the active provider's models are shown, and switching pills auto-selects that provider's recommended default. Cross-provider mismatch is no longer possible.
* UI: **Model cards use the same visual-selection language as the scheduler's depth cards.** Title + cost (per-1M tokens, input/output) in monospace, green "Recommended" badge on the default pick, primary-tinted gradient + check badge when selected.
* UI: The "AI Model & Cost Control" card was split — model moved up to the provider card; the remaining card renamed to **"Cost & Limits"** (daily budget + max output tokens only).
* UX: **Cross-provider migration handled silently.** If a previously-saved `luwipress_ai_model` belongs to a provider you're not currently using, the UI falls back to the new provider's recommended default and a hidden `luwipress_ai_model` field syncs on every provider-pill or model-radio change — so the form submit always carries a model that matches the active provider.

= 3.1.29 — Knowledge Graph visual clarity pass =
* UI: **Orphan nodes no longer drift to the edge of the canvas.** Categories with zero products (like "Tongue Drum" on Tapadum) and unconnected pages used to float 800-1500px away from the main cluster because nothing pulled them back. Radial centering force is now ~2× stronger and every tick clamps each node inside a 40px inner margin — the graph stays a compact, coherent shape regardless of orphan density.
* UI: **Node labels shrunk** — regular labels 11→9.5px and posts/pages trim at 22 chars instead of 32. Categories, languages, and hub nodes keep the bigger 11px label so they stand out as navigational anchors. Full titles still show in the hover tooltip. Result: the "wall of overlapping blog post titles" from earlier screenshots is gone.
* UI: **Pages view has structure.** Pages are mostly top-level on a typical WP site (parent_id=0), so the old parent-child-only graph rendered 60+ isolated dots. Now there's a virtual "Site pages" hub that every orphan page attaches to — the view reads as a tree with front/shop/blog as secondary anchors and everything else clustering around the hub. Still clickable → opens the individual page detail panel as before.
* UI: **Customers view rebalanced.** Empty segment buckets (`vip=0`, `loyal=0` on low-repeat stores) are hidden so the view isn't cluttered with zero-count nodes. Segment radii use a square-root scale so the biggest cohort (e.g. 37 one-time) doesn't dwarf smaller ones. The hub is now bigger and anchored above the chain; segments sit in a clean horizontal line below, distributed by lifecycle order (New → Active → At-risk → Dormant → One-time → Lost).

= 3.1.28 — Knowledge Graph: Store Health collapsed into the header =
* UI: **Store Health is now a pill inside the page header**, sitting right next to the "Knowledge Graph" title — score + mini progress bar + chevron, nothing more. Clicking the pill slides down a detail panel (qualitative subtitle + weakest-first dimension chips + achievement badges) under the header. Reclaims ~40px of vertical space at rest so the graph sits ~110px higher on the page.
* UI: The dedicated Store Health banner that used to consume a full row is gone. Details only exist when you want them.

= 3.1.27 — Settings → API Keys simplified + Knowledge Graph density tightened further =
* UI: **Knowledge Graph Store Health banner is now truly one line.** Dropped the explicit "Store Health" label (score prominence tells the story already) and moved the Details toggle out to the right edge of the banner. Score 32→26px at rest, padding 10→6px top + 14→7px bottom. Expanding the toggle reveals the full dimension chips + achievement badges as before — but at rest the banner is ~40% shorter.
* UI: **Stat bar one-notch denser.** Value 18→16px, padding 6→5px, gap + bottom margin also trimmed. The "Show all →" / "Needs SEO →" action cues are now hidden at rest and slide in on hover so the card is cleanest when you're just scanning numbers.
* UI: **API Keys tab rebuilt as a single provider-driven card.** The old layout stacked three cards (AI Provider radios, three-row API Keys table, OpenAI-Compatible) and asked the user to hold context across all of them — "I picked Claude, so why are there three key fields?" The new layout has **one card**: four provider pills (Claude / GPT / Gemini / Custom) at the top, and only the selected provider's key input below. Switch pill → key field fades in, others disappear.
* UI: **API Keys tab rebuilt as a single provider-driven card.** The old layout stacked three cards (AI Provider radios, three-row API Keys table, OpenAI-Compatible) and asked the user to hold context across all of them — "I picked Claude, so why are there three key fields?" The new layout has **one card**: four provider pills (Claude / GPT / Gemini / Custom) at the top, and only the selected provider's key input below. Switch pill → key field fades in, others disappear.
* UI: **Provider pills use the same visual-selection language as the scheduler's depth/mode cards** — soft primary-tinted gradient when active, a circular check badge in the top-right corner, and a green saved-key check next to the provider name when a key is already stored.
* UI: **Input row is modern** — pill-shaped input with monospace font, soft focus ring, matching "show/hide key" toggle. Each provider has a "Get your API key →" link pointing straight to the vendor console (Anthropic, OpenAI, Google AI Studio).
* UI: **Custom (OpenAI-Compatible) no longer lives in a separate card.** Pick the Custom pill and you get preset dropdown, optional base URL (auto-toggled for self-hosted), key, and model picker — all in one flow.
* UX: **Fallback keys preserved without clutter.** If you have keys saved for providers you're not currently using, they're collapsed into a subtle "N other saved keys" details block below the main card, showing just the vendor name + masked key (first 7 + last 4 chars). Expand to peek, switch pill to activate.

= 3.1.26 — Knowledge Graph density + filter feedback =
* UI: **Stat bar cards tightened** — value font 22→18px, padding 10/6→6/7px, label 10→9.5px, row gap 8→6px. Collectively shaves ~25% off the top-of-page footprint so the graph is immediately visible without scrolling on 1366px screens.
* UI: **"Interactive store intelligence map" subtitle removed** from the KG header — redundant with the page title, took horizontal space away from the search box and controls.
* UI: **Filter / view switch now produces visible feedback.** When you click a stat card or switch views (Products → Posts → Pages → Customers), the graph canvas pulses a soft primary-tinted ring and a floating "N products" / "57 posts · needs seo" chip appears in the top-left corner for ~2.4s. Confirms the filter applied even when the redraw is instant.
* UI: Stat card "View details →" / "Top wins →" / etc. hints now fade in on hover (opacity 0.6 → 1) so the card is cleaner at rest but the action cue still signals clickability.

= 3.1.25 — Knowledge Graph layout refinement: collapsible hero + Next Wins below + cleaner graph =
* UI: **Store Health hero is collapsible** — compact by default (score + one-line summary + colour bar), with a "Details" toggle that expands into the full dimension chips and achievement badges. Keeps the graph above the fold on 1366px screens; expand only when you want the full breakdown.
* UI: **Action Queue ("Next wins") moved below the graph.** Operators now explore the store first (graph is immediately visible), then land on the ranked next-step cards underneath — matches the natural "look, then act" reading order and lets KG mutations feel live under your fingers.
* UI: **Graph layout tightened.** Added weak radial pull (`forceX` + `forceY`) so orphan categories and unconnected product nodes no longer drift to the canvas edge — the whole graph stays neatly centered without sacrificing cluster spread.
* UI: **Long node labels are now trimmed to ~32 characters with an ellipsis.** Blog post and page titles could reach 60+ characters and overlap into a wall of text; the hover tooltip still shows the full title on demand. Categories, languages, and segments keep their full names (they're already short).
* UI: **Customers view reworked as a lifecycle chain.** A central "All customers" hub node anchors the view, and the eight segment cohorts now pin left-to-right by lifecycle order (New → Active → Loyal → VIP → At-risk → Dormant → One-time → Lost). Every segment connects back to the hub so the graph is no longer a set of disconnected islands. Makes the retention story visible at a glance — healthy cohorts on the left, drift segments on the right.

= 3.1.24 — Scheduler delta polling (no more full reloads) =
* NEW: **`GET /schedule/delta`** REST endpoint returns only the rows whose status changed since a given timestamp (plus optional `ids` whitelist for active rows). The admin UI now uses this to update row badges, status tags, and error banners **in place** without a full page reload — so your scroll position, bulk-selection checkboxes, and open form state survive the poll.
* UX: When a generating item finishes it may reveal new action buttons (Enrich, Review & publish) — if no rows are selected and the user is still near the top of the page, a soft reload kicks in to surface them. Otherwise the in-place update is enough.
* NEW: `luwipress.nonce_rest` (wp_rest nonce) is now localized alongside `ajax_url` so admin-only REST endpoints can be called from admin-enqueued JS without a second auth round-trip.

= 3.1.23 — CRM threshold settings + AEO Action Queue candidate =
* NEW: **`GET / POST /crm/settings` endpoint** exposes all six customer-segment thresholds (`vip_spend`, `loyal_orders`, `active_days`, `at_risk_days`, `dormant_days`, `new_days`) so operators can tune them without touching the database. High-ticket / low-frequency stores (think furniture, instruments) can drop `loyal_orders` from 3 → 2 and immediately see their 2-order customers appear in the Loyal / VIP buckets. Partial-update pattern — absent keys keep their current value. Response hints the operator to call `/crm/refresh-segments` to reclassify.
* NEW: **Action Queue — AEO Schema candidate.** The Knowledge Graph "Next wins" list now spots AEO gaps too: when HowTo / Schema / Speakable coverage is below 30%, it picks an already-enriched product still missing that field and surfaces a one-click generate action. Rich results (HowTo cards, review snippets) only fire when the structured data exists — this candidate turns that into a concrete recommendation instead of a number in the stat bar.

= 3.1.22 — Usage & Logs live UI + TM design system + Luwi brand icon =
* NEW: **Usage & Logs page is now alive.** Stat cards, "Cost by Workflow" breakdown, 30-day cost sparkline, and daily-budget indicator all update every 10 seconds without reloading the page. Values count up smoothly, changed numbers briefly highlight, and the budget card pulses in warning red once spend crosses 90% of the daily limit.
* NEW: **Cost by Workflow is now a visual bar chart** instead of a plain table. Top 8 workflows are sorted by spend with coloured horizontal bars, call count, and token count inline — easy to spot at a glance which workflow is the cost driver this month.
* NEW: **30-day cost trend sparkline** inline under the stat cards. Tiny SVG that summarises daily spend curve so you can see weekend dips and campaign spikes without leaving the page.
* NEW: **Activity log "Live" mode.** Open the Logs tab, flip the "Live" toggle at the top right, and new log entries slide in from the top every 15 seconds — no more F5 to see the enrichment you just triggered. Toggle state is remembered per browser. Polling automatically pauses when the browser tab is in the background to save API cost.
* NEW: **Instant client-side log search.** Typing in the search box filters the visible entries after a 180ms debounce — no form submit, no URL change. Combined with level pills (All / Errors / Warnings / Info) you can drill into the exact entries you need in under a second.
* NEW: **Shared `LuwiLive` UI primitive.** The polling, count-up, sparkline, bar meter, and highlight-pulse helpers now live as a reusable client-side module, ready to plug into the Dashboard, Scheduler, Knowledge Graph, and Translations pages in upcoming releases so everywhere feels equally alive.
* NEW: **Luwi brand icon now appears in the WordPress admin menu bar and on the LuwiPress dashboard header**, replacing the generic Dashicon placeholder used previously. White variant on the dark menu, full colour on the dashboard.
* FIX: **Design system CSS that was missing.** The `tm-*` classes the Usage and Translation pages relied on (`tm-stats` grid, `tm-stat-card`, `tm-header`, `tm-table`, `tm-btn`, step cards, progress bars) had HTML markup but no matching styles, so both pages rendered as unstyled lists. The full TM design system is now defined with proper grid layouts, buttons, tables, empty states, and hover polish.
* FIX: **Button icons were sitting off-center** inside queue row actions ("Enrich", "Review & publish", "Edit", "View") — WP admin's default `.button` cascade made `.dashicons` inherit odd line-height. Added a scoped normalization: every `.sched-shell .button` is now `inline-flex` with `line-height: 1` and a sized dashicon so icon + label align on the same baseline regardless of button size.
* FIX: **Huge off-center `+` glyph on the empty-state "Create a plan" button** — caused by a `.sched-empty .dashicons { font-size: 48px }` rule leaking into nested buttons. Scoped to direct-child selector (`.sched-empty > .dashicons`) so the central hero icon keeps its size while nested buttons render normally.
* UI: **Third-party admin notices (LiteSpeed "Purged all caches", cache plugin banners, etc.) are now suppressed on the Scheduler page.** They duplicate the feedback our own toast system already delivers and pushed the header off-screen. LuwiPress's own notices can opt in via the `.luwipress-keep-notice` class.

= 3.1.21 — Knowledge Graph gamification: Next Wins + Achievements + Activity feed =
* NEW: **Action Queue ("Next wins")** — three ranked suggestion cards at the top of the Knowledge Graph page answer the question "where should I click first?" Candidates are scored by impact ÷ effort (affected_count × metric_weight divided by estimated minutes): worst-covered category SEO, language closest to 100% translation, taxonomy terms missing translation, media alt-text gaps, and top-opportunity single product. Each card has a tier stripe (high/medium/low) and a one-click CTA that either opens the relevant detail panel or fires the action directly.
* NEW: **Achievement badges** in the Store Health hero. Bronze → Silver → Gold → Platinum milestones light up automatically when coverage thresholds cross: "SEO > 80%", "Enrichment > 95%", "All languages 100%", "100+ products", "500+ products", etc. Top grand-slam "💎 Grand slam" badge fires when SEO + enrichment + translation + taxonomy all hit ≥95%. Dedupe keeps only the highest tier per category so the row stays clean.
* NEW: **Activity feed** below the graph canvas. Pulls the last 25 log entries from `/logs` (enrichment completions, translation batches, AEO jobs, errors) and auto-refreshes every 30s while the tab is visible. Relative timestamps (`2m ago`, `1h ago`), colour-coded level badges (error/warning/success/info), and an instant "+N new" flash when fresh entries land between polls. Action queue + category batch firings trigger an immediate poll so operators see their work reflected without waiting.

= 3.1.20 — Knowledge Graph dashboard upgrade: Store Health hero + clickable stats + weekly trend =
* NEW: **Store Health hero banner** at the top of the Knowledge Graph page. Single-number weighted average across SEO / enrichment / translation / taxonomy / design / media / plugin health, colour-coded (red / amber / green) with a qualitative description ("your store is in solid shape" vs "significant gaps"). Breakdown chips below surface the weakest dimensions first so the operator knows where one click gives the biggest lift.
* NEW: **Weekly progress trend.** A rolling 30-day snapshot of core coverage numbers is captured on every KG refresh (once per day, stored in `luwipress_kg_summary_history`). The hero subtitle shows the last-7-days delta — `+3.2% SEO, +1.5% enriched, -240 opportunity pts` — so operators see their work actually moving the needle.
* NEW: **Top stat cards are now drill filters.** Products / Posts / SEO Coverage / Enriched / Opportunities cards were purely decorative — now each one switches the graph view and applies the matching preset in one click (Opportunities → Top wins; SEO Coverage → Needs SEO; Enriched → Needs enrich; Products → show all products; Posts → show all posts). Visual pulse on click confirms the filter applied.
* IMPROVED: **Stat bar fits on 1366px screens.** The 10-card grid was overflowing on typical admin widths once Media Health was added; minimum column width dropped from 108→96px so every card renders in a single row at 1366+ and wraps gracefully on narrower screens.
* IMPROVED: `summary.trend` is now part of the standard KG payload — callers (remote dashboards, WebMCP agents) can consume the 7-day delta without a second call.

= 3.1.19 — Scheduler confirm modal + KG gamification tightening =
* UI: **Native `window.confirm()` dialogs replaced with a stylized confirm modal** throughout the scheduler (delete item, bulk delete/retry/publish, regenerate outline, delete recurring plan, run pending now). Variant-aware — destructive actions get a red icon + red primary button; info/warning get amber. Matches the rest of the scheduler's visual language.
* A11Y: **Focus trap in all three modals** (outline review, recurring plan, new confirm). Tab/Shift-Tab now cycles within the modal instead of leaking to the page behind. When modal closes, focus returns to whichever element originally opened it.
* UX: **Wizard step sidebar flashes red on validation error.** When you click Next on Step 1 with an empty topics textarea (or on Step 3 without a date), the offending step number in the sidebar briefly pulses with a red halo so you can see exactly where you got stuck — the field still highlights too, but the sidebar hint is the new signal.
* UX: Escape key now closes whichever modal is topmost (confirm > outline > plan) instead of all simultaneously — no more accidental data loss when you hit Esc on a confirm dialog stacked over an outline edit.
* UX: "Generate ideas" button in brainstorm panel is now i18n-wired (was hardcoded English in two spots). New keys added: `lbl_confirm`, `lbl_confirm_delete`, `lbl_cancel`, `lbl_generate_ideas`, plus confirm modal titles.
* NEW: **Knowledge Graph — Media Health card.** New clickable stat card surfaces the total image count, missing-alt-text count, orphaned (detached) files, and the top 5 largest files. Panel health bar tracks alt-text coverage; recommendations link straight to the Media library with the right filter applied. Huge for SEO + accessibility audits that were previously invisible.
* NEW: **Knowledge Graph — Primary (source) language node.** The source language used to be invisible — only translation targets showed up. Now the source language renders as a "Source language — all products originate here" node so the Language view / Taxonomy heatmap / stats count it. Every language node now ships native + English names (Français / Italiano / Español / …) instead of just the two-letter code.
* FIX: **Knowledge Graph — single-product enrich / translate actions now show live progress.** Previously the button said "Queued" then the panel refreshed after 8 seconds showing no change (AI takes 30-90s). The single-product path is now routed through the batch endpoint so the floating progress monitor shows up, polls every 3 seconds, and reopens the same detail panel with fresh chips / health bar / opportunity counter the moment the job finishes. Fixes the gamification loop — operators now see their work reflected immediately.
* FIX: **Knowledge Graph — `?` shortcut help is now a proper panel.** Replaced the browser `alert()` dialog with a styled panel listing every shortcut with `<kbd>` chips plus a tips section pointing to the clickable stat cards.
* FIX: **Knowledge Graph — cache badge no longer shows decimal milliseconds.** `fresh (272.7ms)` now reads `fresh (273ms)`.

= 3.1.18 — Scheduler UI polish (scroll + bulk bar + brainstorm button) =
* FIX: Phantom vertical scrollbar on the main tab strip — caused by `overflow-x: auto` allowing vertical overflow too. Locked to `overflow-y: hidden` and hid the horizontal scrollbar visually (still scrollable via touch/wheel).
* FIX: Bulk selection toolbar was staying visible at page load when no rows were selected. WordPress admin stylesheet leaks `display: flex` that was overriding the HTML `hidden` attribute. Added explicit `[hidden] { display: none !important }` scoped to the scheduler shell.
* UI: "Brainstorm with AI" button now has explicit styling (subtle primary-tinted border, matching icon color, proper height) instead of inheriting muddy WP admin button defaults. Hover gives a gentle primary wash.

= 3.1.17 — Content Scheduler UX refresh + CRM segment classifier fix =
* FIX: **Customer segments were collapsing into `one_time` on most stores.** The classifier had an override that forced every 1-order customer to `one_time` regardless of recency — which silently hid `active`, `at_risk`, `dormant`, and `lost` cohorts on stores where most customers only buy once (normal for high-ticket / niche commerce). A 1-order customer is now segmented on recency first: recent → `active`, 3-6 months → `at_risk`, 6-12 months → `dormant`. Only customers past the `dormant_days` threshold (default 365) get relabelled as `one_time` — so the bucket actually means "bought once, never came back" instead of hiding every recent 1-order customer. Re-run segments via the new refresh endpoint below to reclassify existing customers.
* NEW: **`POST /crm/refresh-segments` endpoint.** Manually trigger a full segment recompute + lifecycle-event regeneration without waiting for the weekly cron. Useful after threshold changes, data imports, or classifier fixes like this one. Returns the new counts + execution time. Requires admin / token.
* IMPROVED: `POST /translation/batch` now advertises its optional `post_ids` whitelist argument in the route's `args` block (the handler has accepted it since 3.1.6, but it was undocumented). Scopes a batch to specific posts — used by the Knowledge Graph category panel's "Translate this category to X" action.
* FIX: **Knowledge Graph category panel — Recommendations weren't clickable.** "Improve SEO Coverage" / "AI Enrich Products" / "Translate to X" cards rendered as inert info cards while a separate "Batch Actions" section duplicated the same operations as real buttons. Recommendations are now the action buttons — one click on "AI Enrich Products" queues the whole category. Deduped to one button per action/language so you don't see three cards that do the same thing.
* UI: **Content Scheduler rebuilt around a hybrid tab layout** — three main tabs at the top (Queue · Plans · Create new) with URL-hash state, replacing the long vertical scroll where wizard, queue, and recurring plans competed for attention. Each tab only shows what it needs; the page no longer feels "crowded."
* UI: **Create wizard switched to a left-sidebar step layout** with descriptive hints under each step label ("What to write about", "Voice, depth, language", "Dates & cadence", "Confirm & queue"). On mobile the sidebar collapses to a horizontal step strip. Progress bar sits under the sidebar, always visible.
* UI: **Queue list redesigned** — status sub-tabs (Pending / Generating / Outline review / Ready / Published / Failed) sit above a denser, cleaner row list. Each row gets a circular status badge, title with 2-line clamp (no more overflow), metadata chips (mode / type / lang / depth / tone / words / date), and action buttons that collapse to a bottom strip on mobile.
* UI: **Bulk toolbar** now stands out with a primary-color band across the list when selections exist ("N selected"), high-contrast action buttons, and a disabled state on "Publish selected" when no drafts are selected — so you always know what's actionable.
* UI: **Recurring plans got their own tab** with a proper empty-state CTA, cleaner card layout, inline pause/resume/edit/delete. No longer buried beneath the queue.
* UI: **Depth cards (Standard / Deep / Editorial) and publish-mode cards** now use visual selection (border highlight + checkmark badge) instead of radio buttons. Multilingual language chips also become visual pills that turn primary-color-filled when selected.
* UX: **All native `alert()` / `confirm()` popups replaced with toast notifications** (leveraging the existing `luwipress_toast` system) so the scheduler speaks with one voice and respects site branding. Validation errors now highlight the offending field with a red border + focus ring, not just a fading notice.
* UX: **Smart polling** — while topics are generating, the page used to full-reload every 15s and wipe your modal edits + scroll position. Now it polls every 20s, pauses when the tab is hidden, and pauses when any modal is open. No more losing your outline edit mid-review.
* UX: **Full i18n coverage** — every scheduler string now goes through the WordPress translation system (`luwipress.i18n` map with 60+ keys) so the plugin can be localized for Envato buyers outside English.
* UX: Accessibility pass — both modals now have matching ARIA attributes (`aria-labelledby`, `aria-modal`, labeled close buttons). Wizard step sidebar items are keyboard-focusable and respond to Enter/Space. Single consistent responsive breakpoint system (960px tablet, 600px mobile) replacing the five-value mix.

= 3.1.16 — CRM segment classifier fix + chat header contrast + taller widget =
* FIX: **Customer segments were collapsing into `one_time` on most stores.** The classifier had an override that forced every 1-order customer to `one_time` regardless of recency — which silently hid `active`, `at_risk`, `dormant`, and `lost` cohorts on stores where most customers only buy once (normal for high-ticket / niche commerce). A 1-order customer is now segmented on recency first: recent → `active`, 3-6 months → `at_risk`, 6-12 months → `dormant`. Only customers past the `dormant_days` threshold (default 365) get relabelled as `one_time` — so the bucket actually means "bought once, never came back" instead of hiding every recent 1-order customer. Re-run segments via the new refresh endpoint below to reclassify existing customers.
* NEW: **`POST /crm/refresh-segments` endpoint.** Manually trigger a full segment recompute + lifecycle-event regeneration without waiting for the weekly cron. Useful after threshold changes, data imports, or classifier fixes like this one. Returns the new counts + execution time. Requires admin / token.
* IMPROVED: `POST /translation/batch` now advertises its optional `post_ids` whitelist argument in the route's `args` block (the handler has accepted it since 3.1.6, but it was undocumented). Scopes a batch to specific posts — used by the Knowledge Graph category panel's "Translate this category to X" action.
* FIX: **Knowledge Graph category panel — Recommendations weren't clickable.** "Improve SEO Coverage" / "AI Enrich Products" / "Translate to X" cards rendered as inert info cards while a separate "Batch Actions" section duplicated the same operations as real buttons. Recommendations are now the action buttons — one click on "AI Enrich Products" queues the whole category. Deduped to one button per action/language so you don't see three cards that do the same thing.
* UI: WhatsApp icon button in the chat header now has a 2.5px white border and uses a white pulse ring instead of green. The plain green ring was invisible on red / dark-primary headers — the white halo now reads against any brand color. Button bumped 32→38px, inner WhatsApp glyph 16→18px. Hover adds a thicker white glow.
* UI: The "online" status dot next to the subtitle also gained a white outline + white pulse for the same contrast reason — it was fading into the red gradient.
* UI: Close button matched the WhatsApp button size (30→34px), glyph 18→20px, and the gap between them widened slightly.
* UI: Chat widget feels noticeably more generous — window width 360→390px, max-height 520→720px, body min-height 240→420px and max 340→540px. Earlier pass over-minimized the message area; this restores breathing room for 5–6 turns before scroll kicks in.

= 3.1.15 — Customer chat UI polish =
* UI: Removed the bottom "Prefer WhatsApp?" CTA bar — it crowded the chat area and duplicated intent. The WhatsApp escalation now lives solely as a compact icon-only circle in the header (32px, official brand green, breathing pulse) next to the close button. Minimal footprint, still obvious.
* UI: Chat window refresh — wider (360px), softer 16px corner radius, layered shadow. Header got a subtle gradient and a live "online" dot next to the subtitle so customers feel the assistant is present. Body background is a gentle two-stop off-white so message bubbles pop without fighting for attention.
* UI: Message bubbles — gradient on customer messages, tinted shadow matching the brand color, slightly larger radius (16px) with a 4px "tail" corner, and a crisp 1px border for assistant bubbles so white-on-white doesn't melt into the body. Font bumped to 13.5px, line-height tightened.
* UI: Input area — larger pill input (22px radius) with a soft focus ring instead of hard border, placeholder color softened, and the send button now lifts on hover with a brand-tinted shadow.
* UI: Toggle pill (the entry point before the window opens) also got the gradient + lifted shadow treatment.

= 3.1.14 — Content Scheduler Overhaul (Wizard + Draft-First + Outline Approval + Recurring) =
* NEW: **Content Scheduler wizard.** The Scheduler page is now a 4-step flow: Topics → Style → Schedule → Review. Forward/back navigation with a progress bar, step-jump for completed steps, inline validation. The previous "New Content" + "Bulk Queue" forms are consolidated — the wizard always uses the bulk endpoint and handles 1-50 topics uniformly.
* NEW: **Draft-first publish workflow.** Step 3 choice: "Save as draft for review" (new default) or "Auto-publish on schedule". Draft mode creates the WP post as a reviewable draft the moment AI generation completes (target publish date baked into `post_date`), with a primary "Review & publish" button on each queue row that jumps straight to the WP editor.
* NEW: **Two-phase outline approval for deep / editorial depth.** Enable "Review outline before writing" in Step 2 and deep/editorial articles enter a new `outline_pending` status: AI drafts a structured outline (title, hook, sections with bullet points, FAQ, closing approach), the editor opens a modal to add / remove / reorder / edit sections, and only then does Phase 2 write the full article — strictly following the approved outline. Regenerate / reject paths built in.
* NEW: **Brand voice card.** Site-level `luwipress_brand_voice_card` option plus a batch-level override textarea in Step 2. Brand voice layers on top of the depth rules (audience profile, forbidden openers, preferred opening style, cultural context, banned terms). Admins can promote a batch voice to the site default in one click. Custom system prompts can reference it via the `{brand_voice}` token.
* NEW: **AI topic brainstorm.** Button on Step 1 opens an inline panel — AI proposes specific publishable titles from a theme you supply, filtered against the last 30 post titles so no duplicates leak in. Brand-voice aware. Each suggestion returns a recommended depth tier; picks drop into the textarea with per-topic override syntax already applied.
* NEW: **Per-topic pipe overrides.** Inline syntax `Topic | keywords | depth=editorial | words=3000 | tone=creative | image=0 | lang=tr | type=post` lets individual rows override the batch defaults without leaving the textarea. Legacy `Topic | keyword` syntax preserved.
* NEW: **Budget preview in the Review step.** Live cost estimate using the real provider / model pricing tables (per-topic input + output tokens × batch size, plus optional image cost). Scales automatically with multilingual duplicate, so 12 topics × 4 languages shows the true 48-article cost.
* NEW: **Enrich draft.** One-click button on draft rows runs Internal Linker (resolves `[INTERNAL_LINK: anchor]` markers into real anchors) and AI taxonomy suggestion (picks from EXISTING categories/tags only — never invents new terms).
* NEW: **Bulk approve & publish toolbar.** Per-row checkboxes + select-all in the queue reveal a sticky toolbar: Publish selected (drafts only), Retry, Delete. 12 drafts → 1 click.
* NEW: **Queue filter tabs + Failed retry.** All / Pending / Generating / Outline review / Ready / Published / Failed — one-click scoping. Failed rows get a Retry button that clears the error, flips the row back to pending, and re-queues AI 30 seconds out. No more delete-and-re-add.
* NEW: **Multilingual duplicate.** If WPML or Polylang is active, Step 2 exposes chip selectors for the site's active languages. Each picked language gets its own natively-written article (not a machine translation) linked to the primary via the translation plugin's API. A single 12-topic batch can become 48 linked articles across 4 languages without extra setup.
* NEW: **Recurring plans.** New admin card: define a theme + cadence (daily / weekly / biweekly / monthly) + post count + depth + language + publish mode, and LuwiPress auto-brainstorms and queues fresh topics on your schedule. Budget-aware (defers when the daily cap hits), pause / resume / edit / delete per plan. The editorial calendar fills itself.
* NEW: **6 new AJAX endpoints** power the overhaul: `retry_schedule`, `estimate_batch_cost`, `enrich_schedule_draft`, `bulk_schedule_action`, `get_outline` / `save_outline` / `regenerate_outline`, `brainstorm_topics`, `save_recurring_plan` / `delete_recurring_plan` / `toggle_recurring_plan`.
* IMPROVED: Status model now includes `outline_pending` alongside `pending`, `generating`, `ready`, `published`, `failed`. Status cards + queue filters updated to match.
* IMPROVED: Scheduler queue row now shows a mode badge (DRAFT / AUTO), depth badge, language, tone, target words, and the scheduled publish date — all at a glance.
* FIX: Customer chat occasionally answered "I don't have specific information about our products" even for simple queries ("what do you sell?", "im looking for caglama"). Root cause was a chain of edge cases: the Knowledge Graph transient cache could store an empty snapshot (e.g. if the handler errored during warm-up) and `false !== $data` happily returned that empty array for a full hour; KG product nodes also weren't matched by slug, so a customer typing the slug ("caglama") missed products whose displayed titles used localised forms ("Çağlama Box — Kutu Saz"). `get_kg_data()` now treats cached-but-empty as a miss, a new `synthesize_fallback_snapshot()` builds a minimal KG-shaped blob directly from WP/WC (top 20 categories + 20 recent products) whenever the KG handler returns nothing, `search_products_from_kg()` now also scores against product slugs (weight 2), and the system prompt explicitly forbids the "no info" cop-out — if nothing matches, the AI must surface 3–5 relevant categories with a one-liner pitch each.

= 3.1.13 — Knowledge Graph gamification + Customer Chat WhatsApp CTA =
* NEW: **Customer chat WhatsApp CTA.** The ambiguous phone-icon button in the chat header has been replaced with a branded green "WhatsApp" pill (icon + label + subtle pulse). A persistent CTA bar sits above the text input: "Prefer WhatsApp? Talk to our team directly." with a proper green WhatsApp brand button. Conversion-focused: the shortest path to a human conversation is always visible, not discoverable. Gracefully hides when no WhatsApp number is configured.
* NEW: **Auto zoom-fit on the Knowledge Graph** once the force simulation settles. Previously, tabs with few nodes (Customers: 8 segments) or widely-dispersed nodes would leave half the graph off-screen until the user panned — now everything fits the viewport automatically. The zoom-fit button also now fits to node bounds instead of resetting to identity.
* NEW: **Every KG recommendation is clickable.** Posts, Pages, and Customer Segments previously rendered recommendations as inert `<div>` elements — visually shown but not actionable, breaking the "click to improve" gamification loop. Now each rec is either an AI action (translate, enrich), an editor link (opens the WP post/page editor in a new tab), or a concrete action (CSV export for segment outreach).
* NEW: **Segment CSV export.** Click "Launch win-back campaign" / "Send onboarding sequence" / "Re-engagement email" on any customer segment and the full cohort (customer_id, name, email, order_count, total_spent, last_order, days_since_last_order, segment) downloads as a BOM-prefixed UTF-8 CSV — ready to paste into Mailchimp, Klaviyo, FluentCRM, or any email tool. LuwiPress never writes to third-party CRM plugins — this is the operator's handoff point.
* NEW: **Preset counts on the dropdown.** Each preset option now shows how many nodes it matches in parentheses — "Needs SEO (80)", "Not enriched (102)", "Thin content (3)", "Translation backlog (40)", "High opportunity (100)" — so operators can triage at a glance without committing to a filter.
* FIX: Preset/Export dropdowns on the Knowledge Graph admin page actually close now when a peer opens. Root cause was CSS, not JS — the `.kg-dropdown-menu { display: flex }` rule has the same specificity as the user-agent's `[hidden] { display: none }`, so flex won the cascade and kept the menu visible even after JS set `hidden=true`. Added `.kg-dropdown-menu[hidden] { display: none }` (specificity 0,2,0) so the hide intent is honored.
* IMPROVED: Stats bar sized down — nine metric cards now fit on a single row at standard admin widths instead of wrapping to a second row. Graph canvas sits directly below without being pushed below the fold.
* IMPROVED: Global KG search now covers Pages and Customer Segments alongside Products / Posts / Categories.
* IMPROVED: Hover tooltips extended to Post / Page / Customer-Segment nodes — Post shows word count + author + SEO status; Page shows role + content length + template; Segment shows customer count + share %.

= 3.1.12 — Knowledge Graph gamification loop =
* NEW: **Auto zoom-fit** once the force simulation settles. Previously, tabs with few nodes (Customers: 8 segments) or widely-dispersed nodes would leave half the graph off-screen until the user panned — now everything fits the viewport automatically. The zoom-fit button also now fits to node bounds instead of resetting to identity, which is far more useful.
* NEW: **Every recommendation is clickable.** Posts, Pages, and Customer Segments previously rendered recommendations as inert `<div>` elements — visually shown but not actionable, breaking the "click to improve" gamification loop. Now each rec is either an AI action (translate, enrich), an editor link (opens the WP post/page editor in a new tab for SEO meta / featured image / content expansion), or a concrete action (CSV export for segment outreach). Post "Add SEO Meta / Featured Image / Expand Content / Refresh Content", Page "Expand content / Orphan page", and all Segment recommendations now drive the user somewhere.
* NEW: **Segment CSV export.** Click "Launch win-back campaign" / "Send onboarding sequence" / "Re-engagement email" on any customer segment and the full cohort (customer_id, name, email, order_count, total_spent, last_order, days_since_last_order, segment) downloads as a BOM-prefixed UTF-8 CSV — ready to paste into Mailchimp, Klaviyo, FluentCRM, or any email tool. LuwiPress never writes to third-party CRM plugins — this is the operator's handoff point.
* NEW: **Preset counts on the dropdown.** Each preset option now shows how many nodes it matches in parentheses — "Needs SEO (80)", "Not enriched (102)", "Thin content (3)", "Translation backlog (40)", "High opportunity (100)", "All items (185)" — so operators can triage at a glance without committing to a filter.

= 3.1.11 — Knowledge Graph polish =
* FIX: Preset/Export dropdowns on the Knowledge Graph admin page actually close now when a peer opens. Root cause was CSS, not JS — the `.kg-dropdown-menu { display: flex }` rule has the same specificity as the user-agent's `[hidden] { display: none }`, so flex won the cascade and kept the menu visible even after JS set `hidden=true`. Added `.kg-dropdown-menu[hidden] { display: none }` (specificity 0,2,0) so the hide intent is honored.
* IMPROVED: Stats bar sized down — nine metric cards now fit on a single row at standard admin widths instead of wrapping to a second row (min card width 140→108px, padding/font tightened). Graph canvas sits directly below without being pushed below the fold.
* IMPROVED: Global search now covers Pages and Customer Segments alongside Products / Posts / Categories. Typing in the header search pulls up any content type with its role (Homepage, Shop, Blog, Top-level) or cohort (VIP, at-risk, etc.).
* IMPROVED: Hover tooltips extended to Post / Page / Customer-Segment nodes — Post shows word count + author + SEO status; Page shows role + content length + template; Segment shows customer count + share %.

= 3.1.10 — Content Depth Presets + Dropdown Hotfix =
* NEW: Content Scheduler "Content depth" preset on the bulk queue form — three levels:
  - **Standard**: balanced SEO article (800-1500 words, clear structure).
  - **Deep**: long-form explainer with research framing, examples, citations, counter-arguments, "Key takeaways", and a 3-5 question FAQ (1500-3000 words).
  - **Editorial**: essay-style with strong voice, cultural/historical context, narrative arc, original perspective, quote-worthy sentences, memorable closing line (2000-3500+ words).
  `max_tokens` scales automatically (4096 → 6000 → 8000).
* NEW: Rewritten default content system prompt — stricter anti-filler rules ("no 'in today's fast-paced world' openings"), concrete-over-vague mandate, internal link placeholder syntax, no-markdown-fence JSON output.
* NEW: Operator-level custom system prompt support — set `luwipress_content_system_prompt` option to override the default entirely. Variable substitution available: `{topic}`, `{language}`, `{tone}`, `{word_count}`, `{keywords}`, `{site_name}`, `{depth}`.
* FIX: Knowledge Graph dropdowns still occasionally stayed open in pairs — strengthened the sibling-close logic to walk `.kg-dropdown` roots (not just menus), flip both `menu.hidden` and the sibling's `aria-expanded` in one pass.

= 3.1.9 — Content Scheduler Bulk Queue + KG Dropdown Fix =
* NEW: Content Scheduler → "Bulk Queue" section. Paste up to 50 topics (one per line) with optional `| keywords` pipe syntax on each line. Set a start date, a publish spacing (e.g. "1 day" or "6 hours"), an AI stagger interval (default: 5 minutes between generation runs so you don't burst your AI budget), shared tone / word count / language / post type, and the whole batch is queued in one click. Each topic becomes an individual schedule row with its own `wp_schedule_single_event` worker, so AI calls are spread out automatically. A "Run N pending now" button lets operators kick the queue forward without waiting for wp-cron.
* NEW: Budget-aware deferral — if the daily AI budget is exhausted when a scheduled generation fires, the task automatically reschedules itself for 1 hour later instead of failing. Operators can raise the cap and the queue picks up from where it left off.
* NEW: `luwipress_generate_single` cron hook — single-item AI generation handler reused by the bulk queue and available for any third-party code that wants to queue content from its own context.
* FIX: Knowledge Graph Preset/Export dropdowns — when one dropdown was open and the other was clicked, the first one wouldn't close. `e.stopPropagation()` was blocking the document-level outside-click listener used by sibling dropdowns. Now only one dropdown is ever open; a new opener explicitly closes its peers.

= 3.1.8 — Dead Code Removal (n8n residue cleanup) =
* CLEANUP: Removed the `LuwiPress_AI_Engine::MODE_N8N` constant and the `forward_to_n8n()` stub entirely. These had been deprecated since 2.0.1 (n8n integration removed, all AI handled natively) but were kept as stubs for backward compatibility. They were unreachable in practice — `get_mode()` always returned `'local'` — so removing them has no runtime effect.
* CLEANUP: Five dead `if ( MODE_N8N === $mode )` branches in AEO generation, single-product enrichment, batch enrichment, per-post translation, and taxonomy translation call sites are gone. Each had lived alongside the real local-AI path since 2.0.1.
* CLEANUP: `LuwiPress_AI_Engine::get_mode()` and the `MODE_LOCAL` constant also removed — no callers left in the core plugin.
* CLEANUP: `luwipress_processing_mode` option is no longer written on save or read on load. `luwipress_seo_webhook_url` no longer surfaces in `/site-config` responses. The old Translation Manager "Webhook" engine badge was dropped — the badge now always reads "Local AI".
* CLEANUP: `LuwiPress_AI_Content::$webhook_url` and `$webhook_api_token` properties (only ever written, never read) removed.
* NO BREAKING CHANGE for end users: the affected code paths were already dead. If a third-party integration was still calling `forward_to_n8n()` or `get_mode()` directly, it would have been receiving a deprecation error / `'local'` constant anyway. File a ticket if you hit unexpected fatal errors — there's an easy shim to add back.

= 3.1.7 — Batch Monitor, CSV Round-trip, Layout Memory =
* NEW: Enrichment batch monitor — when you queue "Enrich all products in category" (or any `/product/enrich-batch` call), a floating progress panel appears in the bottom-right corner with a live striped progress bar, elapsed time, and per-status chips (queued / running / done / failed). Polls `/product/enrich-batch/status` every 3 seconds until complete, then refreshes the graph automatically.
* NEW: CSV round-trip for SEO meta — the Export dropdown now has an "Upload CSV → apply SEO meta" option. Export the "CSV — Opportunity list" or "CSV — Missing SEO" file, edit it offline (Excel, Sheets, any tool), then re-upload. The frontend parses the CSV (flexible column detection: `ID` / `post_id`, `SEO Title` / `Meta Title` / `Title`, `Meta Desc` / `Description`, `Focus Keyword`), previews the count, and dispatches the batch to the new `POST /seo/meta-bulk` endpoint. Up to 500 rows per request. Writes go through the detected SEO plugin (Rank Math / Yoast / AIOSEO / SEOPress) exactly as the single-row `/seo/meta` endpoint does.
* NEW: Knowledge Graph layout memory — when you drag a node to a preferred position, the position is pinned and saved to localStorage per view (Products / Posts / Pages / Customers). Reopen the graph and your layout is where you left it. A new reset button (↺) in the zoom controls clears the saved layout for the current view and re-flows the simulation.
* IMPROVED: Design Health stat card now reads "N/A" instead of "0%" on sites without Elementor installed. The card becomes non-interactive (no click-through, no hover cue) when the metric doesn't apply. Server-side `design_audit.elementor_available` flag surfaces this cleanly.
* IMPROVED: `/knowledge-graph` endpoint description updated to list `design_audit` as a valid section (it was always accepted by the handler, but the args description was missing it).

= 3.1.6 — Knowledge Graph Asset Split (performance) =
* IMPROVED: Knowledge Graph admin page is now served as a separate `assets/js/knowledge-graph.js` (~93 KB) + `assets/css/knowledge-graph.css` (~26 KB) bundle. These load only on the Knowledge Graph page, not on every LuwiPress admin screen. Page templates drop from 2,466 lines to 174 lines; `admin.css` drops 1,000+ lines (~22% smaller) for every non-KG admin page load. Config (REST URL, API token, nonce) is injected via `wp_localize_script` as `window.lpKgConfig` — no more inline PHP-generated JSON.
* NO BREAKING CHANGE: no functional or behaviour change — refactor only. The KG page behaves exactly like 3.1.5.

= 3.1.5 — Customers & Elementor Audit Drill-down =
* NEW: Knowledge Graph fourth view — **Customers**. Shows eight customer segments (VIP, Loyal, Active, New, One-Time, At Risk, Dormant, Lost) as color-coded nodes sized by cohort count. Each segment has its own detail panel with segment definition, priority action, and targeted recommendations (e.g. win-back campaign for One-Time buyers, reactivation offer for Dormant, VIP perk program for VIPs). Keyboard shortcut `4` added for quick switch.
* NEW: Design Audit drill-down — click any page row in the Design Health panel to open a dedicated Elementor audit view for that page. Issues are grouped by severity (critical/warning/info) and then by issue type, with affected element IDs listed as code chips. "Open in Elementor" and "View live" buttons jump you straight to the editor or the rendered page.
* IMPROVED: Design Health panel's per-page results are now scannable summaries (severity counts instead of full issue dumps). The deep list lives in the drill-down where you can act on it.
* IMPROVED: Knowledge Graph fetch now includes the `crm` section so customer segment data is available without a second request.
* IMPROVED: Keyboard shortcut help (`?` dialog) updated to include the fourth view.

= 3.1.4 — Knowledge Graph Overhaul =
* NEW: Knowledge Graph search — typeahead over products, posts, and categories. Keyboard: `/` to focus, `↑↓` to navigate, Enter to select. Selected node auto-zooms and opens its detail panel.
* NEW: Knowledge Graph presets — filter dropdown with six built-in views: All items, Needs SEO meta, Not enriched, Thin content, Translation backlog, High opportunity (score > 30). Active preset shown as a badge.
* NEW: Knowledge Graph export — four formats: CSV opportunity list (opportunity_score desc), CSV missing SEO meta (with Edit URL column), JSON raw graph, PNG viewport snapshot. All with UTF-8 BOM for spreadsheet compatibility.
* NEW: Pages view — third tab in the graph; parent-child hierarchy with `child_of` edges. Homepage, shop, blog, top-level, and child pages get distinct colours and sizes. Page detail panel shows role badge, template, content length, and recommendations (thin content, orphan pages).
* NEW: Order Analytics stat card — click to open a Revenue panel with today/7-day/30-day revenue snapshot, AOV, 12-month SVG sparkline, customer retention health bar, top 5 sellers, inventory status, payment method breakdown, and refunds (last 90 days).
* NEW: Plugin Health stat card — readiness score plus per-category detected plugins (SEO, translation, email, CRM, cache, support) and prioritised recommendations.
* NEW: Taxonomy Coverage heatmap — click the new stat card to see a matrix of taxonomy type × language with colour-coded coverage (≥95% green, 70-94% orange, 40-69% dark, <40% red). Missing terms listed per language with a "Translate all" button that dispatches one request per taxonomy type.
* NEW: Category batch actions — from any category detail panel, queue every product in that category for enrichment or translation to a specific language. Frontend collects product IDs from the cached graph; backend `/translation/batch` now accepts an optional `post_ids` whitelist parameter.
* NEW: Cache badge in the graph header — shows whether the current data was served from cache (`cached`) or freshly computed (`fresh (Nms)`). The Refresh button bypasses cache; normal page loads now hit the cache reliably.
* NEW: Keyboard shortcuts — `/` search, `r` refresh, `1/2/3` view switch (Products/Posts/Pages), `Esc` close detail panel, `?` show shortcut help.
* IMPROVED: Knowledge Graph force simulation now adapts to node count (alphaDecay, collision padding, charge strength) so large catalogues (1000+ products) converge in a few seconds instead of 10+.
* IMPROVED: Cache invalidation is now triggered when LuwiPress or SEO-plugin meta keys change, not only on `save_post`. Async AI enrichment writes no longer leave stale graph data.
* IMPROVED: `GET /knowledge-graph` accepts both `section` and `sections` parameters. Previous admin requests silently loaded all 20 sections because of a parameter mismatch.
* FIX: Recommendation action buttons on the Knowledge Graph detail panel were silently failing — the refresh callback referenced functions that lived in an inner scope. The action buttons (Enrich, FAQ, HowTo, Translate) now execute reliably and the panel re-opens with fresh data.
* FIX: WebMCP companion status on Settings → Connection tab now reflects the companion's own default (`enabled` when the option has not been persisted yet). Previously the admin UI showed "Disabled" even though the MCP endpoint was live.
* FIX: Dashboard header "Open Claw AI" button replaced with Knowledge Graph shortcut — the Open Claw page was split out in 3.1.0 and the old button led nowhere. OpenAI-Compatible provider now recognised by the AI-key detector and provider label map, so 3.1.2 users no longer see "No AI key" when only that provider is configured. Plugin pills deduplicated across Google Ads / Meta Ads / Product Feed categories — a single plugin covering two categories (e.g. Google Listings & Ads) now shows once.
* CLEANUP: Legacy `n8nPress` / `n8n workflow` references removed from all user-facing surfaces (admin UI, plugin metadata, LICENSE, readme). Internal identifiers also renamed (`$n8n_webhook_url` → `$webhook_url`, `check_n8n_token()` → `check_api_token()`). No breaking change — `MODE_N8N` and `forward_to_n8n()` kept as deprecated stubs for backward compatibility.

= 3.1.3 — Primary Language Dropdown Uses Detected Languages =
* IMPROVED: Settings → AI Content → Primary Language now reads the active languages from WPML, Polylang, or TranslatePress when one is installed, instead of the old 11-language hardcoded list. You can only pick a language your site actually serves, removing a foot-gun where Tapadum-style sites had `tr` selected while the site ran on EN+FR+IT+ES.
* NEW: Badge above the dropdown shows which translation plugin was detected and how many languages it exposes. Default language from the translation plugin is surfaced as a hint so the admin knows what the source-of-truth is.
* NEW: Save handler validates the submitted language against the detected active list; an invalid submission snaps to the translation plugin's default language instead of silently persisting a mismatched value.
* NEW: When a previously-saved language is no longer active (e.g. site disabled a language in WPML), a warning line appears inline on the dropdown so admins can fix it explicitly.
* FALLBACK: If no translation plugin is present, the original list (11 common locales) still ships as the fallback. No breaking change for single-language sites.

= 3.1.2 — Multi-Provider Expansion (OpenAI-Compatible) =
* NEW: Fourth AI provider slot — **OpenAI-Compatible** — a single provider class that talks to any vendor exposing OpenAI's `/chat/completions` schema. Ships with presets for **DeepSeek**, **Moonshot Kimi**, **Groq**, and **Together.ai**, plus a **Custom** preset for self-hosted LLMs (Ollama, vLLM, LM Studio, text-generation-webui).
* NEW: Settings → AI Content now exposes the OpenAI-Compatible card with preset dropdown, API key, optional custom base URL, and model selector. Switching preset updates the base URL and model list instantly. Off-peak discount hints (e.g. DeepSeek's ~50% discount window 16:30–00:30 UTC) are shown under the model picker.
* IMPROVED: Fallback chain extended to include `openai-compatible` — if the primary provider fails transiently (429, 5xx, network, 404-on-model), the engine now considers the OpenAI-Compatible provider too.
* IMPROVED: `/site-config` response masks the OpenAI-Compatible API key with the same last-4-char hint pattern introduced in 3.1.1. No breaking change to other secret fields.
* NO BREAKING CHANGE: Existing OpenAI, Anthropic, and Google configurations work unchanged. Provider interface is unchanged — third-party code calling `LuwiPress_AI_Engine::dispatch()` needs no updates. Adding a new OpenAI-compatible vendor in the future is a one-line preset addition, no new class required.

= 3.1.1 — Security: Secret Masking in Site Config =
* SECURITY: `GET /site-config` no longer returns the LuwiPress API token or the active AI provider's API key in clear text. The response now exposes `api_token_configured` / `api_key_configured` booleans plus `*_hint` fields showing only the last 4 characters, which is sufficient to verify configuration without leaking the full secret. Any existing tokens/keys that may have been surfaced to third parties (automation logs, MCP transcripts, remote clients) should be rotated.
* NO BREAKING CHANGE to other fields — `site`, `woocommerce`, `plugins`, and the non-secret `luwipress.*` fields are unchanged. Consumers that previously read `luwipress.api_token` or `luwipress.ai.api_key` must switch to the `_configured` boolean; the full secret was never intended to be consumed by external clients.

= 3.1.0 — Open Claw Companion Split =
* BREAKING: The Open Claw admin AI assistant has moved to a separate companion plugin (`luwipress-open-claw`). Sites that use Open Claw must install the companion to keep the LuwiPress → Open Claw menu working. Conversation history and all settings are preserved.
* NEW: Migration notice — admin users on sites without the companion see a dismissable banner pointing them to the plugin.
* NEW: Core dropped ~26 KB / 500 LOC. Core is now focused on WooCommerce AI automation; admin chat is opt-in via companion.
* CLEANUP: Settings tab "Open Claw" removed (was already reduced to a info card pointing at the admin page — now that page moves too).
* UI: Settings → Advertising tab removed (all Google Ads / Meta Ads option fields were dead — no code ever consumed them).
* UI: Settings → CRM info message sadeleşti; FluentCRM/Mailchimp mention removed.
* UI: Open Claw admin sidebar no longer references Telegram/WhatsApp channel status (those channels were removed in the 2.0.0 slim rewrite).

= 3.0.0 — Companion Plugin Split (breaking) =
* BREAKING: WebMCP has moved to a separate companion plugin (`luwipress-webmcp`). Stores that relied on the MCP endpoint must install the companion to keep AI-agent integration working. `luwipress_webmcp_enabled` + `luwipress_webmcp_allowed_origins` options are preserved across the split.
* NEW: Core plugin dropped ~211 KB / 4,172 LOC. Core is now focused on WooCommerce AI automation; MCP is opt-in via companion.
* NEW: One-time migration notice — admin users on sites that had WebMCP enabled see a dismissable banner pointing them to the companion plugin.
* NEW: `translation_batch` MCP tool (mirrors `POST /translation/batch`) and 4 module-settings MCP tool pairs (`enrich_settings_*`, `translation_settings_*`, `chat_settings_*`, `schedule_settings_*`). Ships with the companion.
* NEW: `cache_purge` MCP tool (mirrors `POST /cache/purge`). Ships with the companion.
* REFACTOR: Module settings methods unified across modules — `AI_Content`, `Translation`, `Customer_Chat`, `Content_Scheduler` all expose `handle_get_settings()` and `handle_set_settings()` now. Makes future automation uniform.

= 2.1.0 — SEO Writer Fallback & Batch Translation =
* NEW: Standalone SEO writer fallback — when no third-party SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress) is detected, LuwiPress now writes its own `_luwipress_seo_title`/`_description`/`_focus_keyword` meta and outputs `<title>` + `<meta name="description">` in `wp_head`. Removes the hidden hard-requirement on a third-party SEO plugin for stores that want LuwiPress to own their SEO metadata end-to-end.
* MERGE: Hreflang module folded into the SEO writer (`class-luwipress-hreflang.php` removed; logic moved to `class-luwipress-seo-writer.php`). Single responsibility, same behaviour — mode `auto`/`always`/`never` preserved.
* NEW: `POST /translation/batch` — translate N untranslated posts for one or more target languages in a single call. Fixes the dead "Translate N missing products" button on language nodes in the Knowledge Graph.

= 2.0.11 — Plugin Slimming =
* SLIM: Theme Manager + Demo Import modules removed from plugin (63 KB / 2,082 lines / 5+ endpoints). Theme setup now lives in the themes themselves.
* SLIM: CRM Bridge — FluentCRM/Mailchimp write paths removed. Segmentation and lifecycle events are now pure-WooCommerce; no third-party CRM plugins are written to, the plugin only reports on its own WooCommerce-derived data.
* NEW: Settings REST pattern — `GET/POST /translation/settings`, `/chat/settings`, `/schedule/settings` (partial-update, admin auth), mirroring the existing `/enrich/settings`. Remote automation (n8n, Claude Code, companion plugins) can now manage module configuration without SSH/WP-CLI.

= 2.0.10 — Translation & Enrichment Hardening =
* NEW: Enrichment prompt customization — Settings → AI tab now exposes a custom system prompt textarea with variable substitution (`{product_title}`, `{category}`, `{focus_keyword}`, `{price}`, `{currency}`, `{site_name}`, `{target_language}`) for store-specific output structure
* NEW: Enrichment constraints — configurable target word count, meta title max (40–80), meta description max (120–200), and optional CTA sentence auto-appended to every meta description
* NEW: `/product/enrich` `options` now accepts `custom_instructions`, `target_words`, `meta_title_max`, `meta_desc_max`, `focus_keyword` for per-request overrides
* NEW: `GET /enrich/settings` and `POST /enrich/settings` — read/write the enrichment prompt and constraints remotely (admin auth; `POST` is partial-update, only keys present in the body are touched)
* NEW: Callback-side meta trimming — AI-generated meta title/description are trimmed to configured limits (word-boundary aware) before being saved to the active SEO plugin
* FIX: Translation — when AI JSON response fails to parse, translation is now rejected and marked failed instead of writing raw payload text into post_content (prevents corrupted product descriptions)
* FIX: Translation — added `source_language` parameter to `/translation/request` so a clean sibling language can be used as source when the default-language copy is corrupted
* FIX: Translation — self-retranslate path: when the post being retranslated is already in the target language, content is now rewritten in place via `wp_update_post` + `clean_post_cache` instead of silently skipped
* NEW: Enrich — WPML language guard on `/product/enrich`, `/product/enrich-batch`, and enrich-callback endpoints. Enrichment writes default-language content; writes to translation copies are now rejected with `language_mismatch` (409). Set `allow_translation_target=true` in the callback body to override.
* NEW: Batch enrich now auto-skips translation copies with a warning log entry instead of queueing them
* NEW: `LuwiPress_Translation::get_post_wpml_language()` helper — centralised handling of WPML language detection (array/object/null response shapes)
* IMPROVED: Translation log line now includes source language override + resolved source post id for traceability

= 2.0.9 — Theme Library & Smart Setup =
* NEW: Theme Library — multi-theme catalog with install/activate/setup from plugin
* NEW: Theme Manager engine — remote ZIP install via Theme_Upgrader, one-click activate
* NEW: 4-step setup wizard — Requirements → Install → Activate → Setup Store
* NEW: 3 themes in catalog — Luwi Elementor (available), Luwi Minimal & Boutique (coming soon)
* NEW: Smart setup — WC store info auto-fills Contact page (email, phone, address)
* NEW: Color presets picker — Burnished Gold, Forest Green, Deep Navy, Dusty Rose
* NEW: Full production safety — snapshot before changes, one-click rollback to previous state
* NEW: Health check after setup — verifies site responds with HTTP 200
* NEW: WooCommerce page verification — Shop/Cart/Checkout/MyAccount auto-created if missing
* NEW: Checkpoint system — setup progress saved, resumable on failure
* NEW: Blog/Journal starter page added (7 pages total)
* NEW: Cleanup — trash starter pages + reset homepage/blog in one click
* IMPROVED: Demo content — Luwi palette, Playfair Display headings, Luwi widgets
* IMPROVED: Elementor guard — import disabled when Elementor not active
* IMPROVED: Confirmation dialogs before theme activation and page import

= 2.0.8 — Marketplace Integration =
* NEW: Multi-marketplace product publishing (Amazon, eBay, Trendyol, Hepsiburada, N11, Alibaba, Etsy, Walmart)
* NEW: Marketplace adapter pattern — interface-based, each marketplace is a separate adapter class
* NEW: REST API endpoints — publish, publish-batch, status, overview, test, categories (luwipress/v1/marketplace/*)
* NEW: Marketplace listings DB table with sync status tracking
* NEW: Settings tab — search-enabled grid UI with per-marketplace credentials and enable toggle
* NEW: Product data mapping — WooCommerce fields automatically mapped to each marketplace's format
* NEW: Design-system-aligned minimal card UI with autocomplete search, status badges (Live/Ready/Off)

= 2.0.6 — Rebrand Cleanup =
* REBRAND: All CSS variables renamed --n8n-* → --lp-*
* REBRAND: All HTML classes renamed n8np-* → lp-*
* REBRAND: JS functions and WebMCP client class renamed
* REBRAND: MCP resource URIs changed n8npress:// → luwipress://
* REBRAND: readme.txt rewritten — standalone AI plugin description
* REBRAND: Removed all n8n references from user-facing UI
* IMPROVED: Coverage calculation — only counts real translations linked to EN sources
* IMPROVED: Fix Orphans tool detects EN-registered non-English posts
* IMPROVED: MCP content tools bypass WPML language filter

= 2.0.7 — Customer Chat Widget + WhatsApp/Telegram Escalation =
* NEW: AI-powered customer chat widget (frontend, vanilla JS, <15KB)
* NEW: RAG pipeline — FAQ short-circuit, product search via Knowledge Graph, store policy injection
* NEW: Intent classification — product inquiry, shipping, returns, order status, stock check, escalation
* NEW: WhatsApp/Telegram escalation via deep links — customer connects directly with team
* NEW: Channel choice dialog when both WhatsApp and Telegram are configured
* NEW: Customer Chat settings tab — greeting, colors, position, policies, budget, rate limiting
* NEW: Separate daily AI budget for customer chat (independent from admin budget)
* NEW: Rate limiting per visitor (30 messages/hour default, configurable)
* NEW: Custom DB tables for conversations and messages (not wp_options)
* NEW: GDPR compliance — auto-cleanup after 90 days, delete customer data endpoint
* NEW: Session persistence via localStorage — conversation survives page navigation
* NEW: Order status lookup for logged-in WooCommerce customers
* NEW: Auto-escalation suggestion after configurable message count

= 2.0.5 — Full Site X-Ray & WordPress Control =
* NEW: Knowledge Graph — 8 new sections: posts, pages, taxonomies, media inventory, menus, product attributes, authors, order analytics
* NEW: 44 MCP tools for full WordPress control — users, orders, coupons, media, comments, settings, plugins, themes, menus, taxonomies, custom fields
* NEW: Blog post nodes with SEO status, categories, tags, author, staleness detection
* NEW: Page hierarchy nodes with template detection, parent/child relationships
* NEW: Media inventory — missing alt text count, orphaned media, file size analysis
* NEW: Navigation menu structure with full item hierarchy
* NEW: WooCommerce order analytics — 12-month revenue trend, repeat customer rate, payment methods, refund tracking
* NEW: Author nodes with per-type content counts and activity tracking
* NEW: Product attribute nodes with term listing
* NEW: Content taxonomy discovery — all registered taxonomies with top terms
* FIX: MCP translation_missing tool parameter mismatch (language → target_language)
* FIX: MCP translation_taxonomy_missing tool parameter mismatch (language → target_languages)
* IMPROVED: Cache invalidation broadened to save_post, delete_post, created_term, delete_term
* IMPROVED: Settings tools use whitelist — siteurl, home, admin_email blocked for security

= 2.0.4 — Translation Pipeline Hardening =
* SECURITY: SQL injection fix — parameterized IN() placeholders in wpdb->prepare()
* SECURITY: XSS fix — esc_html() on translated titles in AJAX responses
* SECURITY: Race condition fix — transient lock on WPML translation creation
* SECURITY: Nonce field added to Translation Manager form
* FIX: Fatal error — replaced new WP_Post() with get_post()
* FIX: Slug generation — translated posts now get sanitized slugs from translated title
* FIX: Excerpt extraction — blog listing excerpts from first text-editor widget
* FIX: WPML element_type dynamic (was hardcoded post_product for pages/posts)
* FIX: WooCommerce meta copy guarded to product post_type only
* FIX: Category taxonomy dynamic per post_type (was hardcoded product_cat)
* FIX: Cron exception handling — try-catch prevents stuck "translating" status
* NEW: Auto-snapshot before Elementor page translation (rollback support)
* NEW: WP Revision integration — pre-translation revision saved, auto-revert on failure
* NEW: Translation progress polling — AJAX endpoint for background job status
* NEW: JS poller in Translation Manager for Elementor background jobs
* NEW: Per-term taxonomy translation with live progress bar
* NEW: "Re-translate Broken" maintenance tool — fixes empty title / numeric slug posts
* IMPROVED: REST API accepts post_id param (backward compat product_id)
* IMPROVED: Response key items (backward compat products)

= 2.0.3 — Elementor Chunked Translation & KG Redesign =
* NEW: Elementor page translation with chunked AI (background WP Cron)
* NEW: ElementsKit heading widget support (ekit_heading_title, ekit_heading_extra_title)
* NEW: Knowledge Graph redesign — product health bar, category/language panels, hover tooltips
* FIX: Translation pipeline source language from WPML (was using target language option)
* FIX: Taxonomy translation source_language_code fix
* FIX: Grey overlay backdrop removed from KG sidebar panel

= 2.0.2 — Translation Manager & Elementor Tools =
* NEW: Translation Manager UI — step-based dashboard with coverage tracking
* NEW: 20+ Elementor WebMCP tools — translate, style, bulk-update, snapshot, rollback
* NEW: Elementor page outline endpoint for AI agents

= 2.0.1 — Standalone AI Engine =
* NEW: Multi-provider AI Engine (OpenAI, Anthropic, Google)
* NEW: Job Queue with WP Cron for async processing
* NEW: Token Tracker with budget enforcement
* REMOVED: n8n dependency — all AI calls are now native

= 2.0.0 — LuwiPress Rebrand =
* REBRAND: n8nPress → LuwiPress
* NEW: Standalone plugin architecture (no external dependencies)
* NEW: Plugin Detector — auto-detects SEO, Translation, SMTP, CRM plugins

= 1.10.0 — WebMCP: MCP Server for AI Agents =
* NEW: MCP Streamable HTTP server at /luwipress/v1/mcp (spec 2025-03-26)
* NEW: 40+ MCP tools across 14 categories (content, SEO, AEO, translation, CRM, email, etc.)
* NEW: MCP resources — site-config, health, AEO coverage + parameterized templates (post, seo-meta, translation-status)
* NEW: MCP tool annotations — readOnlyHint, destructiveHint, idempotentHint, openWorldHint
* NEW: MCP session management with UUID sessions and 1-hour TTL
* NEW: Origin validation (DNS rebinding protection per MCP spec)
* NEW: Cursor-based pagination for tools/list
* NEW: Argument autocompletion for resource templates (completion/complete)
* NEW: Knowledge Graph endpoint — AI-consumable entity graph with WPML-aware translation status
* NEW: WebMCP admin page with tool catalog, connection tester, and usage examples
* NEW: Browser-side MCP client class (webmcp-client.js)
* NEW: Extensibility hook — luwipress_webmcp_register_tools for third-party tools
* NEW: WEBMCP-INTEGRATION.md documentation for AI agent tool discovery
* IMPROVED: Site config permission now accepts logged-in admins (cookie + nonce auth)

= 1.9.0 — Security & Code Quality =
* SECURITY: JWT secret now uses random_bytes(32) instead of wp_generate_password (C2)
* SECURITY: Token revocation mechanism — revoke compromised JWT tokens by jti (C3)
* SECURITY: Image sideload validates MIME type (jpeg/png/webp/gif) and max size 10MB (C4)
* SECURITY: Admin pages check current_user_can('manage_options') on direct access (H4)
* SECURITY: Enrichment uses transient lock to prevent race conditions (H6)
* SECURITY: HMAC secret uses cryptographically secure random_bytes (C2)
* FIX: Meta copy uses whitelist (WC product fields only) instead of blacklist (H2)
* FIX: Daily auto-cleanup cron for logs, tokens, workflow stats (H3)
* FIX: Translation Manager taxonomy count excludes orphan WPML records
* FIX: Knowledge Graph default language uses get_locale() instead of hardcoded 'tr' (M5)

= 1.8.4 =
* FIX: Knowledge Graph now uses WPML icl_translations for translation status (was using meta only)
* FIX: Taxonomy translation WPML registration uses SQL fallback for REST context
* FIX: Taxonomy callback validates term existence before saving
* FIX: Removed orphan term fallback that caused false positives in taxonomy counts
* NEW: Test suite for translation system (tests/test-translation-system.php)

= 1.8.3 =
* NEW: AI image generation for products and posts (DALL-E 3, DALL-E 2, Gemini Imagen 3)
* NEW: Image provider setting in AI Content tab (with per-image pricing display)
* NEW: Gemini 2.5 Flash and Gemini 2.5 Pro model options
* NEW: Image provider and AI provider sent in _meta block to n8n workflows
* IMPROVED: Enrich callback auto-sideloads generated images as featured image
* IMPROVED: Token tracker includes image generation pricing

= 1.8.2 =
* FIX: Post Categories and Post Tags now appear in Translation Manager (WPML fallback for unregistered taxonomies)
* FIX: Taxonomy term count excludes WPML translation copies (was double-counting)
* FIX: Auto-register unregistered taxonomy terms in WPML before translation
* FIX: Knowledge Graph plugins section loads product data for SEO coverage calculation
* IMPROVED: AIOSEO and SEOPress focus keyword meta key support added
* IMPROVED: Translation Manager Bulk Translation section removed (redundant with per-language buttons)
* NEW: Fix Category Assignments maintenance button (re-assigns translated products to correct categories)

= 1.8.1 =
* NEW: Knowledge Graph endpoint (GET /knowledge-graph) — AI-consumable graph of products, categories, languages, SEO/AEO coverage, and opportunity scores
* FIX: SEO meta writing now uses Plugin Detector (was hardcoded Yoast+RankMath — AIOSEO/SEOPress now work)
* FIX: Polylang translation create — new posts are now created with full WC meta, images, taxonomies, SEO meta (was update-only)
* FIX: FAQPage JSON-LD schema now output in wp_head (was missing — Google can now see FAQ data)
* FIX: Cache invalidation added to all callbacks (WP Rocket, LiteSpeed, W3 Total Cache)
* FIX: Translation SEO meta reading now uses Plugin Detector (was hardcoded keys)

= 1.8.0 =
* Merged Logs + Token Usage into unified "Usage & Logs" page with tabs
* Added Quick Actions to dashboard (Translate, Enrich, Open Claw, Usage)
* Removed emoji from menu items
* Removed API Endpoints section from dashboard (developer info, not user-facing)
* Dashboard now shows compact activity feed + AI spend summary
* Grouped logs by date for better readability
* Fixed translation image copy on both create and update paths
* Fixed draft translations not being published
* Fixed large product translation (dynamic max_tokens scaling)
* Fixed batch translation item matching by product ID (not index)

= 1.7.4 =
* Fixed translation coverage percentage (exclude WPML translations from total count)
* Fixed batch translation workflow (SplitInBatches → native multi-item processing)
* Fixed n8n webhook registration (webhookId required for production URLs)
* Fixed n8n credential binding (credentials block required in workflow JSON)
* Fixed WP REST API for product data (WC API returns empty with Bearer token)
* Fixed product image sharing across WPML languages

= 1.7.3 =
* Fixed translation pipeline — OpenAI API, correct callback fields, data flow
* Fixed product image copy for WPML translations (shared across languages)
* Added category/tag translation coverage to Translation Manager
* Removed vendor-specific naming — all AI references are now generic
* Changed all default AI model/provider to GPT-4o Mini / OpenAI
* Fixed callback body construction (HTML content breaking JSON)
* Added Prepare Callback code nodes to safely build JSON bodies

= 1.7.0 =
* Added AI Token Usage tracking with daily/monthly cost breakdown
* Added daily budget limit with auto-pause when exceeded
* Added model selection (GPT-4o Mini, Claude, Gemini)
* Added cost protection guards to all AI-calling functions
* Switched default AI model to GPT-4o Mini (20x cheaper)
* Added dedup check to prevent duplicate enrichment requests
* Fixed cron race condition — disabled crons properly unschedule
* Added workflow result feedback with token reporting

= 1.6.3 =
* Added slash commands to Open Claw (/scan, /seo, /translate, etc.)
* Added command autocomplete popup
* Fixed activity log timestamp field mismatch
* Fixed log cleanup (Clear All Logs)
* Improved log messages with query context
* Added WooCommerce translation support warning for Polylang/WPML
* Removed Turkish text from intent patterns (English-only)

= 1.6.2 =
* Fixed Open Claw 404 on Scan Opportunities and Missing SEO Meta
* Added local resolution for content scans (no n8n dependency)
* Fixed translation pipeline language parameter handling
* Added DB auto-upgrade on plugin update

= 1.6.1 =
* Fixed dashboard icon alignment
* Added Chatwoot integration with Telegram notifications
* Moved Elementor/LiteSpeed to status bar

== Upgrade Notice ==

= 1.7.0 =
Critical cost protection update. Adds daily budget limits and switches to GPT-4o Mini (20x cheaper). Strongly recommended.

== Additional Information ==

LuwiPress is developed by **Luwi Developments LLC** — a boutique AI agency working with founders, startups, and creative teams. We specialize in custom AI workflows, process automation, and intelligent systems.

*Dream. Design. Develop.*

= Free Plugin, Professional Support =

LuwiPress is free and open-source. You bring your own AI API key — no subscription, no hidden fees, no vendor lock-in, no external services in the loop.

Need help getting started? We offer:

* **Free:** Plugin + documentation
* **Setup Service:** We configure the AI engine, migrate existing SEO/translation data, and get your store automations running
* **Custom Automations:** Tailored AI pipelines for your specific business needs
* **Ongoing Support:** Monitoring, optimization, and new automation development

= Resources =

* [Documentation & Guides](https://luwi.dev)
* [GitHub Repository](https://github.com/umutsun/luwipress)

= Contact =

* **Email:** hello@luwi.dev
* **Web:** [luwi.dev](https://luwi.dev)
* **Services:** Custom AI automations, WooCommerce optimization, store intelligence
