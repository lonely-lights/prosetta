# Resilience and costs

Translation runs are meant to be left alone. Prosetta doesn't hammer a provider that's down, stops cleanly on problems that won't fix themselves, and picks up again by itself once the problem clears. It also keeps count of what the AI costs.

Your [driver](ai-drivers.md#telling-prosetta-what-went-wrong) tells Prosetta what kind of error happened. This page covers what Prosetta does next.

## Waiting out an outage

When a provider is down or rate-limiting, a queued job waits and tries again, backing off: 30 seconds, then 60, 120, 300, 600, and 900 from then on (with ±20% jitter so workers don't retry in step).

After 5 such failures in a row, the provider's **circuit opens**: no job calls it for a cooldown (5 minutes at first, doubling each time, up to an hour). After the cooldown, one call tests the provider. If it answers, the circuit closes and work carries on.

If a provider stays down for 6 hours (`outage_timeout`), Prosetta stops retrying and **pauses** the run. `prosetta:resume` restarts paused work once the provider answers again.

A run without a queue (`--sync`) can't wait, so it pauses at the first outage error instead.

## Stopping on problems that won't fix themselves

A bad API key, a retired model or an empty account won't recover by waiting. Prosetta **halts**: the current job ends quietly, its batch is cancelled, and the run is paused. Nothing calls that provider until you fix the problem and run:

```bash
php artisan prosetta:circuit reset
```

Paused work then resumes on the next `prosetta:resume`. To have a halt test the provider again by itself after a while, set `halt_hold` to a number of seconds.

## Commands

| Command | Does |
|---|---|
| `prosetta:circuit status` | Each circuit's state, failures, next test and last error; paused runs; budget usage; the last cycle's time |
| `prosetta:circuit reset [circuit]` | Close one circuit, or all, and clear its halt |
| `prosetta:resume` | Test each circuit that's due, and queue its paused work again if it answers |
| `prosetta:translate --estimate` | What a run would cost in strings and tokens, and whether it fits the budgets, without calling anything |

To run `prosetta:resume` on a schedule, set `resilience.resume_every` to a number of minutes (1–59). For an hour or longer, leave it `null` and schedule `prosetta:resume` yourself. Either way, Laravel's scheduler must be running.

## Token budgets

```php
'budgets' => [
    'per_run' => null,   // tokens; null = no limit
    'daily' => null,
    'monthly' => null,
],
```

Budgets count tokens (input plus output, as your driver reports them).

- `per_run` stops only the run that reaches it.
- `daily` and `monthly` stop every run and pause it. Paused work picks up again automatically once the day or month turns.

Reaching a budget never opens a circuit.

## What the AI costs

Every call records which model spent its tokens. Give Prosetta the list prices, per million tokens, by the model name your driver reports:

```php
'ai' => [
    'currency' => 'USD',
    'prices' => [
        'claude-sonnet-5' => ['input' => 3.0, 'output' => 15.0],
    ],
],
```

A dated model name (`claude-sonnet-5-20260101`) falls back to the undated price when it has none of its own. To keep prices in your own database, bind `LonelyLights\Prosetta\Contracts\PriceCatalogue` to your own class.

Then:

```php
use LonelyLights\Prosetta\Resilience\UsageLedger;

$cost = app(UsageLedger::class)->cost(from: now()->startOfMonth(), locale: 'es');

$cost->amount;          // e.g. 4.18
$cost->currency;        // 'USD'
$cost->unpricedTokens;  // tokens from models with no price, so you can say "about"
```

The [coverage report](review-ui.md#coverage) carries each language's cost this month too.

## Configuration

The full `resilience` block, with its defaults:

```php
'resilience' => [
    'cache_store' => null,            // null = your default store; it must support locks
    'backoff' => [30, 60, 120, 300, 600, 900],  // seconds per attempt; the last repeats
    'jitter' => 0.2,
    'circuit' => [
        'failure_threshold' => 5,     // failures in a row that open the circuit
        'cooldown' => 300,            // first open period, in seconds
        'cooldown_multiplier' => 2,
        'max_cooldown' => 3600,
    ],
    'outage_timeout' => 21600,        // 6 hours of outage before pausing
    'halt_hold' => null,              // seconds before a halt tests again; null = until reset
    'unknown_errors' => 'transient',  // or 'halt'
    'resume_every' => null,           // minutes between scheduled prosetta:resume runs
],
```

**Use a shared cache store that supports locks.** Circuits and pauses live in the cache, and every worker must see the same state. Redis, Memcached, DynamoDB, database and file stores all work. The `array` store only lives inside one process, so it's for tests.

## Events

All in `LonelyLights\Prosetta\Events`, raised once per change (not once per job):

| Event | Carries |
|---|---|
| `CircuitOpened` | Circuit, cooldown seconds, failure count, last error |
| `CircuitClosed` | Circuit, downtime in seconds |
| `TranslationHalted` | Circuit, reason (`rejected`, `quota`, `unknown`), message |
| `TranslationSuspended` | Circuit, reason, what was paused |
| `TranslationResumed` | Circuit, how many paused runs were queued again |
| `BudgetReached` | Period, tokens used, limit |

Prosetta also writes each to your `prosetta.log_channel`: a warning when a circuit opens, a run halts or pauses, or a budget is reached; info when a circuit closes or work resumes.

## Running it in production

- **Restart your queue workers after deploying** a new driver or changing Prosetta's config (`php artisan queue:restart`). Workers keep the old code until then.
- **`failed_jobs` stays meaningful.** Halted and paused jobs are deleted, not failed. What lands in `failed_jobs` is either a batch the provider refused (with its error) or a genuine bug, retried three times first.
- **Long runs don't expire.** A job may retry for up to seven days; the circuit and `outage_timeout` decide when to stop, not Laravel's job timeout.
