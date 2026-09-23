# Prosetta background mode: design

**Date:** 2026-09-23. **Status:** for review. **Builds on:** the resilience layer (`2026-09-23-resilience-layer-design.md`). **Addresses:** G3, G4, G5 (glossary), G6 (in part), G8 (durable budgets), G9 (notifications), and the follow-ups in `2026-09-23-automation-gaps-and-edge-cases.md` §3.

## 1. Goal

Site translations that keep themselves current with nobody running commands:
- a language marked **auto-translate** gets new strings drafted by a scheduled cycle;
- when an English string is **edited**, every language that already has it gets a **minimal update**: only what the edit requires changes, and a purely cosmetic edit costs nothing;
- what passes every check is approved and exported to the lang files (configurable), so the **git diff is the review step** and nothing ships until someone commits;
- spending stays inside budgets that survive a cache clear, and someone is **told** when something needs attention or the cycle stops running.

**Where it runs (decided):** the dev machine (`composer dev`) and CI, where the lang files live. Production never calls the AI for site copy. Member content (spec §16b.7) is a separate, later design.

**Out of scope:** Bridge review pages (Step 7), named engines, money budgets, member content, and the English source's cohort/class wording (a copy decision).

## 2. Language settings in the database

A new Prosetta migration adds to the locales table (`prosetta_locales`):

| Column | Type | Meaning |
|---|---|---|
| `auto_translate` | boolean, default false | The cycle drafts missing keys for this language. |
| `style_note` | text, nullable | Sent with every batch for this language (register, regional neutrality, tone). |
| `glossary` | json, nullable | A list of `{source, target, banned}` entries, e.g. `{"source": "cohort", "target": "دفعة", "banned": ["فوج", "مجموعة"]}`. `source` is matched case-insensitively on word boundaries; `banned` is optional. |

- **The migration ships in two forms**, like the existing ones: in the package's `database/migrations` (and its publish tag) for new hosts, and as an `add_…_columns` migration for hosts that already have the locales table.
- **`Locale`** gains the casts, `fillable` entries and an `autoTranslate()` scope. `LocaleDescriptor` gains `?string $styleNote` and `array $glossary`. Both the database and config locale sources fill them. A regional code with no note of its own falls back to its language's note (as Undaunted's driver does today).
- **`TranslationBatch`** gains nothing new: drivers read `$batch->target->styleNote` and `$batch->target->glossary`.
- **Moving Undaunted's notes:** a one-off step in the Undaunted plan copies `config/translation.php`'s notes and the cohort terms into the new columns, then removes the file. Undaunted's driver reads the descriptor instead of config. Because the notes are read on every call, changing one needs no worker restart (G4).

## 3. Glossary check

A new `GlossaryGuard` runs after `PlaceholderGuard` in the runner:
- for each entry whose `source` appears in the English, the translation must contain `target`. If it's missing, that's a **warning**: `glossary_missing`;
- if the translation contains any `banned` term, that's an **error**: `glossary_banned`. It gets the normal single retry with feedback ("use دفعة, not فوج"), then stays a flagged draft.

Warnings block auto-approval (§6) but not export under `include_drafts`.

## 4. Update mode for edits

**Keeping the old English, per translation.** The translations table gains `approved_source_value` (text, nullable): the English the approved value was made from. It's written whenever a translation becomes approved: approve, edit-and-approve, write-and-approve, confirm, and the import of existing target files (the English at import time). Keeping it per translation, not per key, matters: if Spanish was updated after one edit and Arabic after none, each language's update is diffed against the English that that language was actually made from. Rows approved before this change have it null, and fall back to an ordinary re-translation.

