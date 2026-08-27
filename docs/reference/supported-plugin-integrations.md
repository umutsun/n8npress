# Supported Plugin Integrations

LuwiPress **detects and integrates with** via `LuwiPress_Plugin_Detector`:

| Category | Supported Plugins | How we integrate |
|----------|-------------------|------------------|
| SEO | Rank Math, Yoast, AIOSEO, SEOPress | Read/write their meta keys |
| Translation | WPML, Polylang, TranslatePress | Save translations via their API |
| Email/SMTP | WP Mail SMTP, FluentSMTP, Post SMTP | Send via `wp_mail()` |
| CRM | FluentCRM, Mailchimp for WC | Detect presence, avoid duplication |
| Cache | WP Rocket, LiteSpeed, W3 Total Cache | Purge on content update |
| Page Builder | Elementor, Divi | Read/write/translate Elementor pages via `LuwiPress_Elementor` |
| Analytics | Google Site Kit, GTM4WP, MonsterInsights | Detect GTM/GA4 presence |
| Google Ads | Google for WooCommerce, Conversios | Detect Merchant Center + conversion tracking |
| Meta Ads | Meta Pixel, Meta for WooCommerce, PixelYourSite | Detect Pixel + CAPI + catalog |
| Product Feed | Google Listings & Ads, Product Feed PRO, CTX Feed | Detect feed sync status |
