=== AI Internal Linking ===
Contributors: you
Tags: internal linking, seo, links, suggestions, geo
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.11.0
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

* Inbound suggestions ("which posts should link TO this page") with an orphan fix-it flow, bulk approve/apply in the inbox, Elementor auto-apply, and cluster-aware suggestion ranking.

== Installation ==

1. Upload the `ai-internal-linking` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Follow the setup screen: confirm scope, then run **Index / Re-index site** and **Scan for suggestions**.
4. Review results under **AI Linking → Suggestions**.

== Changelog ==

= 0.11.0 =
* Removed the Clusters and GEO Readiness features. Both were standalone dashboards that did not influence the core index → suggest → review → apply workflow: Clusters produced a hub-and-spoke authority report that never fed the suggestion engine, and GEO Readiness was a heuristic "AI-citation likelihood" score that was speculative and not directly actionable. The admin now shows only the tabs that matter (Setup & Dashboard, Suggestions, Link Health, Keywords, AI Keys, Settings). The clusters/cluster_members tables are no longer created on new installs and are dropped on uninstall of existing installs. Link Health (orphans, dead-ends, broken links, PageRank, anchor diversity) is unchanged.

= 0.10.3 =
* Apply now links the first eligible occurrence of an anchor instead of refusing when the phrase appears more than once. Key terms repeat across a heading, a summary list, a caption, and a practice question in rich posts, so requiring exactly one occurrence made many good, high-confidence suggestions un-appliable ("Could not place this link automatically — the anchor wasn't found uniquely"). Headings, existing links, code, and tag attributes are still never linked, and the byte-splice + visible-text integrity check are unchanged, so no structural corruption is possible. Only a genuinely absent anchor (zero eligible occurrences) is still declined.

= 0.10.2 =
* Fix: the suggestion scan froze / returned "Something went wrong" when "AI link suggestions" was on. Each post is a live model round-trip, but the scan processed up to 24 posts per AJAX request, so a single request could exceed the server's PHP time limit and 500 before the progress bar ever appeared. The scan now returns immediately on start (bar shows at 0% right away) and processes only a small, wall-clock-bounded chunk of posts per request when AI suggestions are enabled (one post at a time under a ~12s budget), so requests always return well within the time limit and the bar advances steadily. Free (TF-IDF) scans are likewise time-bounded per request.

= 0.10.1 =
* "Build embeddings" now reports why it did nothing when no embeddings provider is configured, instead of completing silently. Anthropic/Claude has no embeddings API, so with only a Claude key (and no embeddings provider set) the build had nothing to do; the button now explains this and points you to add an embedding-capable key or to use "AI link suggestions" for Claude.

= 0.10.0 =
* Generative AI suggestions (any chat model): a new "AI link suggestions" toggle (Settings) lets your chat provider propose contextual links directly — and unlike the embedding re-ranker, it works with chat-only keys such as Claude, Groq, xAI, DeepSeek, OpenRouter, and Perplexity, as well as OpenAI/Gemini/local models. During a suggestion scan the model receives each page plus a shortlist of genuinely related pages (real TF-IDF recall) and returns the best links with a natural anchor. Nothing is fabricated: candidate targets are constrained to existing pages, and every proposed anchor is verified to appear verbatim in the page body (wrap-first, never in a heading) before it becomes a suggestion — anything invented is dropped. Runs after keyword-evidence and before the TF-IDF fill, respects the monthly spend cap, and falls back to the free engine if the model is unavailable. AI picks carry an "AI" badge in the review inbox. Add a chat key under "AI Keys", set the chat provider under Settings, enable the toggle, then run a scan.

= 0.9.0 =
* Google Search Console fetch (API): pull performance data (queries + pages) directly, no CSV export step. Connect with a Google service-account JSON key — the key is stored encrypted at rest, and access tokens are minted on the fly by signing a JWT (the OAuth 2.0 JWT-bearer flow), so there are no redirect URLs, no consent screen, and it works headless. Add the service-account email as a user on your Search Console property, pick the property and date window on the Keywords tab, and click "Fetch now"; an optional daily auto-sync keeps it current. Fetched rows flow through the same striking-distance/opportunity scoring as CSV imports and feed the keyword-evidence suggestion engine (0.8.0). Bounded by a max-rows ceiling (filter: ailinking_gsc_max_rows) so it stays safe on very large sites; rows arrive click-descending, so the highest-value keywords are always kept. Requires the PHP OpenSSL (or Sodium) extension for secure key storage and signing.

