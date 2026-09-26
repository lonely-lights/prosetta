# Production review, pulled back into development (step 8, item 3)

Status: designed and built by Claude while the owner was away (owner's instruction, 2026-09-26: "decide and build; I'll review"). Not merged.

## In plain words

Today interface text can only be approved in development, because an approval anywhere else could never reach the lang files in git. This lets reviewers approve interface text on the live site too. Production stops writing lang files itself; development runs `prosetta:pull`, which fetches the approvals production has made since the last pull, applies each one where the English still matches, and the usual export then writes the files for a normal commit and deploy.

Database content needs none of this: it is already approved in production and lives there.

## Decisions

| # | Decision | Why |
| --- | --- | --- |
| P1 | `prosetta.export.enabled` (default true) turns every lang-file write off: the cycle's export and a person's Export both skip, with a clear message | A production that reviews must never write files that the next deploy throws away |
| P2 | Production serves its approvals at an opt-in endpoint, `GET {prosetta.pull.path}` (default `prosetta/approvals`), only when `prosetta.pull.token` is set; a missing or wrong bearer token answers 404 | Off unless configured; the token is compared in constant time; a wrong one reveals nothing |
| P3 | The endpoint returns interface-text approvals only (not content), newest-first pages keyed by approval time and id, with each row's key reference, locale, approved text, the English hash it was approved against, reviewer and time | Content lives in production already; the hash lets development refuse an approval made against different English |
| P4 | `prosetta:pull` applies an approval only when the key exists, is current, and its English hash matches; it never overwrites a newer local approval; each applied approval goes through the review trail ("Pulled from production") and fires `TranslationApproved` | Nothing wrong or stale slips in, and the history says where it came from |
| P5 | The last pulled position is kept in `prosetta_state`, so each pull asks only for what's new; `--since` overrides it | Cheap, repeatable pulls |
| P6 | `--export` exports right after a pull | One command for the usual flow |

## Configuration (Prosetta)

```php
'export' => ['enabled' => env('PROSETTA_EXPORT_ENABLED', true), ...],
'pull' => [
    'token' => env('PROSETTA_PULL_TOKEN'),       // set on both sides
    'url' => env('PROSETTA_PULL_URL'),           // development: production's endpoint
    'path' => 'prosetta/approvals',              // production: where to serve it
],
```

The review guard is unchanged: a host that wants interface text approved in production sets `prosetta.review.editable` true there, alongside `PROSETTA_EXPORT_ENABLED=false`.

## Undaunted

Configuration only: the environment variables above are wired in `config/prosetta.php`, and `review.editable` reads `PROSETTA_REVIEW_EDITABLE` so production can opt in. Turning it on in production is the owner's call and is not done here.
