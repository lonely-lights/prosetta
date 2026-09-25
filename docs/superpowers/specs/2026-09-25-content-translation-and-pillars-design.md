# Content translation, and database-driven pillars

Status: design, awaiting the owner's review. Replaces rebuild spec §12 (Spatie JSON columns).

## In plain words

Staff write content in English in the Bridge: pillar names, badge descriptions, role titles. Prosetta translates it the same way it translates the interface. The AI drafts, people review it on the same Review page, and the approved translations are shown to members in their language. It all lives in the database, so an edit on the live site shows up without a deploy.

The first content to use it is the 12 pillars. They move out of the code (an enum plus lang files) into a table that staff can add to, edit, reorder and retire from the Bridge. Their existing Arabic, Spanish and Chinese translations are carried over.

Two parts, built in order:

1. **Prosetta: content translation** (this package).
2. **Undaunted: pillars in the database**, the first user (coordinated with the spine session).

## Decisions

| # | Decision | Why |
| --- | --- | --- |
| C1 | Translations of content live in Prosetta's existing tables; no Spatie, no JSON columns on host models | One store, one review flow; staff edit live, and production can't write files |
| C2 | Keys follow the 2024 folder-per-model pattern: file = namespace `content`, group = the model's folder (default: its table); key = `<record key>.<field>` | Content is browsable by model on the Keys page, as the old `ProsettaEntries` laid it out |
| C3 | The record key is the model's route key (a slug where there is one), else its id | Readable keys that survive reordering; a slug change renames the keys, keeping their translations |
| C4 | Reading a translation is explicit (`$model->translated('name')`); plain attribute access always returns the English column | Saving a model can never write another language into the source column |
| C5 | Content keys are never exported to files | The database is their only store |
| C6 | Content can be approved in any environment, production included; the `editable` guard keeps applying to file keys only | The guard exists because file approvals must reach git; content approvals don't |
| C7 | Each translatable field carries a context note for the AI (the old `$modelDescription`) and an optional max length | Short fields like a badge name need that guidance |

## Part 1: Prosetta content translation

### Host model

```php
use LonelyLights\Prosetta\Content\TranslatesContent;

final class Pillar extends Model {
    use TranslatesContent;

    /** @return array<string, string> field => context for the AI */
    public function translatableFields(): array {
        return [
            'name' => 'The full name of one of the twelve pillars of the community.',
            'badge' => 'A one- or two-word short name shown on a badge.',
            'subtitle' => 'One sentence on how this pillar serves the whole.',
        ];
    }
}
```

Optional overrides: `translationFolder(): string` (default: the table name), `translationKey(): string` (default: the route key), `translationMaxLength(string $field): ?int`, `shouldTranslate(): bool` (default: true; for example, false for drafts).

### What happens when

- **Saved** (created or updated): for each translatable field whose English changed, the key's source value and hash are updated (creating the key the first time). Existing translations go stale, exactly like a changed lang string. The next `prosetta:cycle` drafts the rest; a `queueContent()` call on the model translates it sooner.
- **Record key changed** (a slug edit): the keys are renamed in place, so the translations follow.
- **Deleted**: its keys are marked obsolete, as removed lang strings are.
- **`shouldTranslate()` false**: its keys are obsolete until it turns true.

All of this runs after the host's transaction commits, so a rolled-back save leaves no keys behind.

### Reading

- `$model->translated('name')` gives the approved value for the current locale, falling back to English. `translated('name', 'es')` names the locale.
- `$model->translations()` gives every translatable field for the current locale, for Inertia props.
- Approved values are cached per folder and locale, and the cache is cleared when a value in that folder is approved or its key changes.
- Derived locales (en_GB) apply to content as well.

### Review, Keys, cycle

- The cycle, the AI drafting, the glossary, the style notes, the checks and the budgets all treat content keys like file keys.
- Review and Keys show content under `content/<folder>`. The Keys matrix, File view and search work on them unchanged.
- Export skips content keys. The review guard (C6) lets content be approved in production.
- `prosetta:content:import <folder> <locale> <lang-file>` imports an existing lang file's values as approved translations of matching keys. It's used once, to carry the pillars' translations over.

### Tests (package)

Tested with a fixture model in Testbench:
- save creates keys, and an English edit makes them stale;
- a slug rename keeps the translations;
- delete marks the keys obsolete;
- a rolled-back transaction leaves no keys;
- the fallback returns English;
- the cache clears on approve;
- export skips content;
- content is approvable when the file guard says read-only;
- import maps values onto keys.

## Part 2: Undaunted pillars in the database

Agreed with the spine session: slug is the reference, migrations are edited in place (pre-launch), and this lands before spine plan 3 starts building. Nothing merges without the owner's named go.

- **Table `pillars`**: `slug` (unique; referenced by `cohort_pillars.pillar` as a foreign key), `name`, `badge`, `subtitle`, `sort`, `retired_at`, timestamps. Retired pillars stay attached to existing cohorts but can't be chosen for new ones. Pillars are never deleted.
- **Seeder**: the 12 current pillars with today's English, then `prosetta:content:import` for `ar`, `es` and `zh-CN` from today's `lang/*/pillars.php`, after which those files are removed.
- **Code that changes**:
  - `app/Enums/Content/Pillar.php` becomes `app/Models/Pillar.php`;
  - the "community cohort needs at least one pillar" rule becomes an existence check against active pillars;
  - `ReadsPillarOption`, `CreateCohortCommand` and `ScheduleCohortCommand` read the table;
  - `PillarValue` in `resources/js/types/content.ts` becomes a string, and the pages get pillar data from the server;
  - the mediums registry keeps naming pillars by slug and is checked against the table.
- **Bridge page** (Content Array group): list, add, edit, reorder, retire and restore. Editing uses the English fields; the translations are reviewed on the Translations pages. It's gated by a new `pillars.manage` permission, given to the roles that manage content today.

## Follow-ups (each its own small step, after this lands)

Badges and badge categories, role display fields, permission categories, cohort names and charter descriptions adopt `TranslatesContent`. None of them needs a schema change.

## Out of scope

- Member posts; English as a target is designed separately.
- Report-a-bad-translation.
- Alexandria.
