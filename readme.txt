=== Daily SEO AI Publisher ===
Contributors: codex
Tags: seo, ai, openai, publishing
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.5.6
License: GPLv2 or later

Daily SEO AI Publisher researches, drafts, audits, and prepares SEO-focused WordPress posts with OpenAI.

== Description ==

This initial implementation includes:

* WordPress admin settings page.
* API key entry in WordPress admin. Existing keys are never printed back to HTML.
* Mock mode for testing without an API key.
* AI-generated attraction-to-conversion content strategy and topic queue.
* Model dropdowns, daily article count, funnel ratio, affiliate CTA settings, and a safe test run.
* Pipeline progress for strategy, research, writing, audit, and publishing.
* Google Search Console OAuth connection and rolling performance sync.
* Automated 28-day period comparison, refresh selection, AI rewrite, audit, revision backup, and review or auto-apply workflow.
* WP-Cron based job execution.
* Research, draft, audit, and publish stages.
* Strict JSON schema contracts for OpenAI Responses API requests.
* Draft-first safety behavior for YMYL or failed audit articles.
* Core meta description output only when a known SEO plugin is not active.

This plugin does not guarantee search rankings. It uses Search Console evidence to plan and audit controlled refreshes.

== Installation ==

1. Upload the plugin folder to wp-content/plugins.
2. Activate "Daily SEO AI Publisher".
3. Open "Daily SEO AI" in the WordPress admin menu.
4. Add an OpenAI API key or leave mock mode enabled for local testing.
5. Add a topic and run a job.

== Search Console PDCA Setup ==

1. Enable the Google Search Console API in a Google Cloud project.
2. Create an OAuth 2.0 Web application client.
3. Copy the redirect URI shown in the plugin's Auto Improvement screen into the client's authorized redirect URIs.
4. Save the client ID and client secret in the plugin settings, then connect Google Search Console.
5. Select a property, enable daily GSC sync and automated PDCA, and run the initial 59-day sync.

The plugin requests only the webmasters.readonly scope. Automatic application is disabled by default; review drafts can be applied or discarded from the pipeline table.

== Changelog ==

= 0.5.6 =
Moved the one-click auto setup flow into a dedicated Initial Setup tab.

= 0.5.5 =
Added one-click AI auto setup after API key entry and GitHub tag-archive update fallback when Releases are not available.

= 0.5.4 =
Changed the GitHub update source to seiya327/Daily-SEO-AI-.

= 0.5.3 =
Changed the admin navigation from anchor scrolling to real tab switching and added the admin JavaScript asset.

= 0.5.2 =
Restored the full strategy and detailed settings screens while keeping the low-effort AI quick setup at the top.

= 0.5.1 =
Simplified the admin screen, moved advanced controls behind a details panel, fixed Japanese mojibake, and clarified GitHub Release setup errors.

= 0.5.0 =
Added signed GitHub Release updates, private repository token support, manual update checks, optional automatic updates, and tag-driven release packaging.

= 0.4.0 =
Added a persistent Google setup wizard with automatic progress detection, Cloud Console links, redirect URI copy, secure OAuth JSON import, property selection, initial sync, and one-click PDCA activation.

= 0.3.0 =
Added the automated Search Console PDCA loop, refresh model, performance dashboard, safe review workflow, and WordPress revision backups.

= 0.2.0 =
Added content strategy planning, attraction/CV article roles, affiliate routing, model dropdowns, daily volume controls, test execution, and pipeline progress. Rebuilt the Japanese admin screen as UTF-8.

= 0.1.0 =
Initial implementation.