= 0.8.0 =
* Keyword-evidence suggestions: imported keywords (GSC/Semrush, Keywords tab) now feed the suggestion engine. When a post mentions a query that another page already ranks for — without linking to that page — the scan proposes the link with the ranking keyword as its anchor (the model Ahrefs/Screaming Frog use for link opportunities). Wrap-first still holds: the keyword must literally appear in the post's body text. Striking-distance keywords (positions 5–20) and high-opportunity keywords rank first; keyword suggestions run before TF-IDF and carry a "keyword" badge in the inbox (embedding-re-ranked ones now show a "semantic" badge). Includes an exact-anchor over-optimization guard: at most 3 identical exact-match anchors per target (filter: ailinking_max_exact_anchors_per_target; pool size: ailinking_keyword_pool_max). Toggle under Settings → "Keyword suggestions" (on by default; inactive until keywords are imported). Run a new suggestion scan after importing keywords.

= 0.7.1 =
* Auto-detect clusters: hardened idempotency. The duplicate-skip check now compares against the exact slug stored on the cluster (the detector passes its own slug to the repository), so re-running auto-detect can never create a second copy of a cluster — including for category names whose punctuation/language suffix would otherwise sanitize differently. No change to detected results; purely a robustness fix.

= 0.7.0 =
* Auto-detect clusters: a new "Auto-detect clusters" button on the Clusters tab builds topic clusters automatically from your categories/taxonomies and internal link structure — no manual pillar/spoke post IDs. Each topic (term) with at least 3 indexed posts becomes a cluster, the post the others link to most is chosen as the pillar (hub), and clusters are kept per-language. It is idempotent: re-running skips topics that already have a cluster and never touches clusters you created by hand. Detected clusters are analyzed immediately so authority/flat badges show right away. (Filters: ailinking_auto_cluster_min_size, _max_size, _max, _use_tags, _taxonomies.)

= 0.6.8 =
* "Analyze clusters" now gives clear feedback when there are no clusters yet (it previously just reloaded to the same screen, which looked like nothing happened). Create a cluster (name + pillar post ID), add spoke posts, then Analyze.

= 0.6.7 =
* Added a "Reset all data" button (Setup & Dashboard) that wipes every plugin table — index, suggestions, link graph, clusters, keywords, embeddings, inserted-links log — plus progress/caches, so you can rescan from scratch. It is red, shows a clear warning, and asks for confirmation; it does not change your posts.

= 0.6.6 =
* Anchors now come from body text only — the post title (the page H1) is excluded from the indexed corpus too, so no suggestion uses the title/heading as its anchor. Also silenced a PHP notice from accent-insensitive database collation in the relevance scorer.

= 0.6.5 =
* Headings are never linked: anchors are no longer drawn from H1-H6 (heading text is excluded from the indexed corpus), and link insertion skips all heading elements and the Gutenberg heading block. Links only go into body text (paragraphs, lists, quotes).

= 0.6.4 =
* Fix: on sites with a persistent object cache, the WP-Cron indexer could race itself and hit “Duplicate entry for key post_id”, which (with 0.6.3’s strict error handling) skipped most posts so only a few were indexed. Index writes are now an atomic upsert (REPLACE), and TF-IDF inserts use INSERT IGNORE — concurrent workers can no longer collide. Full re-index now stores every post.

= 0.6.3 =
* Fix: indexing could report “done” (e.g. 210/210) while saving 0 pages when the custom DB tables were missing (possible after deletes/updates). The plugin now (a) recreates missing tables automatically before indexing and on admin load, and (b) surfaces the actual database error in the dashboard and progress bar instead of failing silently. Note: the indexed total reflects your Scope — only the post types you tick under “Crawl these post types” are counted.

= 0.6.2 =
* Fix: indexing could freeze and over-count (e.g. “218 / 210”, only a few pages actually indexed) when the WP-Cron tick and the admin run overlapped. Added a job lock so only one indexer/scan/embed worker runs at a time (cursor advances cleanly, no skipped or double-counted posts), isolated per-post failures so one bad post can’t stall a run, and clamped the progress bar so it can’t exceed 100%.

= 0.6.1 =
* UI: consolidated the eight separate admin pages into a single “AI Linking” page with tabs (Setup & Dashboard, Suggestions, Link Health, Clusters, GEO Readiness, Keywords, AI Keys, Settings). Navigation only — no functional changes.

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
