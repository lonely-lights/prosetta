# More content adoption (step 8, item 1)

Status: designed and built by Claude while the owner was away (owner's instruction, 2026-09-26: "decide and build; I'll review"). Not merged.

## In plain words

More of Undaunted's database text now goes through Prosetta translation, the same way the pillars do: badges, badge categories, role names, cohort names and greetings. Their English is written on the Bridge as before; the AI drafts every language and people review it in Translations. Two small Prosetta additions make this safe: greetings contain a `[@]` token for the member's name, which the translation checks now protect; and a new command gives rows that already exist their keys without having to re-save each one.

## What is translated, and why

Only text members see. Staff-only text stays English.

| Model | Fields | Record key | Why |
| --- | --- | --- | --- |
| `Badge` (Engagement) | name, subtitle, description, requirements | slug | Shown on profiles and badge pages |
| `BadgeCategory` (Engagement) | name, description | slug | Shelves badges for members |
| `Role` (Access) | display_name, description | name | Role names appear to members |
| `Cohort` (Cohorts) | name | slug | Community and class names, including wave placeholder names ("October 2026 · 1") |
| `Greeting` (Identity) | display_name, full_text | slug | The greeting a new member receives; `[@]` must survive |

Not translated: `Charter` (organisation names are proper nouns; descriptions are staff notes), `RolePermissionCategory` and `Permission` (staff-only groupings), `AccessCode` descriptions and `OrganisationRequest` (staff notes and members' own words).

## Prosetta additions

1. **Extra placeholder patterns.** `prosetta.placeholders.patterns` (a list of regular expressions, default empty) adds tokens beyond Laravel's `:name` to what the guard protects: a translation that drops or alters one is flagged and retried like a missing `:name`. Undaunted adds `/\[@\]/`.
2. **`prosetta:content:sync {model?*}`**: brings every row of the given models (or those listed in `prosetta.content.models`) in line with its keys, through the same `ContentKeys::sync()` a save runs. Used after adopting the trait on a table that already has rows, and safe to repeat.

## Undaunted changes

- The five models use `TranslatesContent`, each field with a note for the AI and a length limit matching its column. `Cohort` and `Role` override `translationKey()` (slug and name) so keys read well.
- Where members already see this text, it reads through `translated()`: the role display name shared with every page (`HandleInertiaRequests`).
- `config/prosetta.php`: the `[@]` pattern, and the five models under `content.models`.

## Coordination

The spine session (plan 4, design phase) was told before any Cohorts or Identity file was touched; the changes there are additive (a trait, two methods).
