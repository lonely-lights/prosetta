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
    'resume_every' => null,              // minutes; null = the host schedules prosetta:resume itself
],

'budgets' => [
    'per_run' => null,                   // tokens; null = no limit
    'daily' => null,
    'monthly' => null,
    'estimate' => ['input_per_char' => 0.3, 'output_per_char' => 0.3, 'input_per_item' => 12, 'output_per_item' => 8],
],
```

Budgets are counted in tokens (input plus output, as the driver reports them), because there's no price catalogue yet. `per_run` stops only that run; `daily` and `monthly` stop every run, suspend it, and are picked up again automatically once the period changes. Reaching a budget never trips a circuit, and a budget is a separate gate: `prosetta:resume` checks it before requeueing anything.

Circuit and suspension state changes (opening, tripping, recording a suspended scope) go through a short cache write lock, so two workers racing the same event can't both fire it. Your `resilience.cache_store` needs to be a store that supports locks — Redis, database, file or array all work; Laravel's `memcached`/`dynamodb` file-less setups may not, check before relying on one.

**Commands:**

| Command | What it does |
|---|---|
| `prosetta:circuit status` | Each known circuit: state, failures, cooldown and time left, open since, suspended scopes, budget usage |
| `prosetta:circuit reset [circuit]` | Close a circuit, or all of them, and clear its halt; suspended work is resumed on the next `prosetta:resume` |
| `prosetta:resume` | Tests each circuit that's due (via `checkHealth()` when the driver has it, otherwise by requeueing) and queues its suspended scopes again on success |
| `prosetta:translate --estimate` | Prints, for the run it would start: strings, source characters and expected input/output tokens, without calling anything, and how that compares with each remaining budget |

`prosetta:resume` only runs on a schedule when `resilience.resume_every` is set **and** the host actually runs Laravel's scheduler (`schedule:work` locally, a cron entry calling `schedule:run` every minute in production). With `resume_every` left `null`, or no scheduler running, nothing calls `prosetta:resume` for you — run it by hand or wire up your own schedule.

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

## Services for your own admin

`Prosetta::reviewQueue($locale, $filters)` returns a paginator of `ReviewItem` (key, source, candidate, approved value, status, stale flag, issues, provenance), ready for Inertia props. `Prosetta::missing($locale, $filters)` lists keys with nothing yet in that locale, and `Prosetta::write($keyRef, $locale, $value, $by, approve: false)` translates any of them by hand through the same review trail. `edit(..., approve: true)` saves and approves together, or changes nothing. `edit()`, `approve()`, `approveClean()`, `reject()`, `export()`, `rename()`, `stats()` and `lookup()` complete the surface. Every method that acts on behalf of a user takes `?Authenticatable $by`; `null` means the system.

## Testing your integration

Bind `LonelyLights\Prosetta\Testing\FakeTranslationDriver` in tests. It echoes the source with ` [locale]` appended, and can be told to drop or recase placeholders, fix them on retry, or return nothing.
