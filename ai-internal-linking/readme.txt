=== AI Internal Linking ===
Contributors: shikhergoyal
Tags: internal linking, seo, links, suggestions, geo
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.21.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Universal, AI-ready internal linking. Crawls any WordPress site and suggests contextual internal links. Every suggestion is reviewed and gated — nothing is auto-inserted.

== Description ==

AI Internal Linking indexes your whole site, then proposes contextual internal links following SEO and AI-search (GEO) best practices. It runs on any theme, post type, taxonomy, or page builder — the site's structure is auto-detected at runtime.

It is fully functional with **zero AI keys and zero external calls**, using a local relevance engine plus your Search Console keyword data. An optional chat model (bring your own key, any provider) can propose links on top of that.

**What it does**

* Auto-detects public post types, taxonomies, page builders (Gutenberg, Classic, Elementor, Divi, WPBakery, Beaver Builder, ACF), WooCommerce, and multilingual setups (WPML/Polylang).
* Indexes your content into custom tables in the background (keyset-cursor batching; WP-Cron with an in-browser fallback).
* Generates contextual link suggestions with relevance, naturalness, and confidence scores, wrap-first, meaning only where a natural anchor already exists in the text.
* Never suggests cross-language links, respects link-density limits, and skips pages you already link to.
* Presents everything in a review inbox: approve, reject, apply, undo — one at a time, or in bulk with checkboxes and a select-all.

**Three suggestion engines**

* **GSC keyword** (free). A page mentions a query another page already ranks for, without linking to it, so the ranking query becomes the anchor. Runs first, because search demand is stronger evidence than similarity.
* **AI Suggestion** (optional, your own key). Any chat model picks links from a shortlist of genuinely related pages, each one shown as its title plus a short summary of what that page covers, so the model judges a destination by what it is about rather than only by what it is called. The summary is extracted from the page's own sentences — no second AI call, and nothing written into your content. It cannot invent a target or an anchor: candidates are restricted to pages that exist, and every proposed anchor is verified to appear verbatim in the body or the pick is discarded.
* **Related Content** (free). A local relevance engine that fills whatever link budget the other two leave. Needs no keys and makes no external calls.

**How it reads your content**

Internally, from your WordPress database, never by requesting your own pages over the internet. It asks WordPress for each post directly, reads page-builder content from the builder's own stored fields, and reduces the HTML to plain words. The only outside connections the plugin ever makes are to Google Search Console and to the AI provider you configured, if any.

This makes indexing fast, puts no load on your front end, works on staging and password-protected sites, and is never fooled by page caching. The trade-off: content that only exists when a page is displayed, such as theme-added sections, related-post blocks, or another plugin's shortcode output, is not seen. Shortcode tags are removed and any text between them is kept, but what a shortcode would have generated is not indexed. That is deliberate, since the plugin should only ever link words that live in your content and that you can edit.

**Applying links is gated, and reversible**

Nothing is inserted without your approval. When you do approve, the plugin writes a WordPress revision AND an independent backup ledger before touching the post, so every insertion has two ways back. One click undoes a single link, and a batched action removes every link the plugin ever inserted.

Insertions are a byte-preserving splice of a plain `<a data-ailinking-id>` tag, never a shortcode and never a DOM round trip, and each write passes a visible-text integrity check before it is saved. Auto-apply covers Gutenberg and Classic. Elementor, Divi, WPBakery, Beaver Builder and ACF are suggest-only, so the plugin never rewrites content a builder owns.

Uninstalling restores content from the ledger first, so removing the plugin does not leave its links behind.

**Link Health**

Orphans, dead ends, broken links, over and under-linked pages, internal PageRank, anchor diversity, and click depth from the front page.

**Cost control, if you use a key**

Keys are yours and are stored encrypted. A live ticker shows tokens and estimated cost while a scan runs, the AI Keys tab breaks usage down per key and per model, and a monthly spend cap pauses AI calls before you overshoot it. Everything the model is given is a setting: how much of each page it reads, how many possible destinations it is shown, and how much it is told about each one. A live estimate on the Setup screen prices a full scan as you change them, so you can see what a change costs before you commit to it rather than after.

**Coming next**

* Inbound suggestions ("which posts should link TO this page") with an orphan fix-it flow, an editor sidebar, and Elementor auto-apply.

== Installation ==

