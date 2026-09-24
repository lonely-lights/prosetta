# Prosetta

Translation workflow for Laravel. Your source-language lang files are the canonical keys. Prosetta drafts every other locale through an AI driver you supply, routes drafts through per-language human review, and writes approved text back to root and module lang folders. It's headless: your admin calls its services.

## How it works

1. **You edit the source locale's files** (`lang/en/*.php`, `lang/en.json`, `app/Modules/*/Lang/en/*.php`) by hand. Prosetta never writes them.
2. **`prosetta:sync`** reads them into keys. New keys become *missing* in every target locale. A changed English value makes existing translations *stale*. Removed keys become *obsolete*. Existing target files are imported as approved work; later hand edits to them are imported for review, never silently accepted.
3. **`prosetta:translate`** drafts missing and stale keys through your `TranslationDriver`. Every result is checked for placeholders (exact case), plural segments and HTML, gets one retry with feedback, and is saved as a draft with model, provider and tokens.
4. **Reviewers approve, edit or reject** per locale (`Prosetta::approve()`, `edit()`, `reject()`, or `prosetta:review`).
5. **`prosetta:export`** writes approved values (or drafts, if you choose) into each locale's files, in the source file's key order. A target file holding anything Prosetta didn't write (a hand edit not yet synced, a file never synced, a key the source doesn't have) is left alone and reported as a conflict, and the command exits 1. Run `prosetta:sync` to import it, or pass `--force` to overwrite.

## Install

```bash
composer require lonely-lights/prosetta
php artisan prosetta:install   # publishes config/prosetta.php and the migrations
php artisan migrate
```

`prosetta:install` publishes the four workflow tables (`prosetta_files`, `prosetta_keys`, `prosetta_translations`, `prosetta_reviews`). It publishes the `prosetta_locales` migration only if that table doesn't exist yet. Hosts upgrading from an earlier Prosetta keep their locales table; add a boolean `translated` column (default `false`) and widen `locale_initials` to 35 characters. Queued translation uses Laravel job batches, so the host needs the `job_batches` table (`php artisan make:queue-batches-table`).

Target locales are rows in `prosetta_locales` where `active` (offered to members) or `translated` (maintained, even if not offered) is true. Codes must match your lang folder names exactly (`zh-CN`, `en_GB`) and can't change once created.

## Bind an AI driver

