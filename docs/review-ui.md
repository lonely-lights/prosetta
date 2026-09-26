# Building a review UI

Prosetta has no admin screens of its own. Instead it gives you queries and actions to build them in your app, in whatever stack you use. Every query returns plain data objects with `toArray()`, ready for Inertia props or a JSON response.

There are two levels:

- **The facade**, for simple one-language screens (the [workflow guide](workflow.md#3-review-approve-edit-or-reject) shows it).
- **The review core**, below, for a fuller UI: a queue across languages, a matrix of every key, a coverage dashboard, and bulk actions.

## The viewer

Every query and action starts from a `Viewer`: who is looking, and what they may do.

```php
use LonelyLights\Prosetta\Review\Viewer;

$viewer = Viewer::for($request->user());
```

It works out once, from your [authorization callback](workflow.md#who-may-do-what):

- `translates` and `reviews`: the language codes this person may translate and review;
- `manages`: whether they have `Manage`, which covers every language;
- whether review is **editable** here (see below).

### Read-only environments

Approving interface text only makes sense where the lang files get committed, so by default review is editable only in the `local` and `staging` environments. Set `prosetta.review.editable` (env `PROSETTA_REVIEW_EDITABLE`) to change that.

This only limits people. The background cycle and commands can always write.

## The queue

```php
use LonelyLights\Prosetta\Review\ReviewQueue;

$page = app(ReviewQueue::class)->for($viewer, $request->only(['locale', 'reason', 'namespace', 'group', 'search']));
```

Returns a paginator of `QueueItem`: everything in the viewer's languages that needs a person. `count()` takes the same arguments without paging, and `all()` returns the whole list. Each item's reason is one of:

| Reason | Means |
|---|---|
| `draft` | An AI draft with no problems |
| `flagged` | An AI draft with a warning or error |
| `pending` | A person's edit, waiting for someone to review it |
| `stale` | Approved, but its English has changed since |
| `held` | Held back: it keeps failing, or was rejected twice from the same English |

After two rejections from the same English, a string stops going back to the AI and waits for a person to write it. Editing the English resets this.

## The keys browser

```php
use LonelyLights\Prosetta\Queries\KeyBrowser;

$browser = app(KeyBrowser::class);

$browser->for($viewer, $filters, page: 1, perPage: 50);  // paginated KeyRows
$browser->matchCount($viewer, $filters);                 // how many match
$browser->files($viewer);                                // every lang file, with counts
$browser->key($viewer, $keyId);                          // one key, with its review history
```

Each `KeyRow` is one key, with a `cells` map from language code to that language's `KeyCell` (status and values), for a matrix or file-by-file editor. Filters: `namespace`, `group`, `locale`, `status`, `search`. A search stops at 500 matching rows to keep the query cheap.

## Coverage

```php
use LonelyLights\Prosetta\Queries\Coverage;

$report = app(Coverage::class)->for($viewer);
```

Everything an overview dashboard needs in one call. For each language the viewer can see: its name, whether it's AI-drafted or a spelling variant, how many strings are in each status (including `missing`), and the tokens and [cost](resilience.md#what-the-ai-costs) spent this month. Plus the last cycle's time and report, each provider circuit's state, budget usage, any health problems, and whether review is editable.

## Actions

Single items go through `ReviewService`, or the facade:

```php
use LonelyLights\Prosetta\Review\ReviewService;

$service = app(ReviewService::class);
$service->approve($ids, $user, expected: $fingerprints);
$service->edit($translationId, $value, $user, approve: true);
$service->reject($translationId, $user, notes: 'Too formal.');
```

Bulk actions go through `ReviewDesk`:

```php
use LonelyLights\Prosetta\Review\ReviewDesk;

$desk = app(ReviewDesk::class);

$desk->approveMatching($viewer, $filters, includeWarnings: false);  // approve every clean item matching the filters
$desk->approveMany($viewer, $expected);                            // approve chosen items
$desk->rejectMany($viewer, $expected, 'Too formal.');              // reject chosen items
$desk->estimateRedraft($refsByLocale);                             // price a fresh AI draft
$desk->redraft($viewer, $refsByLocale);                            // queue it
$desk->runCycle($viewer);                                          // queue a background cycle (Manage only)
```

The approve and reject actions return a `BatchReport` counting what was `approved` or `rejected` and what was skipped: `skippedWarnings`, `skippedErrors`, `conflicts`, `forbidden` (a language the viewer can't review) and `locked` (read-only here). Its `skipped` array gives the reason for each skipped translation id.

### Two people, one string

Pass `$expected`: a map of each translation id to the fingerprint your page showed, from `ReviewService::fingerprint($translation)`. If someone changed the item since the page loaded, it's skipped as a `conflict` instead of being overwritten. The single-item `edit()`, `reject()` and `confirm()` throw `ReviewConflict` in the same case.

### When review is read-only

`approveMatching`, `approveMany` and `rejectMany` still act on [database content](database-content.md), which never reaches a file, and skip lang-file strings as `locked`. `redraft` and `runCycle` throw `ReviewLocked`.

## Services on the facade

For simpler screens, the facade covers one language at a time:

| Method | Does |
|---|---|
| `Prosetta::reviewQueue($locale, $filters)` | A paginator of `ReviewItem`: key, source, candidate, approved value, status, stale flag, issues, provenance |
| `Prosetta::missing($locale, $filters)` | Keys with nothing yet in that language |
| `Prosetta::write($keyRef, $locale, $value, $by, approve: false)` | Translate a missing key by hand |
| `Prosetta::edit($id, $value, $by, approve: false)` | Change a translation; with `approve: true`, save and approve together |
| `Prosetta::approve($ids, $by)` | Approve one or more translations |
| `Prosetta::approveClean($locale)` | Approve every clean draft in a language |
| `Prosetta::reject($id, $by, notes: null)` | Reject, with a note for the AI |
| `Prosetta::export()`, `rename()`, `stats()`, `lookup($keyRef)` | As the commands |

Every method that acts for a person takes `?Authenticatable $by`; `null` means the system.