1. Upload the `ai-internal-linking` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Follow the setup screen: confirm scope, then run **Index / Re-index site** and **Scan for suggestions**.
4. Review results under **AI Linking → Suggestions**.

== Changelog ==

= 0.21.2 =
* Corrected a wrong pointer added in 0.21.1: the formulas document said "Minimum relevance" lives on the Setup screen. It is on the Settings screen. Every other screen reference in both documents was checked against the code and is correct.
* The overview document now also explains the relevance floor and that it governs the Related Content engine alone, so the two documents say the same thing about it.

= 0.21.1 =
* The Settings screen now explains what relevance actually is. It said only "discard candidate links below this relevance score (0-1)", which does not say what relevance measures, how it is decided, or why one link scores higher than another. Each field now opens with a short bold heading, in the same style as the Setup screen.
* It also corrects what that setting does. The wording implied it filtered every suggestion; it only ever applied to the Related Content engine. AI and Search Console suggestions are judged on entirely different evidence and were never affected by it, which is now stated plainly.
* Added the thing all three screens were missing: relevance is worked out differently by each engine, so the numbers are not comparable with each other. Search Console starts every match at 0.50 because a phrase people genuinely search for is stronger evidence than any word overlap. The AI reports its own confidence. Related Content measures the share of a page's distinctive vocabulary that the other page also uses, and normally lands between 0.08 and 0.50 — so a 0.85 from the AI and a 0.85 from word comparison mean entirely different things. Both documents now carry this as a table, and the review screen already labels the source of every suggestion.
* Both documents were still describing the old naturalness formula, including the line "add 0.30 x relevance" that 0.21.0 removed, and the worked example still arrived at the old total. Both are corrected, and the example now works through to 0.61.
* The flowchart gains the relevance floor and the wording length, says what the score in the review screen is made of, and corrects one row that described the per-page suggestion limit as fixed when it is a setting.

= 0.21.0 =
* The confidence figure on every suggestion now means something. It is meant to blend two independent judgements — is this link worth making, and does this wording read well — weighted 60/40. It did not. Naturalness started from "0.45 + relevance x 0.30", so it was largely a restatement of relevance rather than an opinion about the anchor, and relevance was therefore counted twice: 0.6 directly and another 0.12 through naturalness. The real weighting was 0.72/0.28, and the second number contributed almost nothing.
* Measured on 170 real suggestions before changing anything: naturalness minus 0.30 x relevance came out at exactly 0.700 on 161 of them, so the anchor-shape part took three distinct values across the whole set, and confidence correlated with relevance at 0.99 — it was telling a reviewer nothing that relevance had not already said. After the change that correlation is 0.88, and the pile-up in the top band fell from 24% of suggestions to 12%.
* Naturalness now judges the anchor and nothing else: how many words it is, and how long it is. Anchors that are genuinely alike now score alike, which is the honest outcome — every anchor the free engine builds is a two-to-four word phrase from a page title, so they should score the same, and ranking among them falls to relevance where it belongs.
* Two things in that scoring were wrong for any site not written in English. Length was measured in bytes, so a three-character Devanagari or Arabic anchor measured nine and escaped the too-short penalty, sometimes collecting the good-length bonus instead. And a single-word anchor was denied the phrase bonus, which permanently marked down every suggestion on a Chinese, Japanese or Thai site, where words are not separated by spaces. Length is now counted in characters, and a one-word anchor is treated neutrally rather than punished.
* Suggestions already stored are rescored during the upgrade, so the inbox does not show two different scales side by side while you wait for the next scan. Both numbers are recomputed from the anchor text and relevance already on the row, so nothing is estimated and no content is touched.
* The relevance weighting is now overridable with the ailinking_relevance_weight filter, and the anchor score with ailinking_naturalness.
* Covered by 10 new unit tests, 346 in total, including that naturalness no longer moves when relevance changes.