Prosetta ships no AI client. Implement `LonelyLights\Prosetta\Contracts\TranslationDriver`. With [laravel/ai](https://github.com/laravel/ai) it looks like this:

```php
namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class Translator implements Agent, HasStructuredOutput {
    use Promptable;

    public function instructions(): string {
        return <<<'TEXT'
        You translate user-interface strings for a web application.
        Keep every placeholder that starts with a colon (:name, :Name, :NAME) exactly as written, including its case.
        Keep plural segments separated by | and any {0} or [2,*] range markers. Keep HTML tags unchanged.
        Respect max_length when given. When variant_of is set, only adapt spelling and usage for that region,
        and return the text unchanged when nothing differs. Fix every listed problem on a retry.
        Return exactly one translation per id.
        TEXT;
    }

    public function schema(JsonSchema $schema): array {
        return [
            'translations' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'value' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
```

```php
namespace App\Services\Translation;

use App\Ai\Agents\Translator;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Data\TranslationItem;

final readonly class LaravelAiTranslationDriver implements TranslationDriver {
    public function __construct(private Translator $agent) {}

    public function translate(TranslationBatch $batch): TranslationBatchResult {
        $response = $this->agent->prompt(json_encode([
            'from' => $batch->sourceLocale,
            'to' => ['code' => $batch->target->code, 'language' => $batch->target->englishName, 'script' => $batch->target->script],
            'variant_of' => $batch->variantOf,
            'items' => array_map(fn (TranslationItem $item) => [
                'id' => $item->id, 'text' => $item->source, 'context' => $item->context,
                'max_length' => $item->maxLength, 'previous_translation' => $item->previous,
                'problems_to_fix' => $batch->feedback[$item->id] ?? [],
            ], $batch->items),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), model: $batch->model);

        $rows = $response instanceof StructuredAgentResponse ? ($response->structured['translations'] ?? []) : [];

        return new TranslationBatchResult(
            values: array_column($rows, 'value', 'id'),
            provider: (string) ($response->meta->provider ?? 'unknown'),
            model: (string) ($response->meta->model ?? $batch->model ?? 'unknown'),
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
            invocationId: $response->invocationId,
        );
    }
}
```

Bind it in a service provider (or set `prosetta.ai.driver` to the class):

```php
$this->app->bind(\LonelyLights\Prosetta\Contracts\TranslationDriver::class, \App\Services\Translation\LaravelAiTranslationDriver::class);
```

Each translation stores its share of the call's tokens and the call's `ai_invocation_id`, so exact totals are one join away in any ledger keyed by laravel/ai's invocation id.

## Authorization

Decide access in one place. The callback receives the ability and, except for `Manage`, the locale:

```php
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Facades\Prosetta;

Prosetta::authorizeUsing(fn ($user, Ability $ability, ?string $locale): bool => match ($ability) {
    Ability::Manage => $user->can('translations.manage'),
    default => $user->can("translations.{$ability->value}.$locale"),
});
```

`Review` implies `Translate` for the same locale. With no callback, only the `local` environment is allowed. Prosetta also registers the gates `prosetta.translate`, `prosetta.review` and `prosetta.manage`, so `$user->can('prosetta.review', 'ar')` works in policies.

## Commands

| Command | What it does |
|---|---|
| `prosetta:sync [--namespace=*] [--check]` | Read source files, import target files. `--check` changes nothing and exits 1 when work is outstanding (use it in CI). |
| `prosetta:translate [--locale=*] [--namespace=*] [--key=*] [--force] [--sync]` | Draft missing and stale keys (queued on `prosetta.queue.name` unless `--sync`). |
| `prosetta:review {locale} [--approve-clean] [--namespace=]` | List the review queue, or approve every clean current candidate. |
| `prosetta:export [--locale=*] [--namespace=*] [--include-drafts] [--dry-run] [--force]` | Write target-locale files; exits 1 on conflicts unless `--force`. Incomplete lists are left out, and files whose source group is gone are left untouched and reported. |
| `prosetta:rename {from} {to}` | Move translations to a key you renamed in the source file (sync first). |
| `prosetta:stats [--locale=]` | Progress per locale and namespace. |

Key references use Laravel's notation: `identity::onboarding.toast.accessCode.inUse`, `auth.failed`, `json:Save changes`.

## Resilience

Translation runs are meant to be left unattended. Prosetta never hammers a provider that's down, stops cleanly on problems that won't fix themselves, and recovers by itself once the problem clears.

**Error classes.** Prosetta defines the categories; your `TranslationDriver` maps its provider's errors onto them, because only the driver knows the provider. All five extend `LonelyLights\Prosetta\Exceptions\Provider\ProviderException`:

| Exception | Meaning | Prosetta's response |
|---|---|---|
| `ProviderUnavailable` | Down, overloaded, connection failure, timeout, 5xx | Backoff; counts towards the circuit |
| `ProviderRateLimited(?int $retryAfter)` | 429 | Wait `retryAfter` seconds (or the backoff); counts towards the circuit |
| `ProviderRejected` | Invalid key, unknown or retired model, no access (HTTP 401, 403, 404) | Halt |
| `ProviderQuotaExhausted` | Out of credits or quota | Halt |
| `ProviderBatchRejected` | The provider refused this batch's request (context too long, invalid input: HTTP 400 or 422); the provider itself is fine | Fails that one job into `failed_jobs` with its error, and the batch carries on. Never touches the circuit or halts. A `--sync` run records the chunk's keys as failed and goes on |

Any other `Throwable` from the driver is handled according to `resilience.unknown_errors`: `'transient'` (default, treated as `ProviderUnavailable`) or `'halt'` (treated as `ProviderRejected`).

A halt trips the circuit, ends the current job quietly (deleted, not failed), cancels its batch and suspends the run's scope for `prosetta:resume`.

A synchronous run (`--sync`, or `translate(queue: false)`) can't wait out a transient error the way a queued job does, so it stops at the first one and suspends its scope. The suspension's reason is `outage` only when the circuit has been open longer than `outage_timeout`; a single 503 or an open circuit that hasn't reached it is suspended as `unknown`. A queued job never suspends for a transient error before `outage_timeout`: it releases itself and tries again.

**Per-string refusals** (a provider's safety filter declines some strings) aren't exceptions: `TranslationBatchResult::$refused` (item id => reason) records them as failed with a `refused` issue, and the issue-retry loop leaves them alone.

**`ChecksHealth`** is an optional contract for your driver:

```php
interface ChecksHealth {
    /** A near-free call that proves the provider answers. Throws a ProviderException when it doesn't. */
    public function checkHealth(): void;
}
```

A driver that implements it lets Prosetta test a provider after a cooldown without spending a real batch. Without it, the test is the next real job (and `prosetta:resume` just requeues the scope, letting one of those jobs make the test call).

**Config**, with the package defaults:

```php
'resilience' => [
    'cache_store' => null,               // null = the default store. Use a store that supports locks
                                          // (Redis, database, file, array); every worker must share it.
    'backoff' => [30, 60, 120, 300, 600, 900],   // seconds per attempt; the last repeats
    'jitter' => 0.2,                     // ±20%
    'circuit' => [
        'failure_threshold' => 5,        // consecutive countable failures that open the circuit
        'cooldown' => 300,               // first open period, seconds
        'cooldown_multiplier' => 2,
        'max_cooldown' => 3600,
    ],
    'outage_timeout' => 21600,           // 6 h open without a break: stop retrying, suspend
    'halt_hold' => null,                 // seconds a halt lasts before testing; null = until prosetta:circuit reset
    'unknown_errors' => 'transient',     // or 'halt'
    'resume_every' => null,              // minutes, clamped to 1-59 (a */N cron); null = the host schedules prosetta:resume itself
],

'budgets' => [
    'per_run' => null,                   // tokens; null = no limit
    'daily' => null,
    'monthly' => null,
    'estimate' => ['input_per_char' => 0.3, 'output_per_char' => 0.3, 'input_per_item' => 12, 'output_per_item' => 8],
],
```

Budgets are counted in tokens (input plus output, as the driver reports them), because there's no price catalogue yet. `per_run` stops only that run; `daily` and `monthly` stop every run, suspend it, and are picked up again automatically once the period changes. Reaching a budget never trips a circuit, and a budget is a separate gate: `prosetta:resume` checks it before requeueing anything.

Circuit and suspension state changes (opening, tripping, recording a suspended scope) go through a short cache write lock, so two workers racing the same event can't both fire it. Your `resilience.cache_store` needs to be a store that supports locks: Redis, memcached, dynamodb, database and file all do. `array` supports locks too but lives in one process, so it's for tests only, never for more than one worker.

**Commands:**

| Command | What it does |
|---|---|
| `prosetta:circuit status` | A table of each known circuit: state (`closed`, `open`, or `halted (reason)`), failures, next test (a time, or `until reset`) and last error; then the number of suspended runs and the daily and monthly budget usage |
| `prosetta:circuit reset [circuit]` | Close a circuit, or all of them, and clear its halt; suspended work is resumed on the next `prosetta:resume` |
| `prosetta:resume` | Tests each circuit that's due (via `checkHealth()` when the driver has it, otherwise by requeueing) and queues its suspended scopes again on success |
| `prosetta:translate --estimate` | Prints, for the run it would start: strings, source characters and expected input/output tokens, without calling anything; whether the whole estimate fits under the `per_run` limit; and what's left of the daily and monthly budgets |

`resume_every` becomes a `*/N * * * *` cron, so it's clamped to 1–59 minutes: 60 or more runs every 59 minutes; for an hourly or longer interval, leave it `null` and schedule `prosetta:resume` yourself. `prosetta:resume` only runs on a schedule when `resilience.resume_every` is set **and** the host actually runs Laravel's scheduler (`schedule:work` locally, a cron entry calling `schedule:run` every minute in production). With `resume_every` left `null`, or no scheduler running, nothing calls `prosetta:resume` for you — run it by hand or wire up your own schedule.

**Events**, all in `LonelyLights\Prosetta\Events`, raised once per state change (not once per job):

| Event | Payload |
|---|---|
| `CircuitOpened` | circuit, cooldown seconds, failure count, last error message |
| `CircuitClosed` | circuit, downtime seconds |
| `TranslationHalted` | circuit, reason (`rejected`, `quota`, `unknown`), message |
| `TranslationSuspended` | circuit, reason, scope |
| `TranslationResumed` | circuit, scopes queued |
| `BudgetReached` | period, used, limit |

Prosetta also writes a line to `log_channel` for each: warning for opened, halted, suspended and budget; info for closed and resumed.

**Operational notes.**
- Run `php artisan queue:restart` after deploying a new driver or changing translation config. The worker process keeps the old code and config until it's restarted.
- Halted or suspended jobs are deleted, never marked failed, so `failed_jobs` holds only genuine bugs and, by design, batches the provider refused (`ProviderBatchRejected`), each with its error. A job's `retryUntil()` is seven days after dispatch, so Laravel never expires a job during a long run or an outage: the circuit and `outage_timeout` decide when to stop. A genuine bug (an exception Prosetta doesn't handle) is retried after 30, 120 and 600 seconds and fails the job after three (`$maxExceptions = 3`).

## Background mode

`prosetta:cycle` keeps a site's translations current with nobody running commands: it syncs, confirms cosmetic edits, drafts and updates what needs it, then approves and exports per config. It's meant for the dev machine (`composer dev`) and CI, where the lang files live, not production.

### Language settings

Three columns on `prosetta_locales`, created by the published locales migration. A host that owns its own locales table adds them itself:

| Column | Type | Meaning |
|---|---|---|
| `auto_translate` | boolean, default false | The cycle drafts every key that needs work in this language: missing, a stale candidate, or a rejected candidate, not only edited keys. |
| `style_note` | text, nullable | Sent with every batch for this language, through `LocaleDescriptor::$styleNote`. |
| `glossary` | json, nullable | A list of `{"source": "cohort", "target": "دفعة", "accept": ["دفعت", "دفعات"], "banned": ["فوج", "مجموعة"]}` entries. `accept` and `banned` are optional. |

`Locale::autoTranslate()` scopes to it, and `DatabaseLocaleSource::autoTranslateTargets()` returns the codes (excluding the source locale). A regional code (`en_GB` of `en`) with no note or glossary of its own falls back to its base language's, field by field: it gets the base's `style_note` only if it has none of its own, and the base's `glossary` only if its own is empty.

### Glossary checks

`GlossaryGuard` runs after `PlaceholderGuard` in the runner, for every entry whose `source` term appears in the English:

- matched as a whole word, singular or plural (an optional trailing `s` or `es`), case-insensitively, against the source;
- the translation must then contain `target` or any form in the entry's optional `accept` list — each checked as a plain case-insensitive substring, not a word boundary match, because many scripts (Arabic, Chinese, …) have none. Missing them all is a **warning**, `glossary_missing`, whose message names the `target`. Use `accept` for inflections a substring of the target can't catch: Arabic `دفعة` becomes `دفعات` in the plural and `دفعتك` with a pronoun suffix (the ة turns into ت), so its entry accepts `دفعت` and `دفعات`;
- if the translation contains any `banned` term (also a case-insensitive substring), that's an **error**, `glossary_banned`. It gets the normal single retry with feedback ("Use \"دفعة\" for \"cohort\", not \"فوج\".").

Warnings (glossary or the rewrite check below) block auto-approval but not export under `include_drafts`.

### Update mode and the cosmetic rule

Every approval path (`approve`, `edit(..., approve: true)`, `write(..., approve: true)`, `confirm`, and importing an existing target file during sync) writes `approved_source_value` on the translation — the English it was approved against — but only when that approval is made from the key's *current* English; an approval of an already-stale value leaves it `null`. Rows approved before this migration have it `null` and fall back to an ordinary re-translation.

Each cycle, before anything is sent to the AI, `CosmeticConfirmer::confirmAll()` finds every translation whose `approved_source_value` no longer matches its key's current English, skips any that already have a candidate made from that new English (someone's already on it) or with no `approved_source_value`, and for the rest checks `SourceChange::isCosmetic()`: the old and new English compare equal after lowercasing, folding curly quotes and em/en dashes to straight equivalents, collapsing whitespace, and trimming trailing punctuation (`.!?…:;`). A cosmetic match is **confirmed** — re-approved against the new English with no AI call, keeping its origin and logging `ReviewAction::Confirmed`. Anything else is substantive and goes to the model.

