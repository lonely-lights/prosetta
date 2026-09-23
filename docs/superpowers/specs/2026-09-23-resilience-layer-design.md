# Prosetta resilience layer: design

**Date:** 2026-09-23. **Status:** for review. **Addresses:** G1 (retry storms), and parts of G8 (budgets) and G9 (alerts) in `2026-09-23-automation-gaps-and-edge-cases.md`.

## 1. Goal

Translation runs that can be left unattended:
- they never hammer a provider that is down;
- they stop cleanly on problems that won't fix themselves (a bad key, no credits, a spending limit);
- they **recover by themselves** when the problem clears, even if nobody is around, without losing queued work;
- every threshold is set in `config/prosetta.php`, so each host decides how cautious to be.

**Out of scope** (separate specs): quality checks and auto-approval (G5, G6), the "still correct" confirm action (G3), money budgets (they need the price catalogue), named engines, and member-content queues. The design keys everything by a circuit name, so named engines slot in later without changes here.

## 2. How drivers report errors

Prosetta defines the categories; the host's driver maps its provider's errors onto them, because only the driver knows the provider.

New exceptions in `LonelyLights\Prosetta\Exceptions\Provider`, all extending an abstract `ProviderException` (itself a `ProsettaException`):

| Exception | Meaning | Prosetta's response |
|---|---|---|
| `ProviderUnavailable` | Down, overloaded, connection failure, timeout, 5xx | Backoff; counts towards the circuit |
| `ProviderRateLimited(?int $retryAfter)` | 429 | Wait `retryAfter` seconds (or the backoff); counts towards the circuit |
| `ProviderRejected` | Invalid key, unknown or retired model, malformed request | **Halt** (§5) |
| `ProviderQuotaExhausted` | Out of credits or quota | **Halt** (§5) |

Any other `Throwable` from the driver is handled according to `resilience.unknown_errors`: `'transient'` (default, treated as `ProviderUnavailable`) or `'halt'` (treated as `ProviderRejected`).