= 0.20.2 =
* Fixed a way that applying a link could damage a page. When deciding where an anchor may be placed, the plugin split your content into tags and text by ending each tag at the first ">". A ">" written inside a quoted attribute — "<img alt=\"a > b\">" — therefore ended the tag early, and everything after it was treated as ordinary body text. If the anchor phrase happened to sit there, the link was spliced inside the attribute, breaking the tag.
* The check meant to prevent exactly this could not see it. It compares the visible text before and after the change, and the function it uses to strip tags mis-reads the same construct in the same way, so both sides matched and the broken markup was saved. Anything already applied is reversible with Undo, which keeps the original content.
* Tags, comments and processing instructions are now recognised with the quoting rules that actually apply, so a ">" inside an attribute no longer ends a tag. A genuine occurrence elsewhere on the page is still linked normally; only the attribute is now correctly out of bounds.
* Covered by 15 new unit tests, 336 in total, including that nothing is ever spliced inside an attribute or a comment.

= 0.20.1 =
* Fixed the check that decides whether a database upgrade landed. A stray control character in the pattern meant the filter that skips index definitions never matched, so "PRIMARY KEY" and "UNIQUE KEY" were read as if they were column names. Since no table has columns by those names, every upgrade concluded the schema had not been applied, spent three futile retries, and then recorded a list of missing columns that were never columns at all. The schema itself was always correct and no data was affected; the damage was three wasted attempts per upgrade and a misleading warning.
* The reason it shipped is worth recording: the parser had a test, but the test contained its own copy of the logic instead of calling the plugin's. It passed while the shipped code was broken. It now calls the real method, and asserts specifically that PRIMARY KEY, UNIQUE KEY, KEY and INDEX lines are never mistaken for columns.
* Covered by 30 new unit tests, 321 in total.

= 0.20.0 =
* Search Console imports no longer store the same phrase twice. Search Console reports a phrase for a page across several date ranges and property variants, and each report was stored as a new row: one site had 107 duplicated groups, several repeated eight times. Because the engine only ever looks at the 500 most valuable phrases, every copy was taking a slot that a different phrase should have had. A unique key now makes a repeat update the existing row instead, duplicates already stored are removed on upgrade, and unmapped phrases are recorded against page 0 rather than NULL, since MySQL treats every NULL as distinct and would have let them keep duplicating.
* Fixed a database upgrade that could retry for ever. 0.19.1 stopped the version being recorded before the tables really changed, which was right, but if a host refuses the change outright — no permission, a locked table — nothing ever succeeded and the upgrade ran on every single admin request, making the whole admin slow. It now gives up after three attempts, records the version, and stores which columns are missing so the problem can be seen rather than felt.
* The Suggestions screen no longer offers bulk actions that cannot work. On the Applied tab every option was a no-op — applied rows are excluded from bulk status changes by design, and re-applying one fails — so choosing anything returned "0 of N done". Each tab now offers only what can succeed there, and the bar is hidden entirely where nothing can.
* "Max internal links per 1,000 words" was editable on two screens at once, both writing the same setting. Changing it on Settings and then saving Setup & Dashboard from a page loaded earlier silently put the old value back. Settings is now the only place it is stored; Setup shows the current value with a link across.
* An upgrade no longer overturns a deliberate choice. Sites that had picked "Title only" before 0.19.0 expressed it as zero words per destination, and the new description setting defaulted them to summaries — changing behaviour, and the bill, without being asked. That choice is now carried over.
* The two length dropdowns beside "How each destination page is described" had no label of their own, so a screen reader announced them as unexplained dropdowns. Both are now labelled.
* The key form hides fields the chosen provider has no use for. "Base URL" and "Azure api-version" appeared for every provider, explained away by a hint; they now appear only where they apply.
* Bulk actions report anything the server discarded. A request above the 50-id ceiling had the remainder dropped in silence, so a partial run looked complete.
* Removed the keyword_map table. It has been created on every install since the first release and read by nothing; the keyword engine joins the keywords table to the index directly. It is dropped on upgrade alongside the other retired tables.
* Documentation: both presentation documents said version 0.15.1, five releases behind. The engines were numbered "1 of 3", "2 of 3" and "3 of 3" in an order that contradicted the run order stated a page earlier, so they are now named rather than numbered, each saying where it runs. One sentence still said the model is shown "titles and words" after the surrounding section had moved to summaries. The Setup screen controls are now named where the mechanism is explained, so a reader can find them. And the step describing the free engine's vocabulary dropped the distinction between the 40 words taken from the page being read and the 300 from each page being considered.
* Covered by 4 new unit tests, 291 in total.