For a substantive change, `TranslationRunner` sets `TranslationItem::$previousSource` to the translation's `approved_source_value` (only when it differs from the key's current English and the translation has an `approved_value`). Your driver receives it alongside the current English and can build a word-level diff with `SourceChange::diff()` (LCS-based, `[-removed-]` / `{+added+}` markers) to show the model exactly what changed.

After an update, `SourceChange::ratio()` compares how much the translation's words changed (Levenshtein distance, normalized by word count) against how much the English changed. If the ratio is above `automation.rewrite_ratio` (default 3.0) *and* the translation itself changed by more than two words, the draft gets a warning, `large_rewrite`, and isn't auto-approved.

**Which languages get updates:** every target language with an approved translation of the edited key, whether or not it's auto-translate. **Which languages get drafts of keys they never had:** only auto-translate languages. `CycleWork::build()` computes this per key and locale.

**What the cycle holds back.** `CycleWork::plan()` leaves two kinds of key out of the work and reports each one as flagged instead, with its reason:

- **A person's candidate.** A key whose row is a non-AI candidate (origin `manual` or `imported`, status `draft` or `needs_review`) is never sent to the AI, whether that candidate was made from the current English or an older one. It shows as `"{locale} {ref} (awaiting human review)"`. This only changes the cycle: `prosetta:translate` is as before.
- **A key that keeps failing.** When the provider refuses a key, returns no value for it, or rejects its whole batch in a cycle run, the runner adds 1 to that (locale, key)'s count in `prosetta_state` under `cycle.failures`, together with the key's source hash. Once the count reaches 3 (`CycleFailures::LIMIT`) from the key's current English, the cycle stops sending it and flags it as skipped. Editing the English starts the count again, and a successful draft of the key clears it. Each failure is flagged in the cycle it happened in, too. Only cycle runs count; a manual `prosetta:translate` doesn't.

