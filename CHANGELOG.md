# Changelog

All notable changes to Prosetta are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). Until 1.0, a minor version (0.2, 0.3) may include breaking changes; each one is listed under **Changed**.

## [Unreleased]

## [0.1.0] - 2026-09-26

The first public release: a rebuild of Prosetta as a headless translation workflow.

### Added

- **The workflow.** Your source-language lang files are the canonical keys: Prosetta reads them and writes only other languages. `prosetta:sync`, `prosetta:translate`, `prosetta:review` and `prosetta:export`, for root PHP and JSON files, module lang folders found through `loadTranslationsFrom()`, nested folders and regional variants.
- **AI drafting through your own driver** (`TranslationDriver`), with placeholder, plural and HTML checks, one guided retry, and per-translation provenance: provider, model, tokens and invocation id. `FakeTranslationDriver` for tests.
- **Per-language access** (`Translate`, `Review`, `Manage`) decided by one `Prosetta::authorizeUsing()` callback, plus Laravel gates. Optional ban on approving your own edits.
- **Background mode.** `prosetta:cycle` syncs, confirms cosmetic English edits without the AI, drafts, approves its own clean drafts and exports, on a schedule or in CI (`--sync`). Per-language `auto_translate`, style notes and glossaries, a hold on keys that keep failing or are rejected twice, and `prosetta:health`.
- **Spelling variants** such as `en_GB`, derived from the source by word replacement with no AI.
- **Resilience.** Provider error classes, backoff, per-provider circuits, pausing and `prosetta:resume`, token budgets per run, day and month, and events for every state change.
- **Costs.** Each call records its model; prices per model (`prosetta.ai.prices` or your own `PriceCatalogue`) give a cost per language and month.
- **A review core for your own admin**: `Viewer`, `ReviewQueue`, `KeyBrowser`, `Coverage` and `ReviewDesk`, with conflict detection when two people act on the same string, and read-only review outside development.
- **Database content.** Models implementing `TranslatableContent` with the `TranslatesContent` trait have their fields translated and reviewed like lang files, read with `translated()`. `prosetta:content:sync` and `prosetta:content:import`. Custom placeholder patterns.
- **Member reports** of bad translations, routed to review without overwriting anyone's work.
- **Production review.** Approve interface text on the live site and bring it back with `prosetta:pull`.
- **Source comments** are copied into translated lang files, above the same keys.

### Changed

- Requires PHP 8.3 or later and Laravel 12 or 13.
- `Locale` keeps only translation fields, gains `translated`, widens codes to 35 characters, and makes codes immutable.

### Removed

- The Blade UI, the legacy file-writing services, the queue table and the statistics seeder.

[Unreleased]: https://github.com/lonely-lights/prosetta/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/lonely-lights/prosetta/releases/tag/v0.1.0