= 0.19.4 =
* Fixed: a summary could stop in the middle of a name. A full stop after an initial was read as the end of a sentence, so "headed by Sardar Vallabhbhai Patel, with V. P. Menon as its secretary" was cut to "...with V." and the summary trailed off mid-name.
* The rule is structural rather than a word list: a full stop preceded by a single standing letter is punctuation inside a sentence, which holds wherever initials are written that way. A short list of English abbreviations (Dr., Prof., etc.) follows as a secondary hint and can be replaced through the ailinking_sentence_abbreviations filter, since no list can cover a language it was not written for.
* Covered by 9 new unit tests, 287 in total.

= 0.19.3 =
* Summaries no longer lean on knowing English. 0.19.2 kept exam wording out of summaries using a list of English phrases, which is no use to a site in another language or one whose pages are laid out differently. That list is now a secondary hint, and three structural signals do the real work — none of them needs to know the language or the page format.
* A question is now recognised by its question mark, including the full-width, Arabic and Thai forms, so a question in any language is spotted whatever its wording.
* A label is recognised by a colon in its opening few words. "Weather effects: stable air, fog, frost and trapped smog" is a caption and its list, not a statement about the page, and that shape is the same in any language. This also removed the ragged opening fragments that summaries occasionally began with.
* Template furniture is recognised by what it is made of. A sentence built mostly from wording the whole site uses is the site's own frame with a few topical words dropped in. Measured across a real site, pure furniture scored 0.86 to 1.00 on that ratio while genuine prose scored 0.00 to 0.14, so the two separate cleanly with no phrase list involved.
* All of these lower a sentence's standing rather than banning it, so a page that has nothing else still gets described.
* Covered by 14 new unit tests, 278 in total.

= 0.19.2 =
* Fixed: on pages built around questions, the summary described the question instead of the page. Exam and quiz scaffolding — "Consider the following statements", "With reference to…", "Assertion (A):" — contains the page's real subject words, so it scored well and was chosen. A page on the Non-Cooperation Movement was being described to the AI as "UPSC Prelims 1998 GS Paper I. Consider the following statement and reason. Assertion (A): Gandhi stopped the Non-cooperation Movement in 1922."
* The more serious half of the same fault: the sentences immediately after such a stem are the multiple-choice options, and in a multiple-choice question some options are deliberately FALSE. One live page was described with "The earth loses energy to space mainly as short-wave radiation" — the exact misconception that page exists to correct. Nothing about the sentence itself gives it away; it reads as an ordinary statement. Only its position after the stem does, so that is what is now used to spot it.
* Both are penalised rather than banned. A page that is genuinely all questions, an FAQ or a quiz, still gets a summary, because a question describes a page better than nothing does. They simply lose to any plain statement on the same page. The phrase list is overridable with the ailinking_question_markers filter, for sites whose questions are worded differently.
* Covered by 14 new unit tests, 264 in total, including that a deliberately false quiz option is never asserted as a description and that an all-questions page is still described.

= 0.19.1 =
* Fixed: a summary could be built from the wrong sentences. Summaries were selected at the maximum length and then cut back to the length you asked for, which looks equivalent and is not — the chosen sentences are put back into reading order before the cut, so trimming the tail could throw away the best sentence and keep a weaker one that merely appeared earlier on the page. On one page that meant leading with a quiz prompt instead of the definition. They are now built at the length actually in use, and stored summaries are cleared when you change the length or the description mode, so they can never be left over from a different setting.
* Fixed a database upgrade that could record itself as done without having run. The new version number was stamped whether or not the tables really changed, so if the migration ran against a half-updated copy of the plugin — which happens when files are uploaded one at a time, since the main file carrying the new version number can land before the schema file — the column was never added and never retried, and every query touching it failed quietly ever after. This actually happened on one deploy. The version is now recorded only once the tables carry every column the release declares; otherwise the next request tries again.

