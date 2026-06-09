=== AI Internal Linking ===
Contributors: you
Tags: internal linking, seo, links, suggestions, geo
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
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

= 0.2.0 =
* Phase 0b: gated non-destructive apply (revision + ledger backup), one-click undo, batched clean-removal, Link Health dashboard (orphans/dead-ends/density/click-depth). Auto-apply for Gutenberg + Classic; other builders suggest-only.

= 0.1.0 =
* Initial Phase 0a build: auto-detection, background indexer, TF-IDF suggestion engine, review inbox. Read-only (no content mutation).
