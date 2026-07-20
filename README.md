# Daily SEO AI Publisher

WordPress plugin for AI-assisted SEO strategy, article generation, affiliate funnels, Search Console measurement, and controlled content refreshes.

Version 0.6.22 fixes retained draft decisions in retried jobs, verifies the actual WordPress post status, and adds one-click publishing for existing generated drafts. Version 0.6.21 added optional asynchronous GPT Image generation, performance-informed strategy replenishment with a 50-topic refill floor, safer managed-post refresh scope, refreshed citations during rewrites, and SEO metadata/schema compatibility improvements.

## Releases

Push a semantic version tag matching the plugin header, for example `v0.6.11`. GitHub Actions validates the PHP source and publishes:

- `daily-seo-ai-publisher.zip`
- `daily-seo-ai-publisher.zip.sha256`

Installed plugins discover the latest stable GitHub Release through WordPress's standard plugin update system.

## Development

The plugin requires WordPress 6.5 or later and PHP 8.0 or later. API credentials and private-repository tokens are configured in WordPress and must never be committed.