= 0.19.0 =
* Each possible destination is now described to the AI by a short summary of the page, instead of a list of key words. A word list is a poor description: it wastes room on near-duplicates such as "zone" and "zones", it strips out the relationships between ideas, and the unusual terms that actually distinguish a page often sit just below the cut. A summary carries all of that in the page's own sentences, and reads as English rather than as a bag of words.
* The summary is extracted, not written. The plugin scores every sentence on a page against that page's own distinctive vocabulary, which it already has from indexing, and keeps the two or three that represent it best. No AI call is involved, nothing is generated, and nothing is written into your content. It works the same on a brand-new site with no SEO plugin, no excerpts and no API key.
* Boilerplate cannot get into a summary, and that falls out of the method rather than being a special case. Words your whole site repeats are excluded before scoring, so a sentence built from them scores zero however often it appears. On a real site whose every page opened with the same "By the end you will be able to…" block, not one summary picked it up.
* Sentences that repeat a point are dropped, including paraphrases. Pages restate things in "key points" panels, and the restatement scores as well as the original, so both used to be chosen. Two sentences sharing most of their distinctive words now count as one, which is what stops a summary saying the same thing twice in different words.
* Summaries are built once, on first use, and stored. They are not built during indexing, because scoring a sentence needs to know which words the whole site uses and that is not known until the whole site is indexed — a page indexed first would otherwise be judged against an almost empty site, where nothing looks common and boilerplate scores as well as substance. A page's summary is discarded whenever its content changes.
* "How each destination page is described" on Setup & Dashboard offers a short summary (the new default), a list of key words (the previous behaviour), or the title alone, with a length for each. Pages too short to summarise fall back to key words on their own, so nothing is ever described by a bare title by accident. Overridable in code with the ailinking_llm_describe_mode and ailinking_llm_summary_words filters.
* Cost, measured on a 350-page site: a 40-word summary per destination works out about 25% more per scan than a 10-word list. The live estimate on the Setup screen accounts for the mode you pick, so the figure updates as you switch between them.
* Covered by 26 new unit tests, 250 in total, including that site-wide boilerplate is never summarised and that a restated fact appears only once.

= 0.18.0 =
* Fixed: the plugin could not use current-generation Claude models at all. Every request sent a `temperature` parameter, and newer Claude models reject it outright rather than ignoring it, so selecting one produced a bare "400 Bad Request" — which reads like a broken API key, not like a model that needs different options. The AI engine would simply go quiet. If a model refuses the parameter, the plugin now drops it, remembers that model refuses it, and retries once. The only thing lost is the determinism `temperature: 0` bought, and these models are consistent enough without it; a silent engine was much the worse trade.
* This is learned from the API's own answer rather than hard-coded against a list of model names, because a fixed list goes stale the day a new model ships and your install cannot be updated that same day. A brand-new model costs one rejected request, which bills nothing, and works from then on.
* The Anthropic model list on the Providers screen was a generation out of date. It now offers Claude Sonnet 5, Claude Opus 5 and Claude Haiku 4.5, with Sonnet 4.6 kept for anyone who wants it. Note the default changed: an install that never picked a model explicitly was falling back to the first entry, which was Sonnet 4.6 and is now Sonnet 5. If you had pinned a model, on the key or in Settings, nothing changes.
* Fixed a cost-reporting hole that mattered for the spend cap: Claude Opus had no pricing entry at all, so it fell through to the generic default and was costed at roughly a thirtieth of what it actually charges. Anyone running Opus with a monthly cap would have sailed straight past the limit while the plugin reported a fraction of the spend. Opus is now priced at $15 / $75 per million tokens.
* The model is now chosen from a list instead of typed. Both places that carry a model — the Settings screen and each key on the AI Keys screen — were free-text boxes with no validation and no hint of what was valid, so a typo produced the same misleading error as the bug above: a bare rejection from the provider that reads like a broken key. Both are now dropdowns of the models known for the selected provider, rebuilding themselves when you change provider. "Other model…" is still there for anything newer than your copy of this plugin, and a model already stored that the list does not recognise is preserved rather than quietly reset.
* Fixed: the words sent to describe each destination page were not filtered the way the Setup screen said they were. The screen claimed "common words your whole site uses are already filtered out"; they were not. The rule that ignores a word appearing on more than 40% of pages is applied when scoring relevance, not when the words are stored, so reading the table directly handed the model exactly the words the free engine had already judged worthless. On a real 350-page site, "india" was among the top ten words of 131 pages and "correct" of 100, and 14% of everything sent was words that describe nothing — worse than wasted, because a word appearing in every description makes unrelated pages look alike. Both paths now share one definition of what counts as a site-wide word, and the list is cached for an hour and cleared whenever the index is rebuilt.
* Clearer wording on the Setup screen. "Possible destinations shown to the AI" is now "Potential Destination Pages shown to AI", and "Words describing each destination" is now "Words describing each destination page", with an explicit note that those words describe the page being linked TO and never become link text. The anchor is always taken from the page being read and checked to appear there word-for-word; the destination words only influence which page gets chosen. The old label could be read as being about the link text itself.
* Worth knowing, because it is easy to be caught by: a model set on an individual key overrides the model set in Settings, for every call made with that key. The key form now says so, and both fields default to "Provider default" rather than looking blank-but-meaningful.
* Covered by 31 new unit tests, 219 in total, including that an auth failure, a rate limit and an unrelated 400 are never mistaken for a parameter problem.

