# Daily SEO AI Publisher

WordPress plugin for AI-assisted SEO strategy, article generation, affiliate funnels, Search Console measurement, and controlled content refreshes.

Version 0.6.25 prevents publish-mode quality failures from being completed as WordPress drafts and makes one bounded replacement attempt with another planned topic. Version 0.6.24 added executable article-output regression tests, blocked replaceable product copy and near-duplicate paragraphs, and added free attributed Openverse illustrations plus a meaningful decision-flow visual.

## Releases

Push a semantic version tag matching the plugin header, for example `v0.6.11`. GitHub Actions validates the PHP source and publishes:

- `daily-seo-ai-publisher.zip`
- `daily-seo-ai-publisher.zip.sha256`

Installed plugins discover the latest stable GitHub Release through WordPress's standard plugin update system.

## Development

The plugin requires WordPress 6.5 or later and PHP 8.0 or later. API credentials and private-repository tokens are configured in WordPress and must never be committed.
