# AI Internal Linking

A universal WordPress plugin for AI-assisted internal linking. It indexes your whole site, then proposes contextual internal links that follow SEO and AI-search best practices. Every suggestion is reviewed by a human and gated, so nothing is ever inserted into your content automatically.

**Current version: 0.21.1** | Requires WordPress 6.2+ | Requires PHP 7.4+ | License: GPL-2.0-or-later

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
| Setup & Dashboard | Scope selection, indexing, suggestion scans (with pause, resume and stop), how much of each page the AI reads, reset |
| Suggestions | Review inbox: approve, reject, apply, undo — one row at a time or in bulk — filtered by status and source |
| Link Health | Orphans, dead ends, broken links, internal PageRank, anchor diversity, click depth |
| Connect GSC | Google Search Console fetch via a service account, plus generic keyword CSV import |
| AI Keys | Encrypted multi-key pool, per provider, with token usage, spend caps and health status |
| Settings | Engine toggles, link density limits, per site tuning |

Suggestions come from three engines, which run in order and are labelled in the inbox:

1. **GSC keyword** evidence. A page mentions a query another page already ranks for, without linking to it, so the ranking keyword becomes the anchor.
2. **AI Suggestion**, optional. Any chat model (Claude, OpenAI, Gemini, Groq, xAI, DeepSeek, OpenRouter, Perplexity, local) proposes links from a real shortlist of related pages, each shown as its title plus a short summary extracted from that page's own sentences. Candidate targets are constrained to pages that exist, and every anchor is verified to appear verbatim in the body before it becomes a suggestion. How much of the page it reads, how long the shortlist is and how much it is told about each destination are all settings, with a live cost estimate beside them.
3. **Related Content**, a local TF-IDF relevance engine, fills the rest.

## How it reads your content

**Internally, from the database. It never requests your own pages over HTTP.**

The indexer asks WordPress for each post directly (`get_post`, plus the builder's own stored data for Elementor, Beaver Builder and ACF), then strips tags, scripts, styles and every heading down to plain words. The only outbound HTTP this plugin ever makes is to Google Search Console and to whichever AI provider you configured. It never fetches your site.

That choice buys speed (no page requests), zero front-end load, correct behaviour on staging and password-protected sites, immunity to page caching, and the ability to index drafts. Its cost is that content which only exists at render time is invisible: theme-generated furniture, related-post blocks, and the output of other plugins' shortcodes. Shortcode tags are stripped and inner text kept, but shortcode *output* is not indexed.

That trade is deliberate. The plugin should only link words that live in your content, because those are the words you can edit, and an anchor placed in theme output would disappear with a theme change.

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

That writes `Plugin Zip files/ai-internal-linking-vX.Y.Z.zip` from the committed content of `HEAD`, then verifies it: forward slash paths only, exactly one top level folder, and an embedded version matching the header. Add `-AlsoDownloads` to refresh the copy in your Downloads folder, or `-Ref v0.14.0` to rebuild an older tag.

## Keeping a local copy of every release

Releases are built by CI, so the zip lives on GitHub and never lands on your disk. To pull the whole history down into `Plugin Zip files/`:

```powershell
powershell -ExecutionPolicy Bypass -File tools\sync-release-zips.ps1
```

Existing files are skipped, so re-running after each release only fetches the new one. That folder is gitignored: these are build artifacts, and GitHub Releases is the real distribution point.

## Deploying to a site

```powershell
python tools\deploy.py                  # dry run: connect and report versions
python tools\deploy.py --yes --prune    # deploy
```

Prefer `--yes --prune` for a real release. Uploading alone never removes files that were **deleted** from the plugin, so a release that drops a class would leave it orphaned on the server; `--prune` diffs the remote file list against the local one and removes the difference, scoped strictly to the plugin directory.

Site specifics live in `tools/deploy.local.json`, which is gitignored. Copy [`tools/deploy.local.example.json`](tools/deploy.local.example.json) to create it. The SSH key passphrase is deliberately **never** stored in that file: supply it through an environment variable, or point the script at an existing folder of automation scripts to discover from. It is held in memory only, and output masks the host.

The script is non-interactive by design, reading nothing from stdin, so it runs the same from a terminal or a tool. It lints every PHP file with the server's own PHP after uploading and reports a failure rather than leaving you guessing.

## Repository layout

```
ai-internal-linking/       the plugin itself, this is what ships in the zip
  ai-internal-linking.php  bootstrap, version constants, requirement guards
  includes/                PSR-4 sources, namespace AILinking\, no Composer
  assets/                  admin CSS and JS
  readme.txt               WordPress readme, the single source of truth for the changelog
  uninstall.php            ledger based content restore, then table drop
docs/                      plugin overview and scoring formulas
tests/run-unit.php         unit tests for the pure decision functions
tools/build-zip.ps1        local release zip builder
tools/sync-release-zips.ps1  pull every GitHub Release zip into "Plugin Zip files/"
tools/deploy.py            SSH deploy, config from gitignored deploy.local.json
tools/site-check.py        post-deploy health check: migration state, tables, errors
tools/error-scan.py        PHP fatal analysis, aggregated on the server
.github/workflows/         release automation
```

Nothing outside `ai-internal-linking/` ships in the release zip, so repository tooling, tests and docs stay out of what users install.

Build artifacts (`Plugin Zip files/`, any `*.zip`) are deliberately not tracked. Released zips live on the Releases page, which is what a git repository is for.

## License

GPL-2.0-or-later, the same license as WordPress. See [LICENSE](LICENSE).

Made by Shikher Goyal.