= 0.17.0 =
* Bulk actions in the Suggestions inbox. Every row now has a checkbox, the header has a select-all, and a bulk bar above the table offers Approve, Reject, Move back to pending, and Apply to content. Reviewing a large scan used to mean one click per suggestion with a full page reload after each; a 300-suggestion queue was an afternoon. This is the same set of operations, in one pass.
* Bulk apply writes links for real, and takes no shortcuts to do it. Each row goes through exactly the same code as the single-row Apply button: the suggestion is atomically claimed so two applies cannot collide, a WordPress revision and an independent undo ledger entry are written before the post is touched, the page is re-checked for edits made since the scan, and the result passes a visible-text integrity check or nothing is saved. Bulk here means fewer clicks, not fewer safeguards.
* Because applying is partial by nature, the result is reported honestly rather than as a single number. Page-builder pages are skipped rather than rewritten, a page edited since the scan is refused, and a row someone else already applied is passed over. You are told how many succeeded, how many were skipped, and the reason for each kind of skip in plain words. The confirmation box also warns you up front how many of your selection sit on page-builder pages.
* A bulk status change can never touch a link that is already live in your content. Only pending, approved and rejected rows are eligible; applied rows are excluded at the database level, because flipping one back to pending would leave the row disagreeing with your content and orphan the ledger entry that powers Undo. Removing an applied link remains Undo's job.
* Large selections are processed in chunks of 25, so a long run cannot hit the PHP time limit half way through with no report of how far it got, and the count climbs while it works. The server independently caps any single request at 50 ids.
* Covered by 29 new unit tests, 188 in total.

= 0.16.0 =
* The AI engine now tells the model what each possible destination is about, not just what it is called. Until this release the model chose a destination from its title alone, which is a thin thing to judge a page by: two posts both called "Getting started" were indistinguishable to it, and a page whose title says little about its subject was effectively invisible. Each entry on the shortlist now carries that page's own most-used words alongside its title, so an entry reads "12. Getting started — onboarding, checkout, refund, subscription" instead of "12. Getting started". This was the single biggest limit on suggestion quality, and at the default of 10 words it costs about 200 extra tokens per page to fix.
* Those words come from the index built during "Index / Re-index site", not from re-reading anything, so this adds no scanning time — only the tokens, which the estimate on the Setup screen now shows you before you commit to them. Words your whole site uses are already filtered out during indexing, so what is sent is what actually distinguishes one page from another. The new "Words describing each destination" control sets how many, from Title only (the old behaviour, still available) up to 30. The default is 10.
* How many possible destinations the model is shown is now yours to set too, from 5 to 200, with presets at 10, 15, 25, 40 and 60. It was fixed at 15. A short list is cheap but may not contain the right destination at all; a long one costs more and invites weaker picks, because a page ranked fiftieth by wording is on the list precisely because it has little in common with yours. The default stays at 15, so upgrading changes nothing until you decide otherwise.
* Fixed a quiet defect this exposed. The shortlist was worked out by fetching 18 candidates and showing 15, the three spares covering pages that get filtered out afterwards (the page itself, anything it already links to, any pair you have already judged). On a well-linked page more than three were filtered away and the model was silently shown a shorter list than intended — the pages with the most established linking, where good suggestions are hardest to find, were the ones given the least to work with. The headroom is now proportional, half again plus a fixed margin, and it scales with whatever shortlist size you choose.
* Setup & Dashboard shows a live cost estimate beside the three AI controls: tokens per page and the money for one full scan of your indexed pages, priced for your configured model, updating as you change any of them. Previously the only way to find out what a setting change cost was to run a scan and read the ticker afterwards. Both new settings are clamped on save and on read, so a value can never be stored that the plugin would then reject, and both are overridable in code via the ailinking_llm_candidates and ailinking_llm_candidate_words filters.
* Covered by 64 new unit tests, 159 in total.