**Classifying the change** (`Translation\SourceChange`, no AI, per stale translation: its `approved_source_value` against the key's current English):
- **Cosmetic:** the old and new English are identical after normalizing whitespace, straight/curly quotes, dashes, trailing punctuation and letter case. Each stale translation is **confirmed**: the approved value is re-approved against the new source hash, keeping its origin and logging a new `ReviewAction::Confirmed`. No AI call.
- **Substantive:** anything else, including spelling. The key goes to the model in update mode.

**The update prompt.** `TranslationItem` gains `?string $previousSource`. When a stale translation has an `approved_value` and an `approved_source_value`, the runner sets `previousSource` to the latter. Drivers then send the old English, the new English, a word-level diff (`SourceChange::diff()`, computed without AI) and the current translation, with the instruction: *edit the existing translation only as much as the change in the English requires; keep every other word, phrase and punctuation mark exactly as it is.* Undaunted's Translator agent learns this item shape.

**Rewrite check.** After an update, `SourceChange::ratio(oldSource, newSource, oldTranslation, newTranslation)` compares how much the translation changed with how much the English changed, as normalized word-level edit distances. If it's above `automation.rewrite_ratio` (default 3.0), and the translation's change is more than two words, the draft gets a **warning**, `large_rewrite`, and isn't auto-approved.

**Which languages:** every language with an approved translation of the edited key, whether or not it's auto-translate (decided). Keys a language never had are drafted only for auto-translate languages.

## 5. Durable usage and budgets

- **A new table, `prosetta_usage`** (package migration): `id`, `run_id` (nullable), `circuit`, `locale`, `input_tokens`, `output_tokens`, `created_at`, indexed on `created_at` and `run_id`.
- **`ProviderGate`** writes a row per successful call, replacing the cache counters.
- **`Budget`** sums from this table: daily and monthly by `created_at` in the app time zone, per-run by `run_id`. `BudgetReached` stays once per period, guarded by an atomic cache `add`. A cache clear can then re-send the event at most once, but it can no longer reset spending.
- **`--estimate`** reads the same table for history, fitting separate per-character and per-string rates (the follow-up from the Arabic run).
- **Circuits stay in the cache:** their state is temporary by nature. **Suspensions stay too**, because the cycle (§6) re-queues everything outstanding anyway, so a forgotten suspension costs at most one cycle's delay.

## 6. The cycle

`prosetta:cycle` runs on its own schedule. It is registered by the service provider when `automation.every` is set, with `withoutOverlapping()`. It can also run by hand, and `--sync` runs it inline for CI.

1. **Guard.** If the previous cycle's batch hasn't finished (its id is kept in `prosetta_state` under `cycle.batch`, so a cache clear can't lose it), log and exit. A cycle never overlaps another.
2. **Sync** all namespaces.
3. **Cosmetic edits:** confirm them (§4); no AI.
4. **Build the work:**
   - stale keys with an approved translation in **any** target language (update mode);
   - keys missing in **auto-translate** languages (draft mode).
5. **Queue** it as one run through `Translator` (so budgets, circuits and suspensions all apply). The batch's `finally` callback dispatches a `FinishCycle` job.
6. **`FinishCycle`:**
   - approve clean drafts from this run according to `automation.approve`: `'all'`, `'none'` (stop at drafts) or a list of language codes. Clean means no issues at all: no errors, no glossary or rewrite warnings;
   - export the languages that got approvals, when `automation.export` is true;
   - record the heartbeat;
   - raise `CycleCompleted` with a report: counts of drafted, updated, confirmed, approved and flagged strings, the flagged refs, the files written, and the tokens used.
7. **An empty cycle** (nothing to do) still records the heartbeat and raises `CycleCompleted` with zero counts.

**`--sync` for CI:** runs steps 2–6 inline and exits 1 when anything is flagged, stale or suspended, so a pipeline can gate on it. It exits 0 otherwise.

## 7. Heartbeat and health

- **A small `prosetta_state` table** (key, value, updated_at; package migration) holds `cycle.last_run` and `cycle.batch`, which a cache clear can't lose.
- **`prosetta:health`** exits 1 and says why when:
  - automation is on and the last cycle is older than 3 × `automation.every`;
  - any circuit is halted;
  - a daily or monthly budget is spent.

  It exits 0 otherwise.
- **`prosetta:circuit status`** gains the last cycle time.

## 8. Notifications (Undaunted)

Undaunted listens to Prosetta's events and sends one mail per incident to `PROSETTA_ALERTS_TO` (a new env value; no mail when it's unset):

| Event | Mail |
|---|---|
| `TranslationHalted` | "Translation halted ({reason}): {message}" |
| `BudgetReached` (daily or monthly) | "The {period} translation budget is spent" |
| `TranslationSuspended` with reason `outage` | "Translation provider down for over 6 hours" |
| `CycleCompleted` with flagged > 0 | A digest: counts plus the flagged refs, one mail per cycle |

An hourly scheduled `prosetta:health` mails when it fails, at most once a day for the same reason (a cache key).

## 9. Housekeeping and follow-ups

- **Undaunted:**
  - schedule `queue:prune-batches --hours=48`;
  - set `en_GB`'s auto-translate off;
  - set Spanish, Arabic and Chinese on.
- **One set of suspension-reason words:** a shared `SuspensionReason` enum (`outage`, `halted`, `rejected`, `quota`, `unknown`, `daily`, `monthly`), used by both the job path and the synchronous path.
- **The spec wording slips** (resilience spec §5 and §6).
- **`Translator::refs()`** orders by the key model's key name.
- **The merge test** reads the stored `started_at`.
- **A `--sync` report** keeps the first attempt's drafted refs and tokens when a batch rejection hits the retry pass.
- **Undaunted:** `capReachedBatch()` moves to `tests/Pest.php`.

## 10. Configuration

```php
'automation' => [
    'every' => null,          // minutes between cycles; null = no scheduled cycle
    'approve' => 'all',       // 'all' | 'none' (stop at drafts) | list of language codes that auto-approve
    'export' => true,         // write lang files after approving
    'rewrite_ratio' => 3.0,   // flag an update whose translation changed this many times more than the English
],
```

Undaunted: `every` 30, `approve` 'all', `export` true.

## 11. Bridge (Undaunted)

The Locales edit form gains:
- an **Auto-translate** toggle, next to Active;
- a **Style note** text area;
- a **Glossary** editor: one row per entry with fields for the English term, the translation and the banned alternatives (comma-separated), plus add and remove.

All of them are audited like the existing fields, and validated server-side: note ≤ 1,000 characters, ≤ 100 glossary entries, each term ≤ 100 characters. The source locale shows neither the toggle nor the note.

## 12. Testing

**Prosetta:**
- migrations: fresh installs, and the add-columns path;
- the locale source fills the note and glossary, including the regional fallback;
- `GlossaryGuard`: missing, banned, word boundaries, case;
- `approved_source_value` is written on every approval path (approve, edit, write, confirm, import), and two languages stale from different edits each get their own old English;
- `SourceChange` classify, diff and ratio;
- confirming a cosmetic change keeps the origin and logs `Confirmed`;
- update-mode items carry `previousSource`;
- the rewrite warning;
- usage rows and budget sums, which survive a cache clear;
- the cycle:
  - builds updates for every language and drafts only for auto languages;
  - skips when the previous batch is still running;
  - approves per `approve` (all, none, list);
  - exports only languages that got approvals;
  - records the heartbeat;
  - reports correctly;
  - under `--sync`, gives the right exit codes;
- `prosetta:health` for each failure reason;
- the follow-up fixes.

**Undaunted:**
- the driver sends the note, glossary and update fields from the descriptor;
- the Translator agent's instructions cover update mode;
- the Bridge form saves, audits and validates the new fields;
- each notification mail, with deduplication;
- the scheduled commands;
- end to end: edit an English string in a fixture, run `prosetta:cycle --sync`, and the Spanish file shows a minimal update while a whitespace-only edit confirms without a call.

## 13. Compatibility

- **All new columns** are nullable or defaulted. Existing drivers that ignore the note, glossary and `previousSource` keep working; they just translate afresh.
- **`automation.every` is null by default,** so nothing runs until a host turns it on.
- **Budget behavior is unchanged** apart from where it's stored. The resilience layer's cache counters are removed; any spending already counted in the cache for the current day isn't carried over, which is a one-time gap.
