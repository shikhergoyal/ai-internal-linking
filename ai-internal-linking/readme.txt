=== AI Internal Linking ===
Contributors: you
Tags: internal linking, seo, links, suggestions, geo
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Universal, AI-ready internal linking. Crawls any WordPress site and suggests contextual internal links. Every suggestion is reviewed and gated — nothing is auto-inserted.

== Description ==

AI Internal Linking indexes your whole site, then proposes contextual internal links following SEO and AI-search (GEO) best practices. It runs on any theme, post type, taxonomy, or page builder — the site's structure is auto-detected at runtime.

This build (Phase 0a) is fully functional with **zero AI keys and zero external calls**: it uses a local TF-IDF relevance engine. Optional AI providers (multi-key pool) and an embedding re-ranker arrive in later phases.

**What this build does**

* Auto-detects public post types, taxonomies, page builders (Gutenberg, Classic, Elementor, Divi, WPBakery, Beaver Builder, ACF), WooCommerce, and multilingual setups (WPML/Polylang).
* Indexes your content into custom tables in the background (keyset-cursor batching; WP-Cron with an in-browser fallback).
* Generates contextual link suggestions with relevance, naturalness, and confidence scores — wrap-first (only where a natural anchor already exists in the text).
* Never suggests cross-language links; respects link-density limits and skips pages you already link to.
* Presents everything in a review inbox (approve / reject). **Nothing is written to your content in this build.**

**New in 0.2.0 (Phase 0b)**

* Gated, non-destructive **apply** with a WP revision + an independent backup ledger, plus **one-click undo** and a batched "remove all inserted links" action. Auto-apply currently covers Gutenberg and Classic; Divi/WPBakery/Elementor/Beaver/ACF remain suggest-only (manual) for now.
* Inserted links are plain `<a data-ailinking-id>` (never shortcodes) and pass a visible-text integrity check before saving.
* **Link Health** dashboard: orphans, dead-ends, over/under-linked pages, and click-depth from the front page.

**Coming next**

* Auto-apply for Divi/WPBakery (shortcode content), GSC CSV keyword import, GEO/cluster module, AI provider system + embedding re-ranker.

== Installation ==

1. Upload the `ai-internal-linking` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Follow the setup screen: confirm scope, then run **Index / Re-index site** and **Scan for suggestions**.
4. Review results under **AI Linking → Suggestions**.

== Changelog ==

= 0.6.0 =
* GEO Readiness dashboard: scores how well each page is positioned to be cited by AI answer engines (from link equity, click depth, cluster membership, orphan status, schema presence, freshness, and answer front-loading), with concrete fixes, structured-data recommendations, and AI-crawler guidance.

= 0.5.0 =
* Topic clusters: define a pillar (hub) page plus supporting spokes; the analyzer computes hub-and-spoke authority, detects “flat” clusters with no clear hub, and gives concrete fixes. New Clusters admin page.

= 0.4.0 =
* SEO checks completed: internal PageRank (link-equity) on the Link Health dashboard, broken internal-link detection, and an anchor-text diversity / over-optimization report. “Recompute audits” now also computes PageRank and broken links.

= 0.3.0 =
* AI provider system (Phase 1): provider-agnostic adapters — Anthropic, OpenAI, Google Gemini, Cohere, OpenRouter, Mistral, Groq, Together, Fireworks, DeepSeek, xAI, Perplexity, Azure OpenAI, Voyage (embeddings), a custom OpenAI-compatible endpoint, and local (Ollama/LM Studio/vLLM).
* Multi-key pool with encrypted-at-rest keys (envelope encryption), round-robin or primary-failover rotation, automatic failover with cooldown, per-key health/spend, and a monthly spend cap with auto-pause to TF-IDF.
* Optional embedding re-ranker (hybrid TF-IDF recall → embedding precision) with a “Build embeddings” action.
* Keyword CSV import (GSC/Semrush/generic) with striking-distance (positions 5–20) + opportunity scoring, mapped to posts. New “AI Keys” and “Keywords” admin pages.
* Note: live API calls, and the encryption story, require your real environment + keys to fully validate.

= 0.2.1 =
* Anchors are now descriptive 2-4 word phrases drawn from the target title (configurable via Minimum/Maximum anchor words), instead of single words. A fresh scan replaces the pending queue so new settings take effect; rejected pairs are no longer re-suggested.

= 0.2.0 =
* Phase 0b: gated non-destructive apply (revision + ledger backup), one-click undo, batched clean-removal, Link Health dashboard (orphans/dead-ends/density/click-depth). Auto-apply for Gutenberg + Classic; other builders suggest-only.

= 0.1.0 =
* Initial Phase 0a build: auto-detection, background indexer, TF-IDF suggestion engine, review inbox. Read-only (no content mutation).