**Per-string refusals** (a provider's safety filter declines some strings) are not exceptions. `TranslationBatchResult` gains `public array $refused = []` (item id => reason). A refused string is recorded as failed with a `refused` issue and is **not** retried by the issue-retry loop; it waits for a person or, later, another engine.

**Optional health check.** A new contract, `LonelyLights\Prosetta\Contracts\ChecksHealth`:

```php
interface ChecksHealth {
    /** A near-free call that proves the provider answers. Throws a ProviderException when it doesn't. */
    public function checkHealth(): void;
}
```

A driver that implements it lets Prosetta test a provider without spending a real batch. Without it, the test is the next real job (§4).

## 3. Backoff for individual failures

A `ProviderUnavailable` (or a `ProviderRateLimited` without `retryAfter`) releases the job after `resilience.backoff[attempt]` seconds, the last entry repeating, varied by ±`resilience.jitter`. Jitter spreads jobs out, so they don't all return in the same second.

## 4. Circuit breaker

`LonelyLights\Prosetta\Resilience\Circuit` holds its state in the cache store `resilience.cache_store` (Redis in production, so every worker shares it). The circuit name is the driver class plus the model, for example `openai-driver:gpt-5.6-terra`. It has three states:

- **Closed.** Calls go through. Each success resets the failure count. After `circuit.failure_threshold` consecutive countable failures, the circuit **opens**.
- **Open.** No calls are made. A job that finds the circuit open releases itself until the cooldown ends, plus jitter, without touching the provider. The first cooldown is `circuit.cooldown`; each re-opening multiplies it by `circuit.cooldown_multiplier`, up to `circuit.max_cooldown`. Opening raises `CircuitOpened`.
- **Testing** (after a cooldown ends). Exactly **one** caller, holding an atomic cache lock, tests the provider: through `checkHealth()` if the driver has it, otherwise by running its own real batch. Everyone else keeps waiting. Success **closes** the circuit, resets the cooldown to its first value and raises `CircuitClosed` with the downtime. Failure **re-opens** it with the next, longer cooldown.

**Downtime timeout.** When a circuit has been open (including failed tests) for `resilience.outage_timeout` seconds without a break, jobs stop retrying. Each one **suspends** its scope (§6) and ends without being marked failed, and Prosetta raises `TranslationSuspended`. The circuit itself keeps cycling. Because no jobs are left to test it, the scheduled `prosetta:resume` does the testing (§6). Jobs' `retryUntil()` becomes `outage_timeout` plus the longest cooldown, so a job is never expired by Laravel before Prosetta decides.

## 5. Halts

A **halt** is a stop for a provider problem that retrying won't fix. It happens on `ProviderRejected`, `ProviderQuotaExhausted`, or any other exception when `unknown_errors = 'halt'`. (Budgets stop runs too, but they are not provider problems and never trip a circuit: see §7.)

On a halt:
1. The circuit is **tripped**: open, with a cooldown of `resilience.halt_hold` seconds. When `halt_hold` is `null`, it stays open until `php artisan prosetta:circuit reset`.
2. The current job ends quietly (it is deleted from the queue, not marked failed), and its batch is cancelled. The runner's cancelled-batch check already makes the batch's other jobs skip. `failed_jobs` stays reserved for genuine bugs.
3. The run's scope is **suspended** (§6), so nothing is lost.
4. `TranslationHalted` is raised, with the reason (`rejected`, `quota`, `unknown`) and the exception message.

When `halt_hold` ends, the circuit moves to **testing**, exactly as after an outage. A rejected key that has since been fixed, or credits that have been topped up, then recover by themselves through `prosetta:resume`. A test against a still-bad key fails at no cost and re-trips the circuit for another `halt_hold`.

## 6. Suspend and resume

**Suspending** records the run's scope in the cache under `prosetta:suspended`: circuit name, locales, namespaces, keys, the `force` flag, the reason and the time. Scopes with the same locales, namespaces and keys are merged, so repeated suspensions don't pile up.

**`php artisan prosetta:resume`** does the following for each circuit that has suspended work:
- **Circuit open, cooldown not over:** nothing happens.
- **Circuit ready to test, driver has `checkHealth()`:** run the health check.
  - **On success:** the circuit closes; each suspended scope is queued again through the ordinary `translate()` path, which skips anything already done; the suspension is cleared; `TranslationResumed` is raised.
  - **On failure:** the circuit re-opens with the next cooldown.
- **Circuit ready to test, no health check:** the scope is queued again. The circuit's own testing state (§4) then lets exactly one of those jobs make the test call while the others wait, so the provider still sees a single request.
- **Circuit closed:** the scope is queued again straight away.

**Scheduling.** When `resilience.resume_every` is set (minutes), the service provider registers `prosetta:resume` with Laravel's scheduler at that interval, using `withoutOverlapping()`. `null` leaves scheduling to the host. The command is also safe to run by hand.

## 7. Token budgets

Budgets are in **tokens** (input plus output plus reasoning, as the driver reports them), because there are no prices yet.

| Setting | Scope | When it's reached | Recovers |
|---|---|---|---|
| `budgets.per_run` | One `translate()` call (its batch) | That run stops: its batch is cancelled and its scope is **not** suspended, since the cap was for that run. Other runs carry on | No; start a new run |
| `budgets.daily` | Calendar day, in the app time zone | Every run stops and its scope is suspended | Automatically: `prosetta:resume` requeues suspended work once the day has changed |
| `budgets.monthly` | Calendar month | As daily | Automatically on the 1st |

`null` means no limit. Budgets are a separate gate from circuits: reaching one never trips a circuit, and `prosetta:resume` checks the budget before requeueing. Spending is counted in the cache (per run, day and month) after each call. A call is refused **before** it's made when the counter is already at or over the limit. A single batch can overshoot a limit by at most one batch's tokens, which is the accepted trade-off for not estimating every call. Reaching a limit raises `BudgetReached` (period, used, limit) once per period, and the stopped jobs end quietly as in §5.

**`php artisan prosetta:translate --estimate`** prints, for the run it would start: strings, source characters and expected input and output tokens, without calling anything. The per-character rates come from the locale's own history when it has at least 50 AI drafts, otherwise from defaults (`budgets.estimate` in config). It also prints how the estimate compares with each remaining budget.

## 8. Synchronous runs

`prosetta:translate --sync` and `Prosetta::translate(queue: false)` use the same circuit and budget checks. Instead of releasing a job, they stop with a clear console error: circuit open (and until when), halted (and why), or budget reached. A `--sync` run whose provider fails mid-way suspends its remaining scope like a queued run, so `prosetta:resume` picks it up.

## 9. Events

All are in `LonelyLights\Prosetta\Events`. The host listens and decides how to notify.

| Event | Payload |
|---|---|
| `CircuitOpened` | circuit, cooldown seconds, failure count, last error message |
| `CircuitClosed` | circuit, downtime seconds |
| `TranslationHalted` | circuit, reason (`rejected`, `quota`, `unknown`), message |
| `TranslationSuspended` | circuit, reason, scope |
| `TranslationResumed` | circuit, scopes queued |
| `BudgetReached` | period, used, limit |

`TranslationHalted` carries no scope: the circuit trips independently of any one run. The `TranslationSuspended` raised for the same halt (§5 step 3) carries the scope, since that is what `prosetta:resume` needs to queue again.

Prosetta also writes a line to `log_channel` for each: warning for opened, halted, suspended and budget; info for closed and resumed.

## 10. Commands

| Command | What it does |
|---|---|
| `prosetta:circuit status` | Each known circuit: state, failures, cooldown and time left, open since, suspended scopes, budget usage |
| `prosetta:circuit reset [circuit]` | Close a circuit, or all of them, and clear its halt; suspended work is resumed on the next `prosetta:resume` |
| `prosetta:resume` | §6 |
| `prosetta:translate --estimate` | §7 |

## 11. Configuration

Package defaults:

```php
'resilience' => [
    'cache_store' => null,               // null = the default store. Use a shared store (Redis) with more than one worker
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

Undaunted's values, for testing:

```php
'resilience' => [
    'halt_hold' => 600,                  // halts test themselves after 10 minutes
    'resume_every' => 10,                // resume suspended work within 10 minutes of recovery
    // everything else: the package defaults
],
'budgets' => ['per_run' => 250_000, 'daily' => 500_000, 'monthly' => 5_000_000],
```

The defaults for `estimate` fit the Spanish run: 1,795 strings of 63,094 source characters produced 40,161 input and 33,752 output tokens (0.3 × 63,094 + 12 × 1,795 ≈ 40.5k; 0.3 × 63,094 + 8 × 1,795 ≈ 33.3k).

## 12. Undaunted's side

- **`LaravelAiTranslationDriver` maps errors:**
  - `RateLimitedException` → `ProviderRateLimited`, with `retryAfter` when laravel/ai exposes it;
  - `ProviderOverloadedException` and `ProviderConnectionException` → `ProviderUnavailable`;
  - `InsufficientCreditsException` → `ProviderQuotaExhausted`;
  - an HTTP 401, 403, 404 or 400 from the provider → `ProviderRejected`;
  - anything else is left to `unknown_errors`.
- **It implements `checkHealth()`** by asking the Translator agent to translate the single word "OK" into Spanish with the configured model. That costs a handful of tokens, is recorded in `ai_usage` like any call, and proves both the key and the model.
- **Listeners** for the §9 events log to the app log for now. Notifications (mail or the Bridge) come later.
- **The translations worker** needs `queue:restart` on deploy (G4). That's noted in the README, not solved here.

## 13. Testing

**Prosetta (unit and feature, SQLite, array cache, fake clock):**
- A `ScriptedDriver` test double throws a given sequence of exceptions or returns results.
- Backoff: delays per attempt, jitter bounds, and the last delay repeating.
- Circuit: opens at the threshold; stays open, releasing jobs without calling the driver; allows exactly one test after the cooldown when two jobs arrive at once; closes on success and resets the cooldown; re-opens on failure with a doubled cooldown, capped at the maximum.
- Health check used for the test when the driver has it, and a real job used when it doesn't.
- Halts: `ProviderRejected` and `ProviderQuotaExhausted` end the job quietly (not in `failed_jobs`), cancel the batch, trip the circuit and suspend the scope. `halt_hold = null` waits for a reset; a number tests after the hold.
- Outage timeout: jobs suspend instead of retrying, and `retryUntil` covers the timeout.
- Resume: queues suspended scopes after a successful test and clears them; doesn't when the test fails; merges duplicate scopes.
- Budgets: per-run stops that run only, without suspending or tripping a circuit; daily and monthly stop, suspend and are requeued by `prosetta:resume` after the period ends; a call is refused when the counter is already over.
- Refused strings are recorded as failed with `refused` and not retried.
- `unknown_errors` both ways.
- `--sync` stops with the right message in each state.
- `--estimate` from history and from defaults.
- Events raised once per state change, not once per job.
- `withFakeQueueInteractions()` asserts `release($delay)` and `fail()` on jobs.

**Undaunted:**
- Each laravel/ai exception maps to the right Prosetta exception.
- `checkHealth()` makes one small call.
- End to end with `Translator::fake()` throwing: the circuit opens after five failures, then recovers through `prosetta:resume`.
- The config values above.

## 14. Compatibility

- **Drivers that throw plain exceptions** keep working under `unknown_errors = 'transient'`, which is now safer than before (backoff and circuit instead of immediate retries).
- **`TranslationBatchResult::$refused`** has a default, so existing drivers and tests are unaffected.
- **No migrations.** All state is in the cache: a cache flush resets circuits and budgets and forgets suspended scopes. That's the trade-off for needing no schema. The suspended work is still outstanding in the database, and the next ordinary `translate` picks it up.
