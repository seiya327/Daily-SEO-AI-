# Daily SEO AI Publisher

WordPress plugin for AI-assisted SEO strategy, article generation, affiliate funnels, Search Console measurement, and controlled content refreshes.

Version 0.6.23 replaces length-driven article generation with evidence and concision gates, rejects unsupported niche topics before drafting, audits product specificity and repetition, prevents failed revisions from restoring rejected copy, and adds same-post AI rewriting for poor articles. Version 0.6.22 fixed retained draft decisions and added one-click publishing.

## Releases

Push a semantic version tag matching the plugin header, for example `v0.6.11`. GitHub Actions validates the PHP source and publishes:

- `daily-seo-ai-publisher.zip`
- `daily-seo-ai-publisher.zip.sha256`

Installed plugins discover the latest stable GitHub Release through WordPress's standard plugin update system.

## Development

The plugin requires WordPress 6.5 or later and PHP 8.0 or later. API credentials and private-repository tokens are configured in WordPress and must never be committed.
