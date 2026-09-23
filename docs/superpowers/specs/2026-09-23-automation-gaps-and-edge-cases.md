# Prosetta automation: gaps found and edge cases to handle

Input for the hardening design that makes the translation pipeline run unattended: sync, translate, check, approve, export and alert, with no person running commands or reading drafts. Written 2026-09-23 after the first real run (Spanish for Undaunted, 2,083 keys) and the US-spelling pass. It records what a person had to do by hand during that run, and what else an automated system will meet.

**Status legend:** **Fixed** (committed), **Handled** (the code already copes), **Gap** (needs work), **Host** (Undaunted's side, not Prosetta's).

## 1. Gaps found during the first run

Each of these is something a person caught or did by hand.

| # | What happened | Status | What the pipeline needs |
|---|---|---|---|
| G1 | **No backoff, circuit breaker or error classification.** A job that throws is retried by the worker immediately, again and again until `retryUntil` (2 h). Every job in a batch does the same, so a 72-job run during an outage sends a constant stream of failing requests for two hours. A bad API key or a rejected request is retried like a transient outage. | **Fixed** `feat/resilience-layer`, `b478d03..9affbb2` | — |
| G2 | **A rename broke the next export.** `prosetta:rename` moved the translation but the old key stayed on disk, so the export reported a conflict. | **Fixed** `8270ea1` | — |
| G3 | **No "still correct" action.** After a spelling-only source edit, 28 Spanish strings went stale although the Spanish was right. Confirming them used `edit()`, which marks the origin `manual` and loses the AI provenance. Prosetta also keeps only the source *hash*, not the previous source text, so neither a person nor a program can see *what* changed. | **Gap** | Keep the previous source value (or a short history). Add a `confirm` action: re-approve the same value against the new source, keeping the origin, logged as `Confirmed`. Classify source changes automatically: if the old and new source differ only in spelling, whitespace or punctuation, confirm without spending tokens; otherwise re-translate. |
| G4 | **Long-running workers keep old code and config.** The translations worker didn't see the new style note until restarted; the run used `--sync` instead. | **Host** | `php artisan queue:restart` (or `horizon:terminate`) on every deploy and whenever translation config changes. Better: read style notes and glossaries from the database, not config, so they take effect without a restart. |
| G5 | **The guard checks structure, not meaning.** Across 1,795 drafts it flagged nothing, yet review found 17 problems: register (tú/usted), a double colon, "uno entre" for "one in", and "configuración regional" for "locale" 14 times. | **Gap** | Automated checks per language: **glossary** (required and banned terms, e.g. `locale → idioma`, banned "configuración regional"); **register** heuristics (formal verb forms and pronouns when the note says informal); **wrapper text** ("Here is the translation:", added quotes or markdown, which the guard currently lets through); **untranslated** output (value equals source when the source isn't a proper noun, URL or code); **wrong language** (language-ID the output); **length** against `max_length` and against the batch's usual ratio. Then a **quality estimate** (an LLM judge or a QE metric) scoring each draft. |
| G6 | **Approval is a person running commands.** | **Gap** | An approval policy per language: auto-approve drafts that are clean on every check and above a quality threshold; send the rest, plus a random sample of the approved ones, to human review. Trust per language can rise as its sample stays clean. |
| G7 | **`prosetta:sync --check` can't gate CI.** It fails on every outstanding string in every language, including languages nobody has started (6,611 at the time). | **Gap** | Scope the check: fail only on *stale* or *broken* strings in languages that are complete, or on a configured set; report the rest without failing. |
| G8 | **No cost control.** The estimate was off by 40% on output tokens. Nothing stops a run, a retry storm or a new 100-language backlog from spending without limit. | **Partly fixed** | Token budgets (per run, per day, per month) and `prosetta:translate --estimate` are done. Money budgets still need the price catalogue (Step 8), so cost, not just tokens, isn't capped yet. |
| G9 | **Nobody is told when something goes wrong.** Batch progress, failures, circuit state and spend are only visible by querying. | **Gap** | Events already exist; add a run record (who, what, when, tokens, cost, outcome) and notifications for failed batches, circuit opened and closed, and budget reached. |
| G10 | **The model's answer isn't checked for chatter.** "Here is the translation: Hola" passes the guard; empty answers are caught. | **Gap** | Part of G5. |
| G11 | **Keys that mirror code identifiers** (`organisations.php`, `organisationRequests`, the `cancelled` status) couldn't be renamed without renaming the domain. | **Host** (policy) | A key-naming convention: keys that mirror enums, models or routes follow the code; purely textual keys follow the language. |
| G12 | **`issues` is a `json` column.** On Postgres it can't be compared or indexed like `jsonb`, which made querying flagged drafts awkward. | **Gap (minor)** | `jsonb` on Postgres in the migration. |
| G13 | **Provider data retention.** `OPENAI_STORE` defaults to `true` in Undaunted's `config/ai.php`, so OpenAI stores prompts and responses. Fine for interface strings; not for member content. | **Host** | Set it `false` for translation calls, or per agent, before member content is translated. |

## 2. Edge cases an automated pipeline will meet

### Provider and network

| Case | Status | Graceful handling |
|---|---|---|
| Provider down for minutes or hours | **Handled** | Circuit breaker (§3): stop calling, pause the queue, probe occasionally, resume on success. |
| Rate limited (429), with or without `Retry-After` | **Handled** | Release the job for `Retry-After` (or backoff); count towards the circuit only if persistent. laravel/ai throws `RateLimitedException`. |
| Provider overloaded (529/503) | **Handled** | Backoff with jitter; circuit after repeated failures. `ProviderOverloadedException`. |
| Connection failure or timeout | **Handled** | Same as overload. `ProviderConnectionException`. |
| Out of credits or quota | **Handled** | **Stop everything** and alert; don't retry, and don't fail over to a more expensive provider without a budget. `InsufficientCreditsException`. |
| Invalid API key, unknown or retired model, bad request (4xx) | **Handled** | Fail fast, don't retry, alert. These won't fix themselves. |
| Content refused by the provider's safety filter for some strings | **Handled** | Mark those items refused (not failed), route them to another engine or a person; don't retry the same call. |
| Timeout after the provider finished (billed, but the answer is lost) | **Gap** | Record the invocation; on retry, only resend items still without a value. Accept some double billing, but count it. |
| Failover to another provider | Handled by laravel/ai (`FailoverableException`) | Make failover respect budgets and the engine's placeholder handling (engines design). |
| Slow degradation (answers getting slower) | **Gap** | Record latency per call; alert when it drifts. |

### The model's answer

| Case | Status | Graceful handling |
|---|---|---|
| Missing ids | **Handled** | Reported as failed (`missing_value`) and retried once with feedback. |
| Extra or unknown ids | **Handled** | Ignored. |
| Duplicate ids | **Handled (implicitly)** | The last one wins; log it. |
| Empty or whitespace-only value | **Handled** | `empty_value` issue. |
| Placeholders dropped, renamed or re-cased; plural segments wrong; HTML broken | **Handled** | The guard, then one retry with feedback. |
| Wrapper text, quotes, markdown or explanations around the value | **Gap** (G10) | A check, then retry with feedback. |
| Wrong language, or the source returned untranslated | **Gap** (G5) | Language-ID check; untranslated check with a proper-noun allowance. |
| Output truncated at the token limit | **Gap** | Detect `finish_reason = length`; split the batch and retry. |
| Malformed JSON or schema mismatch | **Partly handled** | Structured output makes it rare; treat as all items missing. |
| Invisible characters, bidi controls, unnormalized Unicode | **Gap** | Normalize to NFC; strip zero-width and bidi control characters unless the source has them. |
| Instructions inside the source ("ignore previous…") | **Gap** (low for interface strings, **high for member content**) | Send source text only as data (it already is, as JSON); for member content, add an injection check and never let the output change behavior. |

### Source changes

| Case | Status | Graceful handling |
|---|---|---|
| Key renamed | **Fixed** (G2) | — |
| Key renamed but `prosetta:rename` not run | **Gap** | Suggest renames automatically: an obsolete key and a new key in the same file with the same or a very similar source value. |
| Key moved to another file or group | **Gap** | Same suggestion across files; the rename command already accepts any two references. |
| File renamed, split or merged; module removed | **Partly handled** | Orphaned target files are reported and left alone; add rename suggestions per file. |
| Source file has a syntax error | **Check** | A sync must stop loudly and must never mark that file's keys obsolete. Add a test. |
| Source edited while a batch is running | **Handled** | The draft keeps the source hash it was made from, so it shows as stale. |
| A value changes type (string ↔ array) or a list changes length | **Partly handled** | Lists export whole or not at all; test type changes explicitly. |
| Spelling-only source edits | **Gap** (G3) | Classify and confirm without tokens. |

### Target files and export

| Case | Status | Graceful handling |
|---|---|---|
| Hand edits on disk | **Handled** | Conflict, left alone; `prosetta:sync` imports them for review. |
| Old key on disk after a rename | **Fixed** (G2) | — |
| Read-only filesystem in production | **Gap** | Export in CI or on a build step, commit the files, deploy them; never export on a production box. |
| Two branches translating the same files | **Gap** | Export after merging, in one place; lang files are generated, so resolve conflicts by re-exporting. |
| Formatter reformats exported files | **Handled** | Conflicts compare value hashes, not bytes. |

### Workflow and state

| Case | Status | Graceful handling |
|---|---|---|
| A language switched on | **Gap** (G8) | Big backlog: estimate and ask, or run within the budget over several days. |
| A language switched off mid-run | **Check** | Jobs for it should stop; drafts stay. |
| Jobs still queued when `retryUntil` passes | **Gap** | They fail silently after two hours. With a circuit, `retryUntil` can be longer; report expiry as a run outcome. |
| Worker killed mid-call | **Handled** | The runner re-checks work before calling, so a retried job is idempotent. |
| Deploy with queued jobs | **Host** | Restart workers (G4); jobs survive in Redis. |
| Approving a draft whose source changed since | **Check** | Approval should refuse or warn when the draft's source hash isn't current. |

### Cost and governance

| Case | Status | Graceful handling |
|---|---|---|
| Retry storm | **Gap** (G1, G8) | Backoff, circuit and budget together. |
| Price or model changes | **Gap** | The Step 8 catalogue, with effective dates. |
| Self-hosted model licence limits (e.g. HY-MT excludes the EU, UK and South Korea) | **Host** | A legal check before adoption. |

### Member content (when it's built)

| Case | Graceful handling |
|---|---|
| Post edited while its translation is in flight | Save under the fingerprint it was made from; the reader asks again for the new one. |
| Post deleted or moderated after translation | Delete its translations with it. |
| Mixed-language posts, wrong language detection | Translate only when the detected language differs from the reader's; show the original always. |
| Code blocks, URLs, @mentions, emoji, markdown | Mask them before the call and restore after, like placeholders. |
| Very long posts | Segment (§16b.8); segments also make edits cheap. |
| Private content and data retention | Opt-outs; `store=false`; provider region. |

## 3. Proposed resilience layer (for G1)

1. **Classify errors** into *transient* (connection, overload, 5xx, timeout), *rate-limited* (with an optional wait), *fatal* (bad key, unknown model, bad request), *budget* (out of credits) and *refused* (safety filter, per item). The host driver maps provider exceptions to Prosetta exceptions, since only it knows the provider; laravel/ai's exception classes make that mapping direct.
2. **Backoff:** transient failures release the job with exponential backoff and jitter (30 s, 1 min, 2 min, 5 min, 10 min, capped at 15 min). Rate limits wait for `Retry-After` when given.
3. **Circuit breaker per engine,** held in the cache. After N consecutive transient failures (e.g. 5), the circuit opens for a cooldown that doubles each time it re-opens (5 min, up to 1 h). While it's open, jobs don't call the provider: they release themselves until the cooldown ends. Then one probe job is allowed; success closes the circuit, failure re-opens it. Every state change raises an event, so the host can notify.
4. **Fatal and budget errors** fail the job without retry, cancel the rest of the batch, and raise an event.
5. **`retryUntil`** can then be long (e.g. 24 h), because the circuit, not the deadline, keeps traffic down during an outage.
6. **Separate limits** for background runs and member-facing work, each with its own queue, limiter and circuit, so a background backlog never delays a reader.
