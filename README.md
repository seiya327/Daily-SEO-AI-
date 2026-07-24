# Daily SEO AI Publisher

WordPress plugin for AI-assisted SEO strategy, article generation, affiliate funnels, Search Console measurement, and controlled content refreshes.

Version 0.7.1 makes the public GitHub updater fully unauthenticated and removes stale GitHub tokens that could block update discovery. Version 0.7.0 uses NVIDIA as the sole AI provider and grounds research with server-verified source pages before generation.

## Releases

Push a semantic version tag matching the plugin header, for example `v0.6.11`. GitHub Actions validates the PHP source and publishes:

- `daily-seo-ai-publisher.zip`
- `daily-seo-ai-publisher.zip.sha256`

Installed plugins discover the latest stable GitHub Release through WordPress's standard plugin update system.

## Development

The plugin requires WordPress 6.5 or later and PHP 8.0 or later. NVIDIA and Google API credentials are configured in WordPress and must never be committed.
