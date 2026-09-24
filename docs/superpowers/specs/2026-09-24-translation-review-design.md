# Translation review: shared core and Bridge pages

**Date:** 2026-09-24. **Status:** for review. **Rollout step:** 7.
**Builds on:**
- background mode (`2026-09-23-background-mode-design.md`);
- the export safety net;
- derived locales.

**Leads to:**
- step 10, a publishable Filament panel (rebuild spec §15);
- later API routes (§9).

## 1. Goal

People review and fix translations in Undaunted's Bridge without touching lang files or the terminal:
- see what needs a person;
- clear it quickly, in batches when the first round of copy is good;
- look after tone and humor carefully when it matters;
- browse every key in whatever way suits them.

**Decided:**
- **The pages are bespoke to Undaunted.** They're Inertia/React pages in Bridge, and never part of the package.
- **The package gets a headless core** (queries, actions and an editing guard). Bridge uses it now; a Filament panel (step 10) and JSON API routes (later) reuse it.
- **Dev and staging only for writes.** In production, the pages are read-only. Review in production, with a pull back into dev, stays a step 8 feature.
- **Every page offers more than one view of the same data,** and remembers each person's choice.

**Out of scope:**
- the Filament panel;
- API routes;
- production review and pull;
- language settings, which already live in the Locales dialog.

## 2. Prosetta: the shared core

### 2.1 Viewer

`Review\Viewer` is the user plus what they may do, resolved once per request through the `Authorizer` hook:
- the locales they can translate;
- the locales they can review;
- whether they can manage.

Every query and action takes a `Viewer`, so permission filtering happens in one place. A reviewer of Spanish never receives Arabic rows.

### 2.2 Queries (`Queries\`)

All results are small data objects with a `toArray()`, and lists are paginated. Each rule reuses `WorkState`, `CycleWork` and `CycleFailures`, so the pages and the cycle never disagree about what needs work.

**`ReviewQueue::for(Viewer, ?locale, ?reason, ?search, page, perPage = 50)`**

Returns items that need a person. Each item has a reason:

| Reason | What it means |
|---|---|
| `flagged` | An AI or derived draft with issues. |
| `pending` | A candidate from a person or an import, awaiting review. |
| `stale` | Approved, but the English has changed and no update is queued yet. |
| `held` | Capped by the cycle after repeated failures, or rejected twice (§2.3). |

Each item carries:
- the ref, key id and locale;
- the English;
- for updates, the old English and the word diff;
- the candidate value and the approved value;
- its issues, origin and age;
- its value fingerprint, used for the conflict check in §2.3.

`ReviewQueue::count(...)` counts the items matching a filter, for the "approve everything clean" confirmation.

**`KeyBrowser::for(Viewer, filters, page)`**

- **Filters:** namespace, file, locale, status and search. The search matches the key, or the text in the English or any visible language.
- **Rows:** each key with its English and one cell per visible language (status, value, issues).
- **The three views:** the matrix pages by file or module; the file view returns a file's keys in source order; search is capped at 500 results.
- **History:** `KeyBrowser::key(Viewer, keyId)` adds the review history per language.

**`Coverage::for(Viewer)`**

- **Per visible language:** the counts of approved, draft, flagged, pending, stale and missing strings, plus the mode (AI or derived).
- **Health:** the last cycle, circuit health and budget health (the same checks as `prosetta:health`).
- **Usage:** tokens used today and this month.

This needs one addition: the cycle stores its last `CycleReport` in `prosetta_state`, under `cycle.last_report`.

### 2.3 Actions

All actions go through `ReviewService`, or `ProsettaManager` for operations, and each one checks the `Viewer`.

**Approve and edit**
- **Approve:** as today.
- **Edit:** takes a value, and optionally approves it. A translator's edit becomes a candidate for review; a reviewer may choose "edit and approve".
- **Guards on edits:** the placeholder and glossary guards run first. A blocking issue refuses the edit with the guard's message; warnings are stored.

**Reject, with a note**
- The note is saved with the review.
- The next AI draft of that key and language gets it as feedback, the same as the runner's retry feedback.
- A key rejected twice from the same English becomes `held`: the cycle stops drafting it until the English changes or a person writes a value. This uses the same store and limit as the cycle's failure count.

**Re-draft**
- Queues one key, or the selected keys, through `Translator`, so budgets and the circuit breaker apply.
- Shows a token estimate first.

**Batches**
- **Selected:** approve, reject (with one shared note) or re-draft the ticked items.
- **"Approve everything clean":** approves every item matching the current filter, across all pages. It skips items with errors. Items with warnings are skipped unless the request explicitly includes them. It runs in chunks and returns approved, skipped-with-warnings and skipped-with-errors counts. It's audited as one action.

**Conflicts**
- Every write carries the fingerprint the page showed.
- If the value has changed since (the cycle updated it, or someone else acted), the write is refused with `ReviewConflict`, and the caller reloads the item.
- Batch actions skip and count conflicted items rather than failing the whole batch.

**Operations**
- Sync, run a cycle (queued) and export, for people who can manage.

### 2.4 The editable guard

- **The setting:** `prosetta.review.editable` defaults to `app()->environment('local', 'staging')`, and can be overridden with `PROSETTA_REVIEW_EDITABLE`.
- **When it's false:** every write in §2.3 throws `ReviewLocked`, and the queries report `editable: false` so a UI can explain why.
- **What stays unguarded:** the cycle and the commands. They're how dev and CI work.

