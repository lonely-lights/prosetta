# Report a bad translation (step 8, item 2)

Status: designed and built by Claude while the owner was away (owner's instruction, 2026-09-26: "decide and build; I'll review"). Not merged.

## In plain words

A signed-in member reading the site in another language selects words that read wrong, taps **Report translation**, and optionally suggests better wording with a note. The report lands on the Bridge Review page as a hand edit waiting for a person: the member's suggestion (or, with none, the current wording flagged with their note). A reviewer approves it, edits it, or rejects it, exactly like any other item; the report closes itself when they do. Reports whose words can't be traced to one string wait in a short list for staff instead of being lost.

## Decisions

| # | Decision | Why |
| --- | --- | --- |
| R1 | A report becomes a review candidate (status needs-review, origin manual), not a new status | It appears in Review as "Hand edits" with no change to the cycle or the queue's rules |
| R2 | Without a suggestion, the candidate is the current approved wording, flagged with a `reported` warning carrying the member's note | The reviewer sees exactly what was reported and why |
| R3 | Words are matched to a key by finding current translations in the reader's locale whose approved text contains them (case-insensitive, at least 3 characters); exactly one match attaches, otherwise the report stays unmatched | Selected text is all a page knows; guessing between several strings would misdirect reviewers |
| R4 | A host may pass the key reference instead, when it knows it (a component rendering one content field) | Exact attachment where available |
| R5 | Signed-in members only; one open report per member per key and locale; the host throttles the route | Keeps it useful and hard to spam |
| R6 | A report closes when its translation is next approved (`accepted`) or rejected (`dismissed`); staff can also dismiss an unmatched one | No separate triage workflow to maintain |
| R7 | Reports never change what is live: the approved value stays until a reviewer approves something new | A report is a request, not an edit |

## Prosetta

- **Table `prosetta_reports`**: `id`, `key_id` (nullable), `locale`, `selected_text`, `suggestion` (nullable), `notes` (nullable), `reporter_id` (string), `url` (nullable), `status` (`open`, `accepted`, `dismissed`), `resolved_by`, `resolved_at`, timestamps.
- **`Reports::report(string $locale, string $selectedText, Authenticatable $by, ?string $suggestion = null, ?string $notes = null, ?string $url = null, ?string $keyRef = null): Report`**: matches (R3/R4), refuses a duplicate open report (R5), records it, and when matched writes the candidate (R1/R2) through the review trail as the system with the member's words in the note. A report in the source locale, or for a locale Prosetta doesn't maintain, is refused.
- **Resolution**: listeners on `TranslationApproved` and `TranslationRejected` close the translation's open reports (R6); `Reports::dismiss(int $id, Authenticatable $by)` closes an unmatched one.
- **Reading**: `Reports::open(Viewer $viewer)` lists open reports in the viewer's review languages (matched and unmatched), for a review page; each `QueueItem` of a reported translation carries `reports` (count) so a page can mark it.

## Undaunted

- **Member side**: a small `ReportTranslation` component in the app layout, active only for signed-in members reading in a language other than the source. Selecting text shows a **Report translation** button; its dialog takes an optional suggestion and note and posts to `POST /translations/reports` (throttled 10 per hour). The toast thanks them.
- **Bridge side**: Review shows a "Reported" marker and count on reported rows (they are already "Hand edits"), and a short **Reports we couldn't match** list above the table (text, page link, note, dismiss) for reviewers of that language.
- All copy in lang files; English only, the cycle drafts the rest.

## Amendments after the final review (2026-09-26)

- A report never overwrites work: with a draft or edit already waiting, it is added to it as a `reported` warning carrying the member's note and suggestion.
- A report becomes a hand edit of its own only where the string is current (not stale) and can be changed here (content anywhere; interface text only where review is editable). Otherwise it is not queued (`queued` false) and appears in the staff list with the string it names.
- Only a person closes a report: the cycle's approvals and confirmations leave it open. Approving drops the `reported` warning.
- The reporter counts as the author for self-approval, and reported strings never ride along in approve-matching.