= 0.15.1 =
* The live token counter is now four labelled figures instead of a sentence. It used to read "Tokens: 39.8k in, 5.0k out, est. $0.1950 over 19 AI requests", which is a lot to parse while watching a scan move. It now shows the input tokens, output tokens, estimated cost and request count as separate values, each under its own label, in a bordered strip beneath the progress bar. The figures use tabular digits so they do not jitter as they tick upward. The markup is built on the server, so the version drawn when the page loads and the version drawn live during a scan come from the same code and cannot drift apart, and it stays translatable.
* Documentation: added a plain answer to a question the docs never addressed, namely how the plugin reads your site. It reads your content internally, from the WordPress database, and never requests your own pages over the internet the way a search crawler or an AI bot would. That is what makes indexing fast, keeps load off your front end, works on staging and password-protected sites, and cannot be fooled by page caching. The trade-off is stated too: content that only exists when a page is rendered, such as theme-added sections or another plugin's shortcode output, is not seen. Now covered in the readme, the GitHub README and the overview document.

= 0.15.0 =
* How much of each page the AI reads is now yours to choose. Setup & Dashboard gains a "Words per page sent to the AI" control with presets from 500 to 3,000 words, plus a Custom option that accepts any value up to 20,000. Previously this was fixed at about 1,000 words: on a 3,000 word article the AI engine saw roughly the first third and was blind to the rest, so its suggestions clustered near the top of long posts. The setting is expressed in words rather than characters because that is the unit you can actually judge a page by.
* This is the main cost lever, and the screen says so: every post in a scan is one request, so the token bill scales almost linearly with the number. Doubling the words roughly doubles the input tokens. The default stays at 1,000 words, exactly matching the previous behaviour, so upgrading changes nothing about what you are billed until you decide otherwise. Worth knowing when picking a number: a page can only supply the words it actually has, so setting this above your longest article simply means "send the whole page". The 20,000 ceiling on custom values is not a cost cap but a safety one, since a mistyped number that exceeds a model's context window fails the request and still bills for the attempt. Overridable in code with the ailinking_llm_max_words filter.
* Worth being clear about scope: this affects ONLY the optional AI engine. The free engines are unchanged and always read the entire page. GSC keyword evidence scans the whole body for ranking-keyword mentions, and Related Content builds its term vector from the complete text. The anchor check is also unaffected: a proposed anchor is verified against the full page, never the excerpt.

= 0.14.2 =
* Security hardening: API keys can no longer be captured out of a provider's error message. Error text comes back from someone else's server and was stored verbatim in the key pool's last_error column, and some APIs quote the submitted credential in failure messages ("Incorrect API key provided: sk-..."). One such reply would have written a plaintext key into the database, and into every backup taken afterwards, defeating the encryption that protects the key everywhere else. Nothing was exposed in the admin screens, and no key is known to have leaked; this closes the path before it can be used. Error text is now scrubbed in two layers: the exact key used for the call is removed regardless of its format, which also covers custom and self-hosted endpoints whose key shape nothing could anticipate, and recognised credential shapes (OpenAI, Anthropic, Google, Groq, xAI, Perplexity, Hugging Face, Replicate, bearer tokens, long opaque blobs) are matched and removed even when the plugin did not send them. Ordinary diagnostics are left readable, so "Rate limit exceeded, try again in 20 seconds" still reads exactly like that, and identifiers short enough to be useful, such as request UUIDs, are preserved. Covered by 17 unit tests.

= 0.14.1 =
* Fix: the clusters and cluster_members tables were never actually removed from upgraded sites. Version 0.11.0 retired the Clusters feature but only dropped its tables on uninstall, so every site that upgraded in place has been carrying them ever since, and 0.14.0's new retired-table cleanup only listed the embeddings table. Both are now dropped on upgrade alongside it (DB version 1.4.0 to 1.5.0). Found by a post-deploy database check, not by anything user visible: the tables were inert, just wasting space.

