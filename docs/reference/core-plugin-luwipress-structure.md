# Core Plugin (`luwipress/`) — Structure

```
luwipress/
├── luwipress.php                             <- Plugin bootstrap (class LuwiPress)
├── includes/
│   ├── class-luwipress-ai-engine.php         <- Multi-provider AI dispatch
│   ├── class-luwipress-ai-provider.php       <- Provider interface
│   ├── class-luwipress-prompts.php           <- Prompt templates per task
│   ├── class-luwipress-permission.php        <- Centralised auth (5 methods)
│   ├── class-luwipress-api.php               <- Core REST API + CRUD
│   ├── class-luwipress-auth.php              <- JWT authentication
│   ├── class-luwipress-ai-content.php        <- Product enrichment pipeline + settings
│   ├── class-luwipress-aeo.php               <- FAQ/HowTo/Speakable schema
│   ├── class-luwipress-translation.php       <- WPML/Polylang translation bridge + batch + settings
│   ├── class-luwipress-knowledge-graph.php   <- Store intelligence graph
│   ├── class-luwipress-elementor.php         <- Elementor page read/write/translate
│   ├── class-luwipress-content-scheduler.php <- AI blog post scheduling + settings
│   ├── class-luwipress-customer-chat.php     <- Storefront chat + settings
│   ├── class-luwipress-crm-bridge.php        <- Customer segmentation (read-only, pure-Woo)
│   ├── class-luwipress-review-analytics.php  <- Review sentiment analysis
│   ├── class-luwipress-plugin-detector.php   <- Auto-detect friendly plugins
│   ├── class-luwipress-site-config.php       <- Environment snapshot API
│   ├── class-luwipress-seo-writer.php        <- Native SEO writer fallback + hreflang
│   ├── class-luwipress-email-proxy.php       <- wp_mail() proxy
│   ├── class-luwipress-token-tracker.php     <- AI cost tracking
│   ├── class-luwipress-image-handler.php     <- DALL-E image generation
│   ├── class-luwipress-internal-linker.php   <- AI internal linking
│   ├── class-luwipress-marketplace.php       <- Marketplace adapter registry
│   ├── class-luwipress-people.php            <- Generic vendor/maker/atelier CPT + Schema.org Person/Org E-E-A-T + WC product attribution (3.5.0+)
│   ├── class-luwipress-search-index.php      <- Search index builder
│   ├── class-luwipress-logger.php            <- DB activity logging
│   ├── class-luwipress-hmac.php              <- Webhook signing
│   ├── class-luwipress-security.php          <- Headers, IP whitelist
│   ├── providers/                            <- OpenAI / Anthropic / Google
│   └── marketplace/                          <- Per-marketplace adapters
├── admin/
│   ├── admin-page.php             <- Dashboard
│   ├── settings-page.php          <- Multi-tab settings
│   ├── knowledge-graph-page.php   <- D3.js interactive graph
│   ├── translation-page.php       <- Translation Manager
│   ├── usage-page.php             <- Token usage & logs
│   └── scheduler-page.php         <- Content Scheduler
└── assets/
    ├── css/admin.css              <- Design tokens + all component styles
    └── js/admin.js                <- Dashboard loader, chat, modals, toasts
```
