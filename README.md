# AI Internal Linking

A universal WordPress plugin for AI-assisted internal linking. It indexes your whole site, then proposes contextual internal links that follow SEO and AI-search best practices. Every suggestion is reviewed by a human and gated, so nothing is ever inserted into your content automatically.

**Current version: 0.12.1** | Requires WordPress 6.2+ | Requires PHP 7.4+ | License: GPL-2.0-or-later

## Install

1. [Download the latest release zip](../../releases/latest) (`ai-internal-linking-vX.Y.Z.zip`).
2. In WordPress, go to **Plugins, Add New, Upload Plugin** and pick the zip.
3. Activate, then open **AI Linking** in the admin menu.
4. On **Setup & Dashboard**, confirm the scope, run **Index / Re-index site**, then **Scan for suggestions**.
5. Review what it found under **Suggestions** and approve the links you want.

To update an existing install, upload the newer zip over the old one, or replace the `ai-internal-linking` folder in `wp-content/plugins/`.

## What it does

The admin is a single page with six tabs:

| Tab | Purpose |
| --- | --- |
| Setup & Dashboard | Scope selection, indexing, suggestion scans (with pause, resume and stop), reset |
| Suggestions | Review inbox: approve, reject, apply, undo, filtered by status and source |
| Link Health | Orphans, dead ends, broken links, internal PageRank, anchor diversity, click depth |
| Connect GSC | Google Search Console fetch via a service account, plus generic keyword CSV import |
| AI Keys | Encrypted multi-key pool, per provider, with spend caps and health status |
| Settings | Engine toggles, link density limits, per site tuning |

Suggestions come from three engines, which run in order and are labelled in the inbox:

1. **GSC keyword** evidence. A page mentions a query another page already ranks for, without linking to it, so the ranking keyword becomes the anchor.
2. **AI Suggestion**, optional. Any chat model (Claude, OpenAI, Gemini, Groq, xAI, DeepSeek, OpenRouter, Perplexity, local) proposes links from a real shortlist of related pages. Candidate targets are constrained to pages that exist, and every anchor is verified to appear verbatim in the body before it becomes a suggestion.
3. **Related Content**, a local TF-IDF relevance engine, fills the rest.

## Design guarantees

These are deliberate constraints, not implementation details:

- **Works with zero AI keys.** The TF-IDF engine, link graph audits and gated apply/undo cost nothing and need no external calls. AI providers are an opt-in layer that degrades back to the free engine.
- **Bring your own keys.** There is no vendor backend. Keys are stored encrypted at rest (a data key wrapped by a salt derived key) and never leave your site except to the provider you configured.
- **Wrap first.** By default the plugin only links text that already exists in the page. It does not rewrite your prose.
- **Never destructive.** Applying a link writes a WordPress revision plus an independent backup ledger first, so one click undoes it. Uninstall restores content from that ledger.
- **Byte preserving writes.** Insertion is a raw HTML splice guarded by a visible text integrity check, never a DOM round trip, because DOM serialization silently corrupts real world HTML.
- **Never links headings**, existing links, code, or tag attributes, and never links across languages.
- **Universal.** Post types, taxonomies, page builders, WooCommerce and multilingual setups are auto-detected at runtime. Gutenberg and Classic get automatic apply; builder managed content is suggest only, so the plugin never writes `post_content` it does not own.

## Publishing a new version

Releases are automated. Tagging is the only manual step:

```bash
# 1. Bump the version in both places (they must match):
#      ai-internal-linking/ai-internal-linking.php   (header "Version:" and AILINKING_VERSION)
#      ai-internal-linking/readme.txt                ("Stable tag:" and a new == Changelog == entry)
# 2. Commit, then tag and push:
git commit -am "Short summary (vX.Y.Z)"
git tag vX.Y.Z
git push origin master --tags
```

Pushing the tag runs [`.github/workflows/release.yml`](.github/workflows/release.yml), which:

- checks that the tag matches the version in the plugin header and in `readme.txt`, and fails loudly if they disagree,
- builds `ai-internal-linking-vX.Y.Z.zip` with `git archive` (forward slash paths only, plugin folder only, no repo scaffolding),
- creates the GitHub Release and attaches the zip, using the matching `readme.txt` changelog section as the release notes.

The result is a permanent, shareable download link at `releases/latest` that anyone can install without a git client.

To build the same zip locally without tagging:

```powershell
powershell -ExecutionPolicy Bypass -File tools\build-zip.ps1
```

That writes `Plugin Zip files/ai-internal-linking-vX.Y.Z.zip` from the committed content of `HEAD`, then verifies it: forward slash paths only, exactly one top level folder, and an embedded version matching the header. Add `-AlsoDownloads` to refresh the copy in your Downloads folder, or `-Ref v0.12.1` to rebuild an older tag.

## Repository layout

```
ai-internal-linking/       the plugin itself, this is what ships in the zip
  ai-internal-linking.php  bootstrap, version constants, requirement guards
  includes/                PSR-4 sources, namespace AILinking\, no Composer
  assets/                  admin CSS and JS
  readme.txt               WordPress readme, the single source of truth for the changelog
  uninstall.php            ledger based content restore, then table drop
docs/                      plugin overview document
tools/build-zip.ps1        local release zip builder
.github/workflows/         release automation
```

Build artifacts (`Plugin Zip files/`, any `*.zip`) are deliberately not tracked. Released zips live on the Releases page, which is what a git repository is for.

## License

GPL-2.0-or-later, the same license as WordPress. See [LICENSE](LICENSE).

Made by Shikher Goyal.