= 0.14.0 =
* Removed embeddings entirely. The semantic re-ranker, the "Build embeddings" button, the Embeddings provider setting, the "reuse the chat provider for embeddings" option, the per-key Chat/Embeddings/Both selector, and the embeddings database table are all gone. Reasoning: Anthropic has no embeddings API, so running Claude meant either keeping a second vendor key purely for re-ranking or leaving the whole feature switched off. It was a permanently dark code path on this site, and the generative "AI link suggestions" engine already delivers semantic understanding from the same Claude key that is configured anyway.
* What still works exactly as before: the free relevance engine, GSC keyword-evidence suggestions, AI link suggestions through any chat model, the review inbox, apply and undo, Link Health, the token usage ticker and the spend cap. Suggestion quality on this site is unaffected, because the re-ranker only ever ran when an embeddings provider was configured.
* Upgrade behaviour: the embeddings table is dropped automatically on upgrade (DB version 1.3.0 to 1.4.0), reclaiming whatever vectors had been stored. Existing keys saved as embedding-only are left untouched but are no longer used, and the AI Keys table now labels them "unused (embeddings removed)" so they are easy to spot and delete. Voyage AI, which was embeddings-only, is no longer offered as a provider.

= 0.13.0 =
* Token usage is now visible. A live ticker under the progress bar shows tokens in, tokens out and estimated cost accumulating while a suggestion scan or an embedding build runs, so you can watch the burn instead of guessing. It survives pause, resume and page reloads, because the counter is anchored to the server-side run rather than the browser tab. The AI Keys table gains "Tokens in (mo)" and "Tokens out (mo)" columns per key, and a new "Token usage" card shows this month and all time side by side plus a per-model breakdown split by chat and embedding, which have very different cost profiles. Nothing new is collected: every provider call already reported its own token counts, they were simply never shown.
* Fix: estimated cost was overstated, often by more than 20x. Each call's cost was rounded UP to a whole cent before being recorded, so a typical 2,400-token request costing about 0.04 cents was logged as a full cent. Costs are now stored at full precision, and the monthly spend cap reads the same precise figures, so a cap set at a few dollars is no longer tripped at a fraction of real spend. The cap itself still rounds up when deciding, so it can never be under-enforced.
* Changed: the keyword opportunity score no longer rewards keywords you already rank first for. The old formula weighted impressions toward position 1, so a query already sitting at #1 scored highest and consumed the link budget, even though that page needs another internal link least, and it worked against the striking-distance bonus it was paired with. Opportunity is now the extra clicks a keyword could realistically win: the CTR gap between the top 3 and your current position, floored at zero so positions 1 to 3 score nothing, multiplied by a reach factor that decays past position 20 because one internal link will not lift page 5 into the top 3. The score now peaks in the striking-distance band instead of at the top of it. NOTE: existing keyword rows keep their old scores until you re-fetch from Search Console or re-import your CSV, which recomputes them.

= 0.12.1 =
* Renamed the "Content match" source filter to "Related Content".

= 0.12.0 =
* Scan controls: the suggestion scan now has Pause, Resume and Stop buttons (on both the Setup dashboard and the Suggestions tab). Pause it to go review what has been found so far, then Resume to continue from exactly where it stopped — the scan position is saved on the server, so you can even navigate to the Suggestions tab and back and still resume. Stop ends the run while keeping everything found. Starting a fresh scan asks for confirmation first (it replaces the current Pending suggestions).

= 0.11.4 =
* Fix: "Reset all data" no longer removes your saved API keys. Reset is meant to clear scan data (index, suggestions, link graph, keywords, embeddings) so you can rescan, but it was also emptying the key pool, forcing you to re-enter your key every time. It now preserves your API keys and their spend history, your settings, and the Search Console connection — only scan data is cleared. Copy on the Reset card updated to say so.

= 0.11.3 =
* Suggestions inbox: renamed the "Confidence" column to "Relevance" (with a tooltip clarifying it is the overall score blending relevance and naturalness). Added a Source filter row — All / AI Suggestion / GSC keyword / Content match, each with a live count — so you can quickly see and review suggestions by where they came from. The filter combines with the status tabs (Pending/Approved/…) and paginates correctly.

= 0.11.2 =
* Renamed the "Keywords" tab to "Connect GSC" (Search Console is the primary source now). Removed the Semrush import option — Google Search Console (plus generic CSV as a fallback) is enough. In the review inbox, both suggestion badges are now blue: the generative one reads "AI Suggestion" and keyword-evidence suggestions read "GSC keyword".

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
