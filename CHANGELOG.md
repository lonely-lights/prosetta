# Changelog

All notable changes to Prosetta are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). Until 1.0, a minor version (0.2, 0.3) may include breaking changes; each one is listed under **Changed**.

## [Unreleased]

## [0.1.7] - 2026-11-25

### Fixed

- Approving re-runs the guard instead of trusting the issues saved when the value was imported or drafted, so a candidate held by an older check is approved once the guard accepts it. Only the guard's own issue codes (`PlaceholderGuard::CODES`) are re-checked; issues from other checks (glossary, reports, refusals, large rewrites) still block as stored, and nothing is written by the check.

Before, translations held by 0.1.5's plural check stayed held after upgrading to 0.1.6, because approval read the stored issues.

## [0.1.6] - 2026-11-25

### Fixed

- Plural checks now ask which counts a translation covers, not whether it repeats the source's exact range markers. Arabic's `{0}|{1}|{2}|[3,10]|[11,*]` and Chinese's `{0}|[1,*]` both cover English's `{0}|{1}|[2,*]`, so they pass. A count the translation really leaves out is still `plural_range_missing`, named by the source marker it falls in. A fully marked translation of an unmarked source (`:count day|:count days`) must cover every count from 0 up.
- A plural form for one exact count (`{1}`, `{2}`) may spell the number out instead of using `:count` (Arabic `{1} يوم واحد`, `{2} يومان`). Every other placeholder is still required in that form.
- A plain plural with as many segments as the language has forms (Chinese 1, Arabic 6) no longer warns `plural_segments_differ`; the warning stays for counts that match neither the source nor the language.
- Placeholders in explicit plural forms are checked against every source form whose counts they share, so a language may split or merge ranges.

Before, these correct translations stayed in review with errors, their approvals were lost on each `migrate:fresh`, and `prosetta:export` left them out of the generated files.

## [0.1.5] - 2026-11-07

### Fixed

- `prosetta:export` no longer stops on a key your source removed. In a file Prosetta generated, a key the source no longer has is dropped and named (`Dropped from lang/es/settings.php (no longer in the source): groups.limits.`), and the report lists it under `dropped`. Before, once the database was rebuilt (`migrate:fresh`), such a value had no translation row, sync could never import it, and every export reported a conflict until it was run with `--force`. Files without Prosetta's header, and JSON files, still report unknown keys as conflicts.

## [0.1.4] - 2026-09-30

### Fixed

- Comments inside lists now reach the translator: a note above `'scenes' => [[ 'k' => … ]]` or above one item of a list of strings is that key's context (`scenes.3.k`, `names.1`), numbered as Laravel numbers them. Before, such a comment was dropped or filed under the wrong key. Exported files keep comments on list items too.

## [0.1.3] - 2026-09-30

### Fixed

- `placeholders.terms` no longer flags a correct translation in a language written without spaces: Japanese and Korean put the name straight against the next word (`Undauntedは`), and only letters of the term's own script now count as part of the same word. A term that starts or ends with punctuation (`C++`) needs no word boundary on that side.

## [0.1.2] - 2026-09-30

### Fixed

- `exclude_paths` now leaves out single lang files, not only whole lang folders, as its documentation says: `'lang/*/legal.php'` keeps that file in the source language everywhere, and a sync retires the keys it already had. In 0.1.1 the docs recommended this before it worked.

## [0.1.1] - 2026-09-30

Lessons from the first site translated end to end with Prosetta (lonelylights.com, 15 languages).

### Added

- **`placeholders.terms`**: product and brand names that stay as written in every language, as plain words rather than regular expressions. Whole words, any case, so an all-caps heading is kept too.
- **Comments as context.** The comment above a key in a source PHP lang file becomes that key's context, sent to the AI and shown to reviewers, so a short string like "Copy" can say it's a button.
- **`Cost::$unpricedModels`** and the coverage report's `unpricedModelsThisMonth`: the model names that had no price, so "cost unknown" can say what to add to `prosetta.ai.prices`.
- `prosetta:sync` warns when `namespaces.include` or `namespaces.exclude` names no namespace, and when the name is a lang file, says to use `exclude_paths` instead.

### Fixed

- A key's kept tokens were only worked out again when its English changed, so adding a placeholder pattern did nothing for existing keys. `prosetta:sync` now refreshes them, and a key's context, without marking its translations as needing an update.

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

[Unreleased]: https://github.com/lonely-lights/prosetta/compare/v0.1.7...HEAD
[0.1.7]: https://github.com/lonely-lights/prosetta/compare/v0.1.6...v0.1.7
[0.1.6]: https://github.com/lonely-lights/prosetta/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/lonely-lights/prosetta/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/lonely-lights/prosetta/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/lonely-lights/prosetta/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/lonely-lights/prosetta/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/lonely-lights/prosetta/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/lonely-lights/prosetta/releases/tag/v0.1.0
