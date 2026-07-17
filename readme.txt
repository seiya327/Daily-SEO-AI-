=== Daily SEO AI Publisher ===
Contributors: codex
Tags: seo, ai, openai, publishing
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.6.17
License: GPLv2 or later

Daily SEO AI Publisher researches, drafts, audits, and prepares SEO-focused WordPress posts with OpenAI.

== Description ==

This initial implementation includes:

* WordPress admin settings page.
* API key entry in WordPress admin. Existing keys are never printed back to HTML.
* Optional NVIDIA API fallback when OpenAI quota is exhausted.
* Mock mode for testing without an API key.
* AI-generated attraction-to-conversion content strategy and topic queue.
* Model dropdowns, daily article count, funnel ratio, affiliate CTA settings, and a safe test run.
* Article quality presets that adjust model selection, article depth, and audit thresholds.
* Long-tail and unexpected-entry keyword strategy controls for content planning.
* Built-in editorial standards for intent matching, information gain, decision support, source discipline, and natural conversion paths.
* Deterministic strategy validation with one automatic repair pass for generic, duplicated, disconnected, or cannibalizing plans.
* Deterministic article checks plus audit-driven automatic rewriting before draft or publication.
* Resumable OpenAI background generation for long-running site strategy jobs.
* Verified reference output, approved internal-link candidates, cluster-aware CV routing, and completed-topic tracking.
* First-party page-view, internal CTA click, and affiliate CTA click measurement for conversion-oriented PDCA.
* Pipeline progress for strategy, research, writing, audit, and publishing.
* Google Search Console OAuth connection and rolling performance sync.
* GA4 Data API sync for page views, engagement seconds, and key events.
* Automated 28-day period comparison, refresh selection, AI rewrite, audit, revision backup, and review or auto-apply workflow.
* WP-Cron based job execution.
* Research, draft, audit, and publish stages.
* Strict JSON schema contracts for OpenAI Responses API requests.
* Draft-first safety behavior for YMYL or failed audit articles.
* Core meta description output only when a known SEO plugin is not active.
* Styled headings, styled tables, colored lists, and CTA/reference styling for AI-generated posts.
* Automatic reader-facing article illustrations without external image API cost.
* Rolling article-plan refill when the active topic backlog gets low.

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

The plugin requests read-only Search Console and GA4 scopes. Automatic application is disabled by default; review drafts can be applied or discarded from the pipeline table.

== Changelog ==

= 0.6.17 =
Added GA4 Data API integration, GA4 property settings, daily GA4 metric sync, and engagement/conversion-aware refresh candidate scoring.

= 0.6.16 =
Added automatic safe SVG illustrations to generated posts and rolling strategy refill when active article plans fall below the backlog threshold.

= 0.6.15 =
Allowed test runs to publish when the post status setting is publish, changed auto setup to choose publish by default, and added visible draft reasons to the pipeline.

= 0.6.14 =
Stopped injecting reader-visible AI/helper blocks into posts and added a front-end cleanup filter that hides legacy DSAP visual helper blocks from existing articles.

= 0.6.13 =
Removed internal quality/source/section metrics from article output and improved reader-facing colors for headings, lists, tables, and CTA blocks.

= 0.6.12 =
Added table styling and stronger article prompts requiring comparison tables and decision-support sections. Test runs still stay as drafts by design.

= 0.6.11 =
Relaxed source validation so jobs no longer fail solely because OpenAI web search returns content sources without machine-verifiable citation annotations.

= 0.6.10 =
Limited the NVIDIA fallback dropdown to stronger candidates only and changed the default fallback model to NVIDIA Nemotron Super 49B.

= 0.6.9 =
Added DeepSeek and GLM-family NVIDIA model candidates to the NVIDIA fallback model dropdown, while keeping custom model ID support for catalog-specific slugs.

= 0.6.8 =
Raised OpenAI structured output limits for long strategy and article jobs, classified max_output_tokens as retryable, and reset stale background response IDs before retrying.

= 0.6.7 =
Changed NVIDIA model configuration from a plain text field to a dropdown with common model choices and a custom model ID fallback.

= 0.6.6 =
Added optional NVIDIA API fallback for OpenAI quota exhaustion, including admin settings, key storage, and an OpenAI-to-NVIDIA client failover path.

= 0.6.5 =
Classified OpenAI quota exhaustion separately so the pipeline stops retrying immediately and shows billing guidance.

= 0.6.4 =
Limited model selection to GPT-5.6 Luna, GPT-5.6 Terra, GPT-5 mini, GPT-5.4 mini, and GPT-5 nano. Removed Sol from automatic quality presets and migrated unsupported saved models to cost-conscious defaults.

= 0.6.3 =
Moved long-running strategy generation to resumable OpenAI background responses, added progress polling and automatic admin refresh, and fixed retrying permanently failed jobs.

= 0.6.2 =
Changed one-click GitHub updates to use WordPress's native updater and filesystem credential flow, with specific diagnostics for file modifications, multisite, and user capability restrictions.

= 0.6.1 =
Removed the timed-out external PHP setup step from the GitHub Release workflow so update ZIP assets are published reliably.

= 0.6.0 =
Added offer-led conversion strategy, strict long-tail plan validation and repair, built-in editorial prompts, deterministic article quality gates, audit-driven rewriting, verified references, controlled internal links, cluster-aware publishing order, CTA and page-view tracking, conversion-aware refresh selection, and automatic strategy refill.

= 0.5.12 =
Added long-tail and unexpected-entry keyword strategy settings and strengthened strategy prompts to avoid generic topic plans.

= 0.5.11 =
Added article quality presets and wired them into prompts, model selection, and audit thresholds.

= 0.5.10 =
Clarified Google connection and automatic improvement setup in the Initial Setup tab, including OAuth client creation steps.

= 0.5.9 =
Moved API setup, Google connection, test execution, and setup pipeline checks into the Initial Setup tab.

= 0.5.8 =
Added article plan reset, forced strategy rebuild on auto setup, and one-click GitHub update installation from the settings screen.

= 0.5.7 =
Added visible auto setup progress tracking, step status, and current setup values.

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