### The cycle

`prosetta:cycle` (registered by the service provider on a `*/N * * * *` schedule when `automation.every` is set, with `withoutOverlapping(max(2 × N, 10))`, so the overlap lock of a cycle killed mid-run expires after two intervals rather than Laravel's default 24 hours; also runnable by hand):

1. **Guard.** The running cycle's batch id is kept in `prosetta_state` (survives a cache clear). If it's still going, the command logs and exits without doing anything. A stored batch counts as abandoned — and a new cycle starts anyway, with a warning logged — only once it's missing, or every job in it has finished or failed (or it was cancelled) *and* that settled for longer than `max(60 minutes, 2 × automation.every)`; until then the guard holds even if `FinishCycle` seems to be taking a while.
2. **Sync** every namespace.
3. **Confirm** cosmetic edits (above); no AI.
4. **Build the work:** update-mode keys (any target language, substantive change) and draft-mode keys (auto-translate languages only).
5. **Queue** it as one `Translator` run, so budgets, circuits and suspensions all apply. Queued, the batch's `finally` callback dispatches `FinishCycle`; `--sync` runs it inline instead.
6. **Finish** (`Cycle::finish()`, run by `FinishCycle` or inline): approve this cycle's own clean AI drafts per `automation.approve` — drafts written since the cycle started, with *no* issues at all (not even a glossary or rewrite warning); export the languages that got at least one approval, when `automation.export` is true, except for any lang file holding a current non-AI candidate (a hand edit the sync imported for review, say), which is left as it is and flagged as `"{locale} {path} (export held: …)"` so the export doesn't revert the edit (`prosetta:export` still writes such files as before); record the heartbeat (`cycle.last_run` in `prosetta_state`); raise `CycleCompleted` with a report of what happened.
7. **An empty cycle** (nothing to sync, confirm or translate) still records the heartbeat and raises `CycleCompleted` with zero counts.

**Cycle approval only ever touches this cycle's own AI drafts** — origin AI, status still `draft`, no issues, `updated_at` at or after the moment the cycle started. It never approves a draft from an earlier run, and it never touches a person's pending manual edit (`needs_review`), even one with no issues.

**`--sync`** (for CI) runs steps 2–6 inline and exits 1 when anything is flagged, when a stale approved translation is still made from older English, or when any run is suspended; it exits 0 otherwise, printing each flagged ref.

### The `automation` config

```php
'automation' => [
    'every' => null,          // minutes between scheduled prosetta:cycle runs; null = no schedule
    'approve' => 'all',       // 'all' | 'none' (stop at drafts) | list of language codes that auto-approve
    'export' => true,         // write lang files after approving
    'rewrite_ratio' => 3.0,   // flag an update whose translation changed this many times more than the English
],
```

### Commands

| Command | What it does |
|---|---|
| `prosetta:cycle [--sync]` | Runs one background cycle. Queued by default; `--sync` runs inline and sets CI-friendly exit codes (see above). |
| `prosetta:health` | Exits 1 and prints why when automation is on and the last cycle is older than `3 × automation.every`, any circuit is halted, or a daily/monthly budget is spent; exits 0 (`healthy`) otherwise. Each problem line starts with a stable code in brackets, then the human text: `[cycle_stale]`, `[circuit_halted:{name}]` or `[budget:{period}]`. The text can change from one run to the next (the cycle's age in minutes), so an alerting host should remove duplicates by the codes, not the whole output. |
| `prosetta:circuit status` | As before, plus the last cycle's time. |

**In CI:** run `prosetta:cycle --sync` as a gate (like `prosetta:sync --check`); it fails the build on anything flagged, stale or suspended, so review happens in the lang files' git diff, not in Prosetta.

### Notifications, via events

Prosetta raises the event; it doesn't send mail itself (Undaunted's job, a later task). `Cycle::finish()` dispatches `CycleCompleted($report)` — a `CycleReport` with `drafted`, `updated`, `confirmed`, `approved`, `flagged` (a list of `"{locale} {ref}"` for this cycle's drafts with issues, followed by keys that failed this run, keys held back and export-held files, each with its reason in brackets), `files` (paths the export wrote) and `tokens`. Unlike the six resilience events, Prosetta writes no log line for `CycleCompleted` itself. The resilience events that already exist — `TranslationHalted`, `BudgetReached`, `TranslationSuspended` — are the other signals worth listening to: something needs attention, or the cycle stopped running.

## Services for your own admin

`Prosetta::reviewQueue($locale, $filters)` returns a paginator of `ReviewItem` (key, source, candidate, approved value, status, stale flag, issues, provenance), ready for Inertia props. `Prosetta::missing($locale, $filters)` lists keys with nothing yet in that locale, and `Prosetta::write($keyRef, $locale, $value, $by, approve: false)` translates any of them by hand through the same review trail. `edit(..., approve: true)` saves and approves together, or changes nothing. `edit()`, `approve()`, `approveClean()`, `reject()`, `export()`, `rename()`, `stats()` and `lookup()` complete the surface. Every method that acts on behalf of a user takes `?Authenticatable $by`; `null` means the system.

## Testing your integration

Bind `LonelyLights\Prosetta\Testing\FakeTranslationDriver` in tests. It echoes the source with ` [locale]` appended, and can be told to drop or recase placeholders, fix them on retry, or return nothing.