## 3. Undaunted: the Bridge pages

### 3.1 Placement and navigation

- **The section:** a `TranslationsSection`, in the Central Core group next to Locales.
- **Who sees it:** anyone with any translation ability.
- **The tabs:** three pages.

| Page | Route | Views |
|---|---|---|
| Overview | `/bridge/translations` | **Cards** (default) or **Table** |
| Review | `/bridge/translations/review` | **Table** (default) or **Focus** |
| Keys | `/bridge/translations/keys` | **Matrix**, **File** or **Search** |

- **Filters and paging** live in the URL (for example `?locale=ar&reason=flagged&page=2`). That's how every count on the Overview links straight to its slice of the queue.
- **The view choice** is remembered per person: in Bridge's user preferences if they exist, otherwise in `localStorage`.

### 3.2 The views

**Overview**
- **Cards:** one per visible language, with a coverage bar, counts that link into the queue, tokens this month, and the mode (AI, or derived at 0 tokens).
- **Table:** a row per language, with sortable state columns; it scales past about 8 languages.
- **On both views:** a health strip (last cycle, provider, budget), the last cycle's report, and Sync, Run cycle and Export for people who can manage.

**Review**
- **Table:** dense rows with checkboxes, and the selection and filter batch actions from §2.3. The confirmation shows exact counts and what's excluded.
- **Focus:** one item at a time, the English change beside the candidate, and room for the key's history. Approve, Edit, Reject (with a note), Re-draft or Skip, then on to the next item. Keyboard: `a` approve, `e` edit, `r` reject, `s` skip.

**Keys**
- **Matrix:** keys against languages, with a colored status per cell. Hovering previews the value; clicking opens the file view at that key. One module or file at a time.
- **File:** a module and file tree, and the chosen file's keys in source order, with the English beside one chosen language. Values can be edited in place, and each edit becomes a candidate unless the person reviews that language.
- **Search:** a card per key, showing the English and every visible language stacked, with status and a link to the history.
- **On all three:** the batch buttons, scoped to the current filter, so a whole file or module can be approved in one go.

### 3.3 Server side

- **Page controllers:** thin controllers call the core queries and shape the results into Inertia props.
- **Action controllers:** one small POST controller per action. Each returns to the same view with a flash message, and each is audited through Bridge's existing audit log.
- **Copy:** all text in `bridge::translations`.

### 3.4 Production and states

**When the editable flag is false:**
- every action button is disabled, with a one-line explanation;
- browsing, search and coverage still work.

**Empty and large states:**
- An empty queue says "Nothing needs you in {language}", with a link to Keys.
- The matrix asks you to narrow the filter beyond one module.
- Search shows its first 500 results, with a note.

## 4. Permissions

| Ability | Can |
|---|---|
| Translate (a language) | See that language; edit values, which become candidates; re-draft. |
| Review (a language) | Everything Translate can, plus approve, reject, edit-and-approve, and batches. |
| Manage | All languages, plus Sync, Run cycle and Export. |

These map onto Undaunted's existing permissions (`translations.translate.{code}`, `translations.review.{code}` and `translations.manage`), granted on `/bridge/matrix`. The core enforces them; the UI only hides what the core would refuse anyway.

## 5. Data flow

1. **Approve.** The approved value is set in the database.
2. **Export.** The next cycle's export (or the Export button) writes the lang file. The file safety net still applies.
3. **Git diff.** The diff stays the final review before anything ships.

A rejection's note reaches the model on the next draft of that key. The Overview's numbers and the queue always come from the same rules the cycle uses.

## 6. Error handling

| Error | What the person sees |
|---|---|
| `ReviewConflict` | "This changed since you opened it", with the fresh version shown. Batch actions report a count of conflicted items. |
| `ReviewLocked` | Disabled controls, and any direct POST refused with a 403 and a message. |
| A guard refuses an edit | The guard's message on the field; nothing is saved. |
| A re-draft over budget, or the provider down | The resilience layer's own message ("budget spent", "provider unavailable, suspended"); nothing is lost. |

## 7. Testing

**Prosetta:**
- every `ReviewQueue` reason;
- `KeyBrowser` filters, search, source order and pagination;
- `Coverage` counts matching `CycleWork`;
- `Viewer` filtering (one language never leaks into another);
- the editable guard;
- actions: edit through the guards, reject with a note feeding the next draft, the twice-rejected hold, the conflict check, and batch approve-all with its exclusions and counts;
- `toArray()` for each result object.

**Undaunted:**
- feature tests per page: props, permissions and view preference;
- each action, including batches, conflicts and the production lock;
- Vitest for every view and switch, bulk selection and the confirmation counts;
- one end-to-end test: a flagged Arabic draft fixed in Focus, then exported.

## 8. Delivery

1. **The Prosetta core:** queries, actions, the guard and `cycle.last_report`. Built in a worktree beside the package and merged when green.
2. **Bridge Overview and Review.**
3. **Bridge Keys.**
4. **A visual pass** against Bridge's real look, iterated in the brainstorming companion.

Parts 2–4 are built in an Undaunted worktree, two levels under `C:\Websites`, with a separate test database, so another session working in the main checkout isn't disturbed.

## 9. Later: API routes (not in this step)

JSON routes over the same queries and actions, such as `GET /prosetta/api/review` and `POST /prosetta/api/review/{id}/approve`, behind the host's auth and the same `Viewer`. Nothing in this step builds them. The `toArray()` results and the action signatures are shaped so the routes can stay thin wrappers.
