# Resilience Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Prosetta's translation runs safe to leave unattended: classify provider errors, back off, open a per-driver-and-model circuit breaker, halt on unrecoverable errors, enforce token budgets, suspend work instead of losing it, and resume it automatically.

**Architecture:** A `ProviderGate` wraps every driver call made by `TranslationRunner`. It checks the budget and the `Circuit` (state in the cache), runs the optional health check during the circuit's test phase, classifies failures into four `ProviderException` types, trips or opens the circuit, and records spending. `TranslateBatch` turns the gate's exceptions into `release($delay)`, or into "suspend the run's scope, cancel the batch, delete the job". `prosetta:resume`, which can be scheduled, tests the circuit and requeues suspended scopes.

**Tech Stack:** PHP 8.3+, Laravel 11–13 (`illuminate/*`), Pest 5, Orchestra Testbench 11, the cache `Repository` and `LockProvider`, `Illuminate\Bus\Batch`. Undaunted: laravel/ai.

**Spec:** `docs/superpowers/specs/2026-09-23-resilience-layer-design.md`.

## Global Constraints

- `declare(strict_types=1);` in every new PHP file; four-space indents; braces on the same line (Prosetta's existing style); `final readonly class` for stateless services.
- No new Composer dependencies. No migrations: all state lives in the cache store `prosetta.resilience.cache_store` (null = default store).
- Config keys and defaults exactly as spec §11 (the `estimate` defaults are `0.3 / 0.3 / 12 / 8`).
- Undaunted's values: `halt_hold` 600, `resume_every` 10; budgets `per_run` 250,000, `daily` 500,000, `monthly` 5,000,000.
- Halted or suspended jobs end with `$this->delete()`, **never** `fail()`: `failed_jobs` stays reserved for genuine bugs.
- Budgets never trip a circuit.
- Events are raised once per state change, not once per job: `CircuitOpened` only on closed → open; `TranslationHalted` only when a trip is new; `TranslationSuspended` only for a scope not already suspended; `BudgetReached` once per period.
- Commit messages end with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2
  ```
- Prosetta tests: `vendor/bin/pest` in `C:\Websites\packages\prosetta`. Undaunted: `php artisan test --parallel` and `npm run -s test:run` in `C:\Websites\undaunted\undaunted-web`. Never delete anything through Undaunted's `vendor/lonely-lights/prosetta` (it's a junction to the package).

## Review Focus

1. **Two jobs reaching a circuit's test phase at the same moment:** exactly one calls the provider; the other waits. (Task 2 test "lets exactly one caller test".)
2. **A job released many times while a circuit is open** must not run out of attempts or `retryUntil` before `outage_timeout` decides. (Task 7 test "keeps retryUntil beyond the outage timeout".)
3. **A retry call that fails after the first attempt succeeded:** the first attempt's drafts, already paid for, must be saved before the exception propagates. (Task 6 test "keeps the first attempt's drafts when the retry call fails".)
4. **A cache flush, or a circuit name nobody has used yet:** `state()` must default to closed, never error. (Task 2 test "defaults to closed for an unknown circuit".)
5. **Resuming twice, or resuming a scope whose work is already done:** no duplicate AI calls, because `translate()` skips current keys; the suspension is cleared either way. (Task 8 test "resuming twice queues nothing new".)

---

## File Structure

**Prosetta, new:**
- `src/Exceptions/Provider/ProviderException.php`, `ProviderUnavailable.php`, `ProviderRateLimited.php`, `ProviderRejected.php`, `ProviderQuotaExhausted.php`: the driver-facing error categories.
- `src/Contracts/ChecksHealth.php`: the optional health check.
- `src/Resilience/Backoff.php`: delays and jitter.
- `src/Resilience/Decision.php`: what the circuit allows right now.
- `src/Resilience/Circuit.php`, `src/Resilience/Circuits.php`: one circuit's state machine, and the registry of names.
- `src/Resilience/Budget.php`: token counters and limits.
- `src/Resilience/RunScope.php`, `src/Resilience/Suspensions.php`: what a run covered, and the suspended list.
- `src/Resilience/CallDeferred.php`, `src/Resilience/BudgetExhausted.php`: internal control-flow exceptions.
- `src/Resilience/ProviderGate.php`: wraps every driver call.
- `src/Resilience/LogResilienceEvents.php`: an event subscriber that writes log lines.
- `src/Events/CircuitOpened.php`, `CircuitClosed.php`, `TranslationHalted.php`, `TranslationSuspended.php`, `TranslationResumed.php`, `BudgetReached.php`.
- `src/Translation/Estimator.php`.
- `src/Console/ResumeCommand.php`, `src/Console/CircuitCommand.php`.
- `src/Testing/ScriptedDriver.php`, `src/Testing/HealthCheckedScriptedDriver.php`.

**Prosetta, modified:** `config/prosetta.php`, `src/Support/Settings.php`, `src/Data/TranslationBatchResult.php`, `src/Translation/TranslationRunner.php`, `src/Translation/TranslateReport.php`, `src/Translation/Translator.php`, `src/Jobs/TranslateBatch.php`, `src/Console/TranslateCommand.php`, `src/ProsettaServiceProvider.php`, `README.md`, `docs/handoff/2026-09-22-undaunted-adoption.md`.

**Undaunted, modified:** `app/Services/Translation/LaravelAiTranslationDriver.php`, `config/prosetta.php`, `composer.json` (a `schedule:work` process in `dev`), `tests/Feature/Ai/TranslationDriverTest.php`; **new** `tests/Feature/Ai/TranslationResilienceTest.php`.

---

### Task 1: Config, provider exceptions, health-check contract, refused results, backoff

**Files:**
- Modify: `config/prosetta.php` (append two blocks before `'log_channel'`)
- Modify: `src/Support/Settings.php`
- Modify: `src/Data/TranslationBatchResult.php`
- Create: `src/Exceptions/Provider/ProviderException.php`, `ProviderUnavailable.php`, `ProviderRateLimited.php`, `ProviderRejected.php`, `ProviderQuotaExhausted.php`
- Create: `src/Contracts/ChecksHealth.php`
- Create: `src/Resilience/Backoff.php`
- Test: `tests/Unit/Resilience/BackoffTest.php`, `tests/Feature/ServiceProviderTest.php` (append)

**Interfaces:**
- Produces:
  - `Settings::cache(): \Illuminate\Contracts\Cache\Repository`
  - `abstract class ProviderException extends ProsettaException { public ?string $circuit = null; }`
  - `ProviderRateLimited::__construct(string $message = 'The provider is rate limiting requests.', public readonly ?int $retryAfter = null, ?Throwable $previous = null)`
  - `ProviderUnavailable`, `ProviderRejected` and `ProviderQuotaExhausted` use `RuntimeException`'s constructor
  - `interface ChecksHealth { public function checkHealth(): void; }`
  - `TranslationBatchResult` gains `public array $refused = []` (array<string, string>) as its last constructor parameter
  - `Backoff::delay(int $attempt): int`, `Backoff::jitter(int $seconds): int`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Resilience/BackoffTest.php`:
```php
<?php

use LonelyLights\Prosetta\Resilience\Backoff;

it('walks the configured steps and repeats the last one', function () {
    config(['prosetta.resilience.backoff' => [30, 60, 120], 'prosetta.resilience.jitter' => 0]);

    expect([Backoff::delay(1), Backoff::delay(2), Backoff::delay(3), Backoff::delay(4), Backoff::delay(9)])
        ->toBe([30, 60, 120, 120, 120])
        ->and(Backoff::delay(0))->toBe(30);
});

it('varies a delay by at most the jitter fraction and never below one second', function () {
    config(['prosetta.resilience.jitter' => 0.2]);

    foreach (range(1, 200) as $ignored) {
        expect(Backoff::jitter(100))->toBeGreaterThanOrEqual(80)->toBeLessThanOrEqual(120);
    }

    expect(Backoff::jitter(1))->toBeGreaterThanOrEqual(1);
});
```

Append to `tests/Feature/ServiceProviderTest.php`:
```php
it('ships the resilience and budget defaults', function () {
    expect(config('prosetta.resilience.circuit'))->toBe(['failure_threshold' => 5, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 3600])
        ->and(config('prosetta.resilience.outage_timeout'))->toBe(21600)
        ->and(config('prosetta.resilience.halt_hold'))->toBeNull()
        ->and(config('prosetta.resilience.unknown_errors'))->toBe('transient')
        ->and(config('prosetta.resilience.resume_every'))->toBeNull()
        ->and(config('prosetta.budgets.daily'))->toBeNull()
        ->and(config('prosetta.budgets.estimate'))->toBe(['input_per_char' => 0.3, 'output_per_char' => 0.3, 'input_per_item' => 12, 'output_per_item' => 8]);
});

it('keeps a driver result compatible when it reports no refusals', function () {
    $result = new \LonelyLights\Prosetta\Data\TranslationBatchResult(['1' => 'Hola'], 'fake', 'm');

    expect($result->refused)->toBe([]);
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/pest tests/Unit/Resilience/BackoffTest.php tests/Feature/ServiceProviderTest.php`
Expected: FAIL. `Class "LonelyLights\Prosetta\Resilience\Backoff" not found`, null config values, and `Undefined property: ...::$refused`.

- [ ] **Step 3: Implement**

`config/prosetta.php`: insert before `'log_channel' => null,`:
```php
    /*
    | How Prosetta treats a provider that fails. Backoff spaces out retries of
    | one job; the circuit stops every job from calling a provider that keeps
    | failing, tests it with one call after a cooldown, and suspends work after
    | outage_timeout seconds of downtime. A halt (bad key, no credits) trips the
    | circuit for halt_hold seconds (null = until prosetta:circuit reset).
    | Suspended work is requeued by prosetta:resume, scheduled every
    | resume_every minutes when set. Use a shared cache store (Redis) with more
    | than one worker, so every worker sees the same circuit.
    */
    'resilience' => [
        'cache_store' => env('PROSETTA_CACHE_STORE'),
        'backoff' => [30, 60, 120, 300, 600, 900],
        'jitter' => 0.2,
        'circuit' => [
            'failure_threshold' => 5,
            'cooldown' => 300,
            'cooldown_multiplier' => 2,
            'max_cooldown' => 3600,
        ],
        'outage_timeout' => 21600,
        'halt_hold' => null,
        'unknown_errors' => 'transient',
        'resume_every' => null,
    ],

    /*
    | Token budgets (input + output, as drivers report them); null = no limit.
    | per_run stops only that run; daily and monthly stop every run and
    | suspend it until the period changes. estimate holds the rates
    | prosetta:translate --estimate uses before a locale has 50 AI drafts.
    */
    'budgets' => [
        'per_run' => null,
        'daily' => null,
        'monthly' => null,
        'estimate' => ['input_per_char' => 0.3, 'output_per_char' => 0.3, 'input_per_item' => 12, 'output_per_item' => 8],
    ],

```

`src/Support/Settings.php`: add `use Illuminate\Contracts\Cache\Repository;` and `use Illuminate\Support\Facades\Cache;`, and the method:
```php
    /** The cache store that holds circuits, budgets and suspended work. */
    public static function cache(): Repository {
        $store = config('prosetta.resilience.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
```

`src/Data/TranslationBatchResult.php`: replace the class body:
```php
/** Values keyed by item id, what the call cost, and any items the provider refused. */
final readonly class TranslationBatchResult {
    /**
     * @param array<string, string> $values
     * @param array<string, string> $refused item id => the provider's reason
     */
    public function __construct(
        public array $values,
        public string $provider,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?string $invocationId = null,
        public array $refused = [],
    ) {}
}
```

`src/Exceptions/Provider/ProviderException.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

use LonelyLights\Prosetta\Exceptions\ProsettaException;

/**
 * What a TranslationDriver throws when its provider fails, so Prosetta can
 * tell a passing outage from a problem retrying won't fix. The gate fills in
 * the circuit the failure belongs to.
 */
abstract class ProviderException extends ProsettaException {
    public ?string $circuit = null;
}
```

`src/Exceptions/Provider/ProviderUnavailable.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

/** Down, overloaded, unreachable, timed out or a 5xx: worth retrying later. */
final class ProviderUnavailable extends ProviderException {}
```

`src/Exceptions/Provider/ProviderRejected.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

/** An invalid key, an unknown or retired model, or a malformed request: retrying won't help. */
final class ProviderRejected extends ProviderException {}
```

`src/Exceptions/Provider/ProviderQuotaExhausted.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

/** Out of credits or quota: stops until someone tops up, or the hold ends and a test passes. */
final class ProviderQuotaExhausted extends ProviderException {}
```

`src/Exceptions/Provider/ProviderRateLimited.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

use Throwable;

/** A 429. retryAfter is the provider's own wait, in seconds, when it gave one. */
final class ProviderRateLimited extends ProviderException {
    public function __construct(string $message = 'The provider is rate limiting requests.', public readonly ?int $retryAfter = null, ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
    }
}
```

`src/Contracts/ChecksHealth.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Contracts;

use LonelyLights\Prosetta\Exceptions\Provider\ProviderException;

/**
 * Optional for a TranslationDriver. A near-free call that proves the
 * provider answers, used to test an open circuit without risking a batch.
 */
interface ChecksHealth {
    /** @throws ProviderException when the provider does not answer properly */
    public function checkHealth(): void;
}
```

`src/Resilience/Backoff.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

/** Retry delays for one job, from prosetta.resilience.backoff, spread by jitter so jobs don't return together. */
final class Backoff {
    /** Seconds before attempt number $attempt (1-based) is retried; the last step repeats. */
    public static function delay(int $attempt): int {
        $steps = array_values(array_map('intval', (array) config('prosetta.resilience.backoff', [30])));

        if ($steps === []) {
            return self::jitter(30);
        }

        return self::jitter($steps[min(max($attempt, 1), count($steps)) - 1]);
    }

    /** $seconds varied by ±prosetta.resilience.jitter (a fraction), never below one second. */
    public static function jitter(int $seconds): int {
        $spread = max(0.0, min(1.0, (float) config('prosetta.resilience.jitter', 0.2)));
        $range = (int) round($seconds * $spread);

        return max(1, $seconds + ($range > 0 ? random_int(-$range, $range) : 0));
    }
}
```

- [ ] **Step 4: Run them to see them pass, then the whole suite**

Run: `vendor/bin/pest tests/Unit/Resilience/BackoffTest.php tests/Feature/ServiceProviderTest.php` → PASS.
Run: `vendor/bin/pest` → all green (195 before this task, plus 4).

- [ ] **Step 5: Commit**

```bash
git add config/prosetta.php src/Support/Settings.php src/Data/TranslationBatchResult.php src/Exceptions/Provider src/Contracts/ChecksHealth.php src/Resilience/Backoff.php tests/Unit/Resilience/BackoffTest.php tests/Feature/ServiceProviderTest.php
git commit -m "Add resilience config, provider error classes, the health-check contract and backoff"
```
(Add the trailers from Global Constraints to every commit.)

---

### Task 2: Circuit breaker

**Files:**
- Create: `src/Resilience/Decision.php`, `src/Resilience/Circuit.php`, `src/Resilience/Circuits.php`
- Create: `src/Events/CircuitOpened.php`, `src/Events/CircuitClosed.php`
- Test: `tests/Feature/Resilience/CircuitTest.php`

**Interfaces:**
- Consumes: `Settings::cache()` (Task 1).
- Produces:
  - `final readonly class Decision { public string $kind; public int $seconds; public ?Lock $lock; }` with the kinds `call`, `test`, `wait` and `held`; static constructors `call()`, `test(Lock)`, `wait(int)`, `held()`; and `release(): void` (releases the lock if held).
  - `Circuit` (non-readonly final class, `public readonly string $name`):
    - `state(): array{state: string, failures: int, opened_at: ?int, until: ?int, cooldown: int, reason: ?string, halt: ?string, message: ?string}`
    - `decision(): Decision`
    - `recordSuccess(?Decision $decision = null): void`
    - `recordFailure(string $message, ?Decision $decision = null): void`
    - `trip(string $halt, string $message, ?Decision $decision = null): bool` (true when newly tripped)
    - `outageExceeded(): bool`
    - `reset(): void`
  - `Circuits`: `for(string $name): Circuit`, `names(): list<string>`, `static nameFor(object $driver, ?string $model): string`.
  - Events: `CircuitOpened(string $circuit, int $cooldown, int $failures, string $message)` and `CircuitClosed(string $circuit, int $downtime)`, both `final readonly` with public promoted properties and `use Dispatchable`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Resilience/CircuitTest.php`:
```php
<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Events\CircuitClosed;
use LonelyLights\Prosetta\Events\CircuitOpened;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;

beforeEach(function () {
    config([
        'prosetta.resilience.cache_store' => 'array',
        'prosetta.resilience.jitter' => 0,
        'prosetta.resilience.circuit' => ['failure_threshold' => 3, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 1000],
    ]);
    Event::fake([CircuitOpened::class, CircuitClosed::class]);
    $this->circuit = app(Circuits::class)->for('fake:model');
});

it('defaults to closed for an unknown circuit', function () {
    expect($this->circuit->state()['state'])->toBe('closed')
        ->and($this->circuit->decision()->kind)->toBe('call');
});

it('names a circuit after the driver and model', function () {
    expect(Circuits::nameFor(new FakeTranslationDriver, 'gpt-x'))->toBe('fake-translation-driver:gpt-x')
        ->and(Circuits::nameFor(new FakeTranslationDriver, null))->toBe('fake-translation-driver:default');
});

it('opens after the threshold of consecutive failures, once', function () {
    $this->circuit->recordFailure('boom');
    $this->circuit->recordFailure('boom');
    expect($this->circuit->decision()->kind)->toBe('call');

    $this->circuit->recordFailure('boom');
    $decision = $this->circuit->decision();

    expect($decision->kind)->toBe('wait')
        ->and($decision->seconds)->toBe(300);
    Event::assertDispatchedTimes(CircuitOpened::class, 1);
});

it('forgets failures after a success', function () {
    $this->circuit->recordFailure('boom');
    $this->circuit->recordFailure('boom');
    $this->circuit->recordSuccess();
    $this->circuit->recordFailure('boom');

    expect($this->circuit->decision()->kind)->toBe('call');
});

it('lets exactly one caller test once the cooldown is over', function () {
    foreach (range(1, 3) as $ignored) {
        $this->circuit->recordFailure('boom');
    }
    $this->travel(301)->seconds();

    $first = app(Circuits::class)->for('fake:model')->decision();
    $second = app(Circuits::class)->for('fake:model')->decision();

    expect($first->kind)->toBe('test')
        ->and($second->kind)->toBe('wait');
});

it('closes on a successful test and reports the downtime', function () {
    foreach (range(1, 3) as $ignored) {
        $this->circuit->recordFailure('boom');
    }
    $this->travel(301)->seconds();
    $test = $this->circuit->decision();
    $this->circuit->recordSuccess($test);

    expect($this->circuit->decision()->kind)->toBe('call');
    Event::assertDispatched(CircuitClosed::class, fn (CircuitClosed $event) => $event->circuit === 'fake:model' && $event->downtime >= 301);
});

it('re-opens on a failed test with a longer cooldown, capped at the maximum', function () {
    foreach (range(1, 3) as $ignored) {
        $this->circuit->recordFailure('boom');
    }

    foreach ([600, 1000, 1000] as $expected) {
        $this->travel($this->circuit->decision()->seconds + 1)->seconds();
        $test = $this->circuit->decision();
        expect($test->kind)->toBe('test');
        $this->circuit->recordFailure('still down', $test);
        expect($this->circuit->decision()->seconds)->toBe($expected);
    }

    Event::assertDispatchedTimes(CircuitOpened::class, 1);
});

it('trips on a halt, holding for halt_hold or until reset', function () {
    config(['prosetta.resilience.halt_hold' => null]);
    expect($this->circuit->trip('rejected', 'bad key'))->toBeTrue()
        ->and($this->circuit->trip('rejected', 'bad key'))->toBeFalse()
        ->and($this->circuit->decision()->kind)->toBe('held');

    $this->circuit->reset();
    config(['prosetta.resilience.halt_hold' => 600]);
    $this->circuit->trip('quota', 'no credits');

    expect($this->circuit->decision()->kind)->toBe('wait')
        ->and($this->circuit->decision()->seconds)->toBe(600)
        ->and($this->circuit->state()['halt'])->toBe('quota');

    $this->travel(601)->seconds();
    $test = $this->circuit->decision();
    $this->circuit->recordFailure('still no credits', $test);

    expect($this->circuit->decision()->seconds)->toBe(600);
});

it('knows when an outage has lasted longer than outage_timeout', function () {
    config(['prosetta.resilience.outage_timeout' => 3600]);
    foreach (range(1, 3) as $ignored) {
        $this->circuit->recordFailure('boom');
    }
    expect($this->circuit->outageExceeded())->toBeFalse();

    $this->travel(3601)->seconds();

    expect($this->circuit->outageExceeded())->toBeTrue();
});

it('remembers every circuit it has handed out', function () {
    app(Circuits::class)->for('other:model');

    expect(app(Circuits::class)->names())->toBe(['fake:model', 'other:model']);
});
```

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/pest tests/Feature/Resilience/CircuitTest.php`
Expected: FAIL. `Class "LonelyLights\Prosetta\Resilience\Circuits" not found`.

- [ ] **Step 3: Implement**

`src/Events/CircuitOpened.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A circuit stopped calling its provider after repeated failures. Raised on closed → open only. */
final readonly class CircuitOpened {
    use Dispatchable;

    public function __construct(public string $circuit, public int $cooldown, public int $failures, public string $message) {}
}
```
(Check `src/Events/TranslationDrafted.php` first. If the existing events don't use `Dispatchable`, match their style and drop the trait.)

`src/Events/CircuitClosed.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A circuit's test call succeeded; calls flow again. $downtime is seconds since it opened. */
final readonly class CircuitClosed {
    use Dispatchable;

    public function __construct(public string $circuit, public int $downtime) {}
}
```

`src/Resilience/Decision.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Contracts\Cache\Lock;

/**
 * What a circuit allows right now: call the provider, make the one test
 * call (holding the test lock), wait $seconds, or stay held until reset.
 */
final readonly class Decision {
    private function __construct(public string $kind, public int $seconds = 0, public ?Lock $lock = null) {}

    public static function call(): self {
        return new self('call');
    }

    public static function test(Lock $lock): self {
        return new self('test', 0, $lock);
    }

    public static function wait(int $seconds): self {
        return new self('wait', max(1, $seconds));
    }

    public static function held(): self {
        return new self('held');
    }

    public function release(): void {
        $this->lock?->release();
    }
}
```

`src/Resilience/Circuit.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use LonelyLights\Prosetta\Events\CircuitClosed;
use LonelyLights\Prosetta\Events\CircuitOpened;

/**
 * One provider's circuit breaker, its state in the cache so every worker
 * shares it. Closed: calls flow. Open: nobody calls until the cooldown ends,
 * then exactly one caller (holding a lock) tests; success closes it, failure
 * re-opens it with a longer cooldown. A halt trips it for halt_hold seconds,
 * or until reset when halt_hold is null.
 */
final class Circuit {
    /** Seconds the test lock is held before it expires on its own: a job's timeout plus a margin. */
    private const int TEST_LOCK_SECONDS = 330;

    public function __construct(public readonly string $name, private readonly Repository $cache, private readonly Dispatcher $events) {}

    /** @return array{state: string, failures: int, opened_at: ?int, until: ?int, cooldown: int, reason: ?string, halt: ?string, message: ?string} */
    public function state(): array {
        $default = ['state' => 'closed', 'failures' => 0, 'opened_at' => null, 'until' => null, 'cooldown' => 0, 'reason' => null, 'halt' => null, 'message' => null];
        $stored = $this->cache->get($this->key());

        return is_array($stored) ? array_replace($default, array_intersect_key($stored, $default)) : $default;
    }

    public function decision(): Decision {
        $state = $this->state();

        if ($state['state'] === 'closed') {
            return Decision::call();
        }

        if ($state['until'] === null) {
            return Decision::held();
        }

        $now = now()->getTimestamp();

        if ($now < $state['until']) {
            return Decision::wait($state['until'] - $now);
        }

        $lock = $this->cache->lock($this->key().':test', self::TEST_LOCK_SECONDS);

        return $lock->get() ? Decision::test($lock) : Decision::wait(60);
    }

    public function recordSuccess(?Decision $decision = null): void {
        $state = $this->state();

        if ($state['state'] === 'open') {
            $this->events->dispatch(new CircuitClosed($this->name, now()->getTimestamp() - (int) $state['opened_at']));
            $this->cache->forget($this->key());
        } elseif ($state['failures'] > 0) {
            $this->cache->forget($this->key());
        }

        $decision?->release();
    }

    public function recordFailure(string $message, ?Decision $decision = null): void {
        $state = $this->state();
        $now = now()->getTimestamp();
        $state['message'] = $message;

        if ($state['state'] === 'closed') {
            $state['failures']++;

            if ($state['failures'] >= $this->setting('failure_threshold', 5)) {
                $cooldown = $this->setting('cooldown', 300);
                $state = [...$state, 'state' => 'open', 'opened_at' => $now, 'cooldown' => $cooldown, 'until' => $now + $cooldown, 'reason' => 'outage'];
                $this->save($state);
                $this->events->dispatch(new CircuitOpened($this->name, $cooldown, $state['failures'], $message));
            } else {
                $this->save($state);
            }
        } elseif ($state['reason'] === 'halt') {
            $hold = $this->haltHold();
            $this->save([...$state, 'until' => $hold === null ? null : $now + $hold]);
        } else {
            $cooldown = min($this->setting('max_cooldown', 3600), (int) round(max(1, $state['cooldown']) * max(1.0, (float) config('prosetta.resilience.circuit.cooldown_multiplier', 2))));
            $this->save([...$state, 'cooldown' => $cooldown, 'until' => $now + $cooldown]);
        }

        $decision?->release();
    }

    /** Trips the circuit for a halt; true when it wasn't already halted, so the caller raises TranslationHalted once. */
    public function trip(string $halt, string $message, ?Decision $decision = null): bool {
        $state = $this->state();
        $now = now()->getTimestamp();
        $hold = $this->haltHold();
        $new = ! ($state['state'] === 'open' && $state['reason'] === 'halt');

        $this->save([
            ...$state, 'state' => 'open', 'reason' => 'halt', 'halt' => $halt, 'message' => $message,
            'opened_at' => $state['opened_at'] ?? $now, 'cooldown' => $hold ?? 0, 'until' => $hold === null ? null : $now + $hold,
        ]);
        $decision?->release();

        return $new;
    }

    public function outageExceeded(): bool {
        $state = $this->state();

        return $state['state'] === 'open'
            && $state['opened_at'] !== null
            && now()->getTimestamp() - $state['opened_at'] >= (int) config('prosetta.resilience.outage_timeout', 21600);
    }

    public function reset(): void {
        $this->cache->forget($this->key());
        $this->cache->lock($this->key().':test')->forceRelease();
    }

    private function key(): string {
        return "prosetta:circuit:$this->name";
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void {
        $this->cache->forever($this->key(), $state);
    }

    private function setting(string $key, int $default): int {
        return max(1, (int) config("prosetta.resilience.circuit.$key", $default));
    }

    private function haltHold(): ?int {
        $hold = config('prosetta.resilience.halt_hold');

        return $hold === null ? null : max(1, (int) $hold);
    }
}
```

`src/Resilience/Circuits.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use LonelyLights\Prosetta\Support\Settings;

/** Hands out circuits by name and remembers the names, for prosetta:circuit status. */
final readonly class Circuits {
    private const string NAMES = 'prosetta:circuits';

    public function __construct(private Dispatcher $events) {}

    public function for(string $name): Circuit {
        $cache = Settings::cache();
        $names = (array) $cache->get(self::NAMES, []);

        if (! in_array($name, $names, true)) {
            $cache->forever(self::NAMES, [...$names, $name]);
        }

        return new Circuit($name, $cache, $this->events);
    }

    /** @return list<string> */
    public function names(): array {
        return array_values((array) Settings::cache()->get(self::NAMES, []));
    }

    /** "fake-translation-driver:gpt-x": the driver's class in kebab case, then the model (or "default"). */
    public static function nameFor(object $driver, ?string $model): string {
        return Str::kebab(class_basename($driver)).':'.($model ?? 'default');
    }
}
```

- [ ] **Step 4: Run to see it pass, then the whole suite**

Run: `vendor/bin/pest tests/Feature/Resilience/CircuitTest.php` → PASS (10 tests).
Run: `vendor/bin/pest` → all green.

- [ ] **Step 5: Commit**

```bash
git add src/Resilience/Decision.php src/Resilience/Circuit.php src/Resilience/Circuits.php src/Events/CircuitOpened.php src/Events/CircuitClosed.php tests/Feature/Resilience/CircuitTest.php
git commit -m "Add a cache-backed circuit breaker with escalating cooldowns and a single test caller"
```

---

### Task 3: Token budgets

**Files:**
- Create: `src/Resilience/Budget.php`, `src/Resilience/BudgetExhausted.php`, `src/Events/BudgetReached.php`
- Test: `tests/Feature/Resilience/BudgetTest.php`

**Interfaces:**
- Consumes: `Settings::cache()`.
- Produces:
  - `Budget::exhausted(?string $runId): ?string`: returns `'per_run'`, `'daily'` or `'monthly'` (checked in that order), or `null`.
  - `Budget::record(?string $runId, int $tokens): void`
  - `Budget::usage(?string $runId = null): array<string, array{used: int, limit: ?int}>`, with keys `per_run` (only when `$runId` is given), `daily` and `monthly`.
  - `final class BudgetExhausted extends ProsettaException { public function __construct(public readonly string $period) }`
  - Event `BudgetReached(string $period, int $used, int $limit)`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Resilience/BudgetTest.php`:
```php
<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Events\BudgetReached;
use LonelyLights\Prosetta\Resilience\Budget;

beforeEach(function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    Event::fake([BudgetReached::class]);
});

it('never stops a run when no budget is set', function () {
    app(Budget::class)->record('run-1', 10_000_000);

    expect(app(Budget::class)->exhausted('run-1'))->toBeNull();
});

it('stops the next call once a run has used its budget, and says so once', function () {
    config(['prosetta.budgets.per_run' => 1000]);
    $budget = app(Budget::class);

    $budget->record('run-1', 600);
    expect($budget->exhausted('run-1'))->toBeNull();

    $budget->record('run-1', 600);
    $budget->record('run-1', 10);

    expect($budget->exhausted('run-1'))->toBe('per_run')
        ->and($budget->exhausted('run-2'))->toBeNull();
    Event::assertDispatchedTimes(BudgetReached::class, 1);
    Event::assertDispatched(BudgetReached::class, fn (BudgetReached $event) => $event->period === 'per_run' && $event->used === 1200 && $event->limit === 1000);
});

it('counts daily spending across runs and starts again the next day', function () {
    config(['prosetta.budgets.daily' => 1000]);
    $budget = app(Budget::class);
    $budget->record('run-1', 700);
    $budget->record(null, 400);

    expect($budget->exhausted('run-3'))->toBe('daily');

    $this->travelTo(now()->addDay()->startOfDay()->addMinute());

    expect($budget->exhausted('run-3'))->toBeNull();
});

it('counts monthly spending until the 1st', function () {
    $this->travelTo(now()->startOfMonth()->addDays(3));
    config(['prosetta.budgets.monthly' => 500]);
    app(Budget::class)->record(null, 500);

    expect(app(Budget::class)->exhausted(null))->toBe('monthly');

    $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());

    expect(app(Budget::class)->exhausted(null))->toBeNull();
});

it('reports usage against each limit', function () {
    config(['prosetta.budgets.daily' => 1000, 'prosetta.budgets.per_run' => 50]);
    app(Budget::class)->record('run-1', 40);

    expect(app(Budget::class)->usage('run-1'))->toBe([
        'per_run' => ['used' => 40, 'limit' => 50],
        'daily' => ['used' => 40, 'limit' => 1000],
        'monthly' => ['used' => 40, 'limit' => null],
    ]);
});
```

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/pest tests/Feature/Resilience/BudgetTest.php`
Expected: FAIL. `Class "LonelyLights\Prosetta\Resilience\Budget" not found`.

- [ ] **Step 3: Implement**

`src/Events/BudgetReached.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A token budget (per_run, daily or monthly) was used up. Raised once per run, day or month. */
final readonly class BudgetReached {
    use Dispatchable;

    public function __construct(public string $period, public int $used, public int $limit) {}
}
```

`src/Resilience/BudgetExhausted.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use LonelyLights\Prosetta\Exceptions\ProsettaException;

/** Thrown before a call when a budget is already used up; $period is per_run, daily or monthly. */
final class BudgetExhausted extends ProsettaException {
    public function __construct(public readonly string $period) {
        parent::__construct("The $period token budget is used up.");
    }
}
```

`src/Resilience/Budget.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Contracts\Events\Dispatcher;
use LonelyLights\Prosetta\Events\BudgetReached;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Token budgets per run, day and month, counted in the cache after each
 * call and checked before the next. A batch can overshoot by at most its own
 * tokens; that is the price of not estimating every call.
 */
final readonly class Budget {
    public function __construct(private Dispatcher $events) {}

    public function exhausted(?string $runId): ?string {
        foreach ($this->periods($runId) as $period => [$key]) {
            $limit = $this->limit($period);

            if ($limit !== null && $this->used($key) >= $limit) {
                return $period;
            }
        }

        return null;
    }

    public function record(?string $runId, int $tokens): void {
        if ($tokens <= 0) {
            return;
        }

        $cache = Settings::cache();

        foreach ($this->periods($runId) as $period => [$key, $ttl]) {
            $cache->add($key, 0, $ttl);
            $used = (int) $cache->increment($key, $tokens);
            $limit = $this->limit($period);

            if ($limit !== null && $used >= $limit && $cache->add("$key:reached", true, $ttl)) {
                $this->events->dispatch(new BudgetReached($period, $used, $limit));
            }
        }
    }

    /** @return array<string, array{used: int, limit: ?int}> */
    public function usage(?string $runId = null): array {
        $usage = [];

        foreach ($this->periods($runId) as $period => [$key]) {
            $usage[$period] = ['used' => $this->used($key), 'limit' => $this->limit($period)];
        }

        return $usage;
    }

    /** @return array<string, array{0: string, 1: int}> period => [cache key, seconds to keep it] */
    private function periods(?string $runId): array {
        $now = now();
        $periods = [];

        if ($runId !== null) {
            $periods['per_run'] = ["prosetta:budget:run:$runId", 7 * 86400];
        }

        $periods['daily'] = ['prosetta:budget:day:'.$now->format('Y-m-d'), 2 * 86400];
        $periods['monthly'] = ['prosetta:budget:month:'.$now->format('Y-m'), 40 * 86400];

        return $periods;
    }

    private function used(string $key): int {
        return (int) Settings::cache()->get($key, 0);
    }

    private function limit(string $period): ?int {
        $limit = config("prosetta.budgets.$period");

        return $limit === null ? null : max(0, (int) $limit);
    }
}
```

- [ ] **Step 4: Run to see it pass, then the whole suite**

Run: `vendor/bin/pest tests/Feature/Resilience/BudgetTest.php` → PASS. Then `vendor/bin/pest` → green.

- [ ] **Step 5: Commit**

```bash
git add src/Resilience/Budget.php src/Resilience/BudgetExhausted.php src/Events/BudgetReached.php tests/Feature/Resilience/BudgetTest.php
git commit -m "Add per-run, daily and monthly token budgets"
```

---

### Task 4: Run scopes and suspensions

**Files:**
- Create: `src/Resilience/RunScope.php`, `src/Resilience/Suspensions.php`, `src/Events/TranslationSuspended.php`
- Test: `tests/Feature/Resilience/SuspensionsTest.php`

**Interfaces:**
- Produces:
  - `final readonly class RunScope { __construct(public array $locales, public array $namespaces, public array $keys, public bool $force = false); id(): string; toArray(): array; static fromArray(array): self }`
  - `Suspensions::suspend(string $circuit, RunScope $scope, string $reason): void`
  - `Suspensions::all(): array<string, array{circuit: string, scope: RunScope, reason: string, at: int}>`, keyed by `"$circuit|{$scope->id()}"`
  - `Suspensions::clear(string $id): void`
  - Event `TranslationSuspended(string $circuit, string $reason, RunScope $scope)`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Resilience/SuspensionsTest.php`:
```php
<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Events\TranslationSuspended;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;

beforeEach(function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    Event::fake([TranslationSuspended::class]);
});

it('identifies a scope by its contents, whatever the order', function () {
    expect((new RunScope(['es', 'ar'], ['identity'], []))->id())->toBe((new RunScope(['ar', 'es'], ['identity'], []))->id())
        ->and((new RunScope(['es'], [], []))->id())->not->toBe((new RunScope(['es'], [], [], force: true))->id())
        ->and(RunScope::fromArray((new RunScope(['es'], ['bridge'], ['auth.failed'], true))->toArray()))->toEqual(new RunScope(['es'], ['bridge'], ['auth.failed'], true));
});

it('merges repeated suspensions of the same scope and raises one event', function () {
    $suspensions = app(Suspensions::class);
    $scope = new RunScope(['es'], ['identity'], []);

    $suspensions->suspend('fake:m', $scope, 'outage');
    $suspensions->suspend('fake:m', new RunScope(['es'], ['identity'], []), 'outage');
    $suspensions->suspend('fake:m', new RunScope(['ar'], [], []), 'rejected');

    expect($suspensions->all())->toHaveCount(2)
        ->and($suspensions->all()["fake:m|{$scope->id()}"]['scope'])->toEqual($scope);
    Event::assertDispatchedTimes(TranslationSuspended::class, 2);
});

it('clears a suspension by id', function () {
    $suspensions = app(Suspensions::class);
    $suspensions->suspend('fake:m', new RunScope(['es'], [], []), 'outage');

    $suspensions->clear(array_key_first($suspensions->all()));

    expect($suspensions->all())->toBe([]);
});
```

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/pest tests/Feature/Resilience/SuspensionsTest.php` → FAIL. Class not found.

- [ ] **Step 3: Implement**

`src/Resilience/RunScope.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

/** What one translate() call covered, so a suspended run can be queued again exactly. */
final readonly class RunScope {
    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys key references
     */
    public function __construct(
        public array $locales,
        public array $namespaces,
        public array $keys,
        public bool $force = false,
    ) {}

    public function id(): string {
        $sorted = fn (array $values) => (function () use ($values) {
            sort($values);

            return $values;
        })();

        return sha1((string) json_encode([$sorted($this->locales), $sorted($this->namespaces), $sorted($this->keys), $this->force]));
    }

    /** @return array{locales: list<string>, namespaces: list<string>, keys: list<string>, force: bool} */
    public function toArray(): array {
        return ['locales' => $this->locales, 'namespaces' => $this->namespaces, 'keys' => $this->keys, 'force' => $this->force];
    }

    /** @param array{locales?: list<string>, namespaces?: list<string>, keys?: list<string>, force?: bool} $data */
    public static function fromArray(array $data): self {
        return new self(
            array_values($data['locales'] ?? []),
            array_values($data['namespaces'] ?? []),
            array_values($data['keys'] ?? []),
            (bool) ($data['force'] ?? false),
        );
    }
}
```

`src/Events/TranslationSuspended.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LonelyLights\Prosetta\Resilience\RunScope;

/** A run stopped (outage, halt or budget) and its scope was kept for prosetta:resume. */
final readonly class TranslationSuspended {
    use Dispatchable;

    public function __construct(public string $circuit, public string $reason, public RunScope $scope) {}
}
```

`src/Resilience/Suspensions.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Contracts\Events\Dispatcher;
use LonelyLights\Prosetta\Events\TranslationSuspended;
use LonelyLights\Prosetta\Support\Settings;

/** Runs stopped by an outage, a halt or a budget, kept in the cache until prosetta:resume queues them again. */
final readonly class Suspensions {
    private const string KEY = 'prosetta:suspended';

    public function __construct(private Dispatcher $events) {}

    public function suspend(string $circuit, RunScope $scope, string $reason): void {
        $stored = $this->stored();
        $id = $circuit.'|'.$scope->id();
        $new = ! isset($stored[$id]);
        $stored[$id] = ['circuit' => $circuit, 'scope' => $scope->toArray(), 'reason' => $reason, 'at' => now()->getTimestamp()];
        Settings::cache()->forever(self::KEY, $stored);

        if ($new) {
            $this->events->dispatch(new TranslationSuspended($circuit, $reason, $scope));
        }
    }

    /** @return array<string, array{circuit: string, scope: RunScope, reason: string, at: int}> */
    public function all(): array {
        return array_map(fn (array $row) => [...$row, 'scope' => RunScope::fromArray($row['scope'])], $this->stored());
    }

    public function clear(string $id): void {
        $stored = $this->stored();
        unset($stored[$id]);
        Settings::cache()->forever(self::KEY, $stored);
    }

    /** @return array<string, array{circuit: string, scope: array<string, mixed>, reason: string, at: int}> */
    private function stored(): array {
        return (array) Settings::cache()->get(self::KEY, []);
    }
}
```

- [ ] **Step 4: Run to see it pass, then the whole suite** (`vendor/bin/pest`)

- [ ] **Step 5: Commit**

```bash
git add src/Resilience/RunScope.php src/Resilience/Suspensions.php src/Events/TranslationSuspended.php tests/Feature/Resilience/SuspensionsTest.php
git commit -m "Add run scopes and the suspended-work list"
```

---

### Task 5: The provider gate and scripted test drivers

**Files:**
- Create: `src/Resilience/CallDeferred.php`, `src/Resilience/ProviderGate.php`, `src/Events/TranslationHalted.php`
- Create: `src/Testing/ScriptedDriver.php`, `src/Testing/HealthCheckedScriptedDriver.php`
- Test: `tests/Feature/Resilience/ProviderGateTest.php`

**Interfaces:**
- Consumes: `Circuits`, `Circuit`, `Decision` (Task 2); `Budget`, `BudgetExhausted` (Task 3); `Backoff` (Task 1); the provider exceptions and `ChecksHealth` (Task 1).
- Produces:
  - `final class CallDeferred extends ProsettaException { __construct(public readonly string $circuit, public readonly int $seconds, public readonly string $reason, public readonly bool $outage = false) }`, where `$reason` is `'open'` or `'held'`
  - `ProviderGate::call(TranslationDriver $driver, string $circuit, ?string $runId, Closure $call): TranslationBatchResult`. It throws `ProviderException` (with `->circuit` set), `CallDeferred` or `BudgetExhausted`.
  - `ProviderGate::test(TranslationDriver&ChecksHealth $driver, Circuit $circuit, Decision $decision): bool`
  - `ProviderGate::classify(Throwable $e): ProviderException`
  - Event `TranslationHalted(string $circuit, string $reason, string $message)`, where reason is `rejected`, `quota` or `unknown`
  - `ScriptedDriver` (non-final, implements `TranslationDriver`) with:
    - `public array $calls`
    - `fail(Throwable ...$errors): static`, which queues one error per upcoming `translate()` call
    - `refuse(string $source, string $reason = 'unsafe'): static`
  - `HealthCheckedScriptedDriver extends ScriptedDriver implements ChecksHealth`, with `public int $healthChecks`, `failHealth(Throwable ...$errors): static` and `checkHealth(): void`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Resilience/ProviderGateTest.php`:
```php
<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationItem;
use LonelyLights\Prosetta\Events\TranslationHalted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderQuotaExhausted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use LonelyLights\Prosetta\Resilience\BudgetExhausted;
use LonelyLights\Prosetta\Resilience\CallDeferred;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\ProviderGate;
use LonelyLights\Prosetta\Testing\HealthCheckedScriptedDriver;
use LonelyLights\Prosetta\Testing\ScriptedDriver;

beforeEach(function () {
    config([
        'prosetta.resilience.cache_store' => 'array',
        'prosetta.resilience.jitter' => 0,
        'prosetta.resilience.circuit' => ['failure_threshold' => 2, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 3600],
    ]);
    Event::fake([TranslationHalted::class]);
});

function gateBatch(): TranslationBatch {
    return new TranslationBatch('en', new LocaleDescriptor('es', 'Spanish', 'Español'), null, null, [new TranslationItem('1', 'auth.failed', 'Hello')]);
}

function callThrough(ScriptedDriver $driver, ?string $runId = null) {
    return app(ProviderGate::class)->call($driver, 'scripted:m', $runId, fn () => $driver->translate(gateBatch()));
}

it('passes a successful call through and counts its tokens against the budget', function () {
    config(['prosetta.budgets.per_run' => 5]);
    $result = callThrough(new ScriptedDriver, 'run-1');

    expect($result->values)->toBe(['1' => 'Hello [es]'])
        ->and(fn () => callThrough(new ScriptedDriver, 'run-1'))->toThrow(BudgetExhausted::class);
});

it('names the circuit on the exception and opens the circuit after repeated outages', function () {
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));

    try {
        callThrough($driver);
    } catch (ProviderUnavailable $e) {
        expect($e->circuit)->toBe('scripted:m');
    }

    expect(fn () => callThrough($driver))->toThrow(ProviderUnavailable::class)
        ->and(fn () => callThrough($driver))->toThrow(CallDeferred::class)
        ->and($driver->calls)->toHaveCount(2);
});

it('treats an unknown error as an outage by default, and as a halt when configured', function () {
    $driver = (new ScriptedDriver)->fail(new RuntimeException('weird'));
    expect(fn () => callThrough($driver))->toThrow(ProviderUnavailable::class, 'weird');

    config(['prosetta.resilience.unknown_errors' => 'halt']);
    $driver = (new ScriptedDriver)->fail(new RuntimeException('weird'));
    expect(fn () => callThrough($driver))->toThrow(ProviderRejected::class, 'weird');
    Event::assertDispatched(TranslationHalted::class, fn (TranslationHalted $event) => $event->reason === 'unknown');
});

it('halts once on a rejected key or exhausted quota and holds every later call', function (Throwable $error, string $reason) {
    $driver = (new ScriptedDriver)->fail($error);

    expect(fn () => callThrough($driver))->toThrow($error::class);

    try {
        callThrough($driver);
    } catch (CallDeferred $deferred) {
        expect($deferred->reason)->toBe('held');
    }

    expect($driver->calls)->toHaveCount(1);
    Event::assertDispatchedTimes(TranslationHalted::class, 1);
    Event::assertDispatched(TranslationHalted::class, fn (TranslationHalted $event) => $event->reason === $reason);
})->with([
    'rejected' => [new ProviderRejected('bad key'), 'rejected'],
    'quota' => [new ProviderQuotaExhausted('no credits'), 'quota'],
]);

it('tests an open circuit with the health check before spending a real call', function () {
    $driver = (new HealthCheckedScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    rescue(fn () => callThrough($driver), report: false);
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(301)->seconds();

    $driver->failHealth(new ProviderUnavailable('still down'));
    expect(fn () => callThrough($driver))->toThrow(CallDeferred::class)
        ->and($driver->healthChecks)->toBe(1)
        ->and($driver->calls)->toHaveCount(2);

    $this->travel(601)->seconds();
    $result = callThrough($driver);

    expect($result->values)->toBe(['1' => 'Hello [es]'])
        ->and($driver->healthChecks)->toBe(2)
        ->and(app(Circuits::class)->for('scripted:m')->decision()->kind)->toBe('call');
});

it('uses the real call as the test when the driver has no health check', function () {
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    rescue(fn () => callThrough($driver), report: false);
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(301)->seconds();

    expect(callThrough($driver)->values)->toBe(['1' => 'Hello [es]'])
        ->and(app(Circuits::class)->for('scripted:m')->decision()->kind)->toBe('call');
});

it('flags a deferral once the outage has lasted longer than outage_timeout', function () {
    config(['prosetta.resilience.outage_timeout' => 600]);
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    rescue(fn () => callThrough($driver), report: false);
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(601)->seconds();
    app(Circuits::class)->for('scripted:m')->recordFailure('still down', app(Circuits::class)->for('scripted:m')->decision());

    try {
        callThrough($driver);
    } catch (CallDeferred $deferred) {
        expect($deferred->outage)->toBeTrue();
    }
});
```

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/pest tests/Feature/Resilience/ProviderGateTest.php` → FAIL. Classes not found.

- [ ] **Step 3: Implement**

`src/Resilience/CallDeferred.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use LonelyLights\Prosetta\Exceptions\ProsettaException;

/**
 * The gate made no call: the circuit is open (retry in $seconds) or held
 * after a halt. $outage is true once the circuit has been open longer than
 * outage_timeout, which tells the job to suspend instead of waiting.
 */
final class CallDeferred extends ProsettaException {
    public function __construct(public readonly string $circuit, public readonly int $seconds, public readonly string $reason, public readonly bool $outage = false) {
        parent::__construct($reason === 'held'
            ? "Translation is halted for [$circuit] until the hold ends or prosetta:circuit reset."
            : "The provider behind [$circuit] is failing; the next try is in $seconds s.");
    }
}
```

`src/Events/TranslationHalted.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A provider problem retrying won't fix; reason is rejected, quota or unknown. Raised once per trip. */
final readonly class TranslationHalted {
    use Dispatchable;

    public function __construct(public string $circuit, public string $reason, public string $message) {}
}
```

`src/Resilience/ProviderGate.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use LonelyLights\Prosetta\Contracts\ChecksHealth;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Events\TranslationHalted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderException;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderQuotaExhausted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use Throwable;

/**
 * Every driver call goes through here: the budget first, then the circuit
 * (waiting, held, or this caller's turn to test, using the driver's health
 * check when it has one), then the call. Failures come out classified, with
 * the circuit opened or tripped; successes close the circuit and count tokens.
 */
final readonly class ProviderGate {
    public function __construct(private Circuits $circuits, private Budget $budget, private Dispatcher $events) {}

    /**
     * @param Closure(): TranslationBatchResult $call
     * @throws ProviderException|CallDeferred|BudgetExhausted
     */
    public function call(TranslationDriver $driver, string $circuit, ?string $runId, Closure $call): TranslationBatchResult {
        if (($period = $this->budget->exhausted($runId)) !== null) {
            throw new BudgetExhausted($period);
        }

        $breaker = $this->circuits->for($circuit);
        $decision = $breaker->decision();
        $this->defer($breaker, $decision);

        if ($decision->kind === 'test' && $driver instanceof ChecksHealth) {
            if (! $this->test($driver, $breaker, $decision)) {
                $this->defer($breaker, $breaker->decision());
            }

            $decision = Decision::call();
        }

        try {
            $result = $call();
        } catch (Throwable $e) {
            throw $this->fail($breaker, $e, $decision);
        }

        $breaker->recordSuccess($decision);
        $this->budget->record($runId, $result->inputTokens + $result->outputTokens);

        return $result;
    }

    /** Runs the health check for a circuit's test turn; true when it passed and the circuit closed. */
    public function test(TranslationDriver&ChecksHealth $driver, Circuit $breaker, Decision $decision): bool {
        try {
            $driver->checkHealth();
        } catch (Throwable $e) {
            $this->fail($breaker, $e, $decision);

            return false;
        }

        $breaker->recordSuccess($decision);

        return true;
    }

    public function classify(Throwable $e): ProviderException {
        if ($e instanceof ProviderException) {
            return $e;
        }

        return config('prosetta.resilience.unknown_errors', 'transient') === 'halt'
            ? new ProviderRejected($e->getMessage(), 0, $e)
            : new ProviderUnavailable($e->getMessage(), 0, $e);
    }

    /** Records the failure on the circuit and returns the classified exception, tagged with the circuit. */
    private function fail(Circuit $breaker, Throwable $e, Decision $decision): ProviderException {
        $classified = $this->classify($e);
        $classified->circuit = $breaker->name;

        if ($classified instanceof ProviderRejected || $classified instanceof ProviderQuotaExhausted) {
            $reason = match (true) {
                $classified instanceof ProviderQuotaExhausted => 'quota',
                $classified !== $e => 'unknown',
                default => 'rejected',
            };

            if ($breaker->trip($reason, $classified->getMessage(), $decision)) {
                $this->events->dispatch(new TranslationHalted($breaker->name, $reason, $classified->getMessage()));
            }
        } else {
            $breaker->recordFailure($classified->getMessage(), $decision);
        }

        return $classified;
    }

    /** @throws CallDeferred when the decision is to wait or stay held */
    private function defer(Circuit $breaker, Decision $decision): void {
        if ($decision->kind === 'held') {
            throw new CallDeferred($breaker->name, 0, 'held');
        }

        if ($decision->kind === 'wait') {
            throw new CallDeferred($breaker->name, Backoff::jitter($decision->seconds), 'open', $breaker->outageExceeded());
        }
    }
}
```

`src/Testing/ScriptedDriver.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Testing;

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use Throwable;

/**
 * A test driver that fails on cue: each fail() argument is thrown by one
 * upcoming translate() call, in order; after that it echoes like
 * FakeTranslationDriver. refuse() makes it refuse items with a given source.
 */
class ScriptedDriver implements TranslationDriver {
    /** @var list<TranslationBatch> */
    public array $calls = [];

    /** @var list<Throwable> */
    private array $failures = [];

    /** @var array<string, string> source => reason */
    private array $refusals = [];

    private FakeTranslationDriver $echo;

    public function __construct() {
        $this->echo = new FakeTranslationDriver;
    }

    public function fail(Throwable ...$errors): static {
        $this->failures = [...$this->failures, ...array_values($errors)];

        return $this;
    }

    public function refuse(string $source, string $reason = 'unsafe'): static {
        $this->refusals[$source] = $reason;

        return $this;
    }

    public function translate(TranslationBatch $batch): TranslationBatchResult {
        $this->calls[] = $batch;

        if (($error = array_shift($this->failures)) !== null) {
            throw $error;
        }

        $result = $this->echo->translate($batch);
        $refused = [];

        foreach ($batch->items as $item) {
            if (isset($this->refusals[$item->source])) {
                $refused[$item->id] = $this->refusals[$item->source];
            }
        }

        return new TranslationBatchResult(array_diff_key($result->values, $refused), $result->provider, $result->model, $result->inputTokens, $result->outputTokens, $result->invocationId, $refused);
    }
}
```

`src/Testing/HealthCheckedScriptedDriver.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Testing;

use LonelyLights\Prosetta\Contracts\ChecksHealth;
use Throwable;

/** A ScriptedDriver with a health check; failHealth() queues errors for upcoming checks. */
final class HealthCheckedScriptedDriver extends ScriptedDriver implements ChecksHealth {
    public int $healthChecks = 0;

    /** @var list<Throwable> */
    private array $healthFailures = [];

    public function failHealth(Throwable ...$errors): static {
        $this->healthFailures = [...$this->healthFailures, ...array_values($errors)];

        return $this;
    }

    public function checkHealth(): void {
        $this->healthChecks++;

        if (($error = array_shift($this->healthFailures)) !== null) {
            throw $error;
        }
    }
}
```

- [ ] **Step 4: Run to see it pass, then the whole suite** (`vendor/bin/pest`)

- [ ] **Step 5: Commit**

```bash
git add src/Resilience/CallDeferred.php src/Resilience/ProviderGate.php src/Events/TranslationHalted.php src/Testing/ScriptedDriver.php src/Testing/HealthCheckedScriptedDriver.php tests/Feature/Resilience/ProviderGateTest.php
git commit -m "Route driver calls through a gate that classifies errors, opens or trips circuits and counts tokens"
```

---

### Task 6: The runner calls through the gate

**Files:**
- Modify: `src/Translation/TranslationRunner.php`
- Modify: `src/Translation/TranslateReport.php`
- Test: `tests/Feature/Translation/TranslationRunnerTest.php` (append)

**Interfaces:**
- Consumes: `ProviderGate::call()` and `Circuits::nameFor()` (Task 5 and Task 2); the `refused` results (Task 1).
- Produces:
  - `TranslationRunner::run(string $locale, array $keyIds, bool $force = false, ?string $runId = null): TranslateReport`. It throws `ProviderException`, `CallDeferred` or `BudgetExhausted` after saving whatever it already has.
  - `TranslateReport` gains `public array $refused = []` (list of `"{locale} {ref}"`) and `public ?string $stopped = null`; `merge()` and `toArray()` include both.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Translation/TranslationRunnerTest.php`:
```php
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use LonelyLights\Prosetta\Testing\ScriptedDriver;

it('records strings the provider refused as failed, without retrying them', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    $driver = (new ScriptedDriver)->refuse('These credentials do not match our records.');
    app()->instance(TranslationDriver::class, $driver);

    $report = app(TranslationRunner::class)->run('es', keyIds('auth.failed', 'auth.throttle'));

    expect($report->refused)->toBe(['es auth.failed'])
        ->and($report->failed)->toBe(['es auth.failed'])
        ->and($report->drafted)->toBe(['es auth.throttle'])
        ->and($driver->calls)->toHaveCount(1);
});

it('keeps the first attempt\'s drafts when the retry call fails', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    $driver = new class extends ScriptedDriver {
        public function translate(\LonelyLights\Prosetta\Data\TranslationBatch $batch): \LonelyLights\Prosetta\Data\TranslationBatchResult {
            if ($batch->feedback !== []) {
                $this->calls[] = $batch;

                throw new ProviderUnavailable('down during the retry');
            }

            $result = parent::translate($batch);

            return new \LonelyLights\Prosetta\Data\TranslationBatchResult(
                array_map(fn (string $value) => str_replace(':name', '', $value), $result->values),
                $result->provider, $result->model, $result->inputTokens, $result->outputTokens,
            );
        }
    };
    app()->instance(TranslationDriver::class, $driver);
    $ids = keyIds('messages.welcome');

    expect(fn () => app(TranslationRunner::class)->run('es', $ids))->toThrow(ProviderUnavailable::class);
    expect(Translation::query()->where('key_id', $ids[0])->where('locale', 'es')->value('status'))->toBe(TranslationStatus::Draft);
});

it('names the run it belongs to when counting tokens', function () {
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.budgets.per_run' => 1]);
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    app(TranslationRunner::class)->run('es', keyIds('auth.failed'), runId: 'run-9');

    expect(fn () => app(TranslationRunner::class)->run('es', keyIds('auth.throttle'), runId: 'run-9'))
        ->toThrow(\LonelyLights\Prosetta\Resilience\BudgetExhausted::class);
});
```
The fixture's keys: `auth.failed` (no placeholder), `auth.throttle` (`:seconds`), `messages.welcome` (`:name`), `messages.apples` (plural), `messages.terms` (`:url` in HTML). Dropping `:name` from `messages.welcome` makes the first attempt fail the guard, so the runner makes a retry call, which throws.

- [ ] **Step 2: Run to see them fail**

Run: `vendor/bin/pest tests/Feature/Translation/TranslationRunnerTest.php`
Expected: FAIL. `Undefined property ...TranslateReport::$refused`, and `run()` has no `runId` parameter.

- [ ] **Step 3: Implement**

`src/Translation/TranslateReport.php`: add the properties, and include them in `merge()` and `toArray()`:
```php
    /** @var list<string> "{locale} {ref}" the provider refused to translate */
    public array $refused = [];

    /** Why the run stopped early (outage, halt or budget), or null when it ran to the end. */
    public ?string $stopped = null;
```
In `merge()` add:
```php
        $this->refused = [...$this->refused, ...$other->refused];
        $this->stopped ??= $other->stopped;
```
In `toArray()` add `'refused' => $this->refused, 'stopped' => $this->stopped`.

`src/Translation/TranslationRunner.php`:
1. Imports: `use LonelyLights\Prosetta\Resilience\Circuits;`, `use LonelyLights\Prosetta\Resilience\ProviderGate;`.
2. Constructor: add `private ProviderGate $gate,` after `$events`.
3. Signature: `public function run(string $locale, array $keyIds, bool $force = false, ?string $runId = null): TranslateReport {`, and `@throws` becomes `@throws Throwable when a database transaction fails, or a ProviderException, CallDeferred or BudgetExhausted when the provider can't be called`.
4. Replace everything from `$outcomes = $this->attempt($driver, $batch, $locale);` down to the closing `foreach` that persists, with:
```php
        $outcomes = $this->attempt($driver, $batch, $locale, $runId);
        $stopped = null;

        try {
            for ($retries = (int) config('prosetta.ai.retries_on_issues', 1); $retries > 0; $retries--) {
                $failing = array_filter($outcomes, fn (array $outcome) => $this->blocking($outcome['issues']) && ! $this->refused($outcome['issues']));

                if ($failing === []) {
                    break;
                }

                $feedback = array_map(fn (array $outcome) => array_map(fn (Issue $issue) => $issue->message, $outcome['issues']), $failing);
                $retryItems = array_values(array_filter($items, fn (TranslationItem $item) => array_key_exists($item->id, $failing)));
                $retried = $this->attempt($driver, $batch->withItems($retryItems)->withFeedback($feedback), $locale, $runId);

                foreach ($retried as $id => $outcome) {
                    $outcome['input'] += $outcomes[$id]['input'];
                    $outcome['output'] += $outcomes[$id]['output'];
                    $outcomes[$id] = $outcome;
                }
            }
        } catch (Throwable $e) {
            # The First Attempt Is Already Paid For: Keep It, Then Let the Caller Deal With the Failure
            $stopped = $e;
        }

        foreach ($outcomes as $id => $outcome) {
            $this->persist($keys->get((int) $id), $existing->get((int) $id), $locale, $outcome, $report);
        }

        if ($stopped !== null) {
            throw $stopped;
        }

        return $report;
```
5. `attempt()`: new signature `private function attempt(TranslationDriver $driver, TranslationBatch $batch, string $locale, ?string $runId): array`. Replace `$result = $driver->translate($batch);` with:
```php
        $result = $this->gate->call($driver, Circuits::nameFor($driver, $batch->model), $runId, fn () => $driver->translate($batch));
```
In the outcomes loop, replace the `'value'` and `'issues'` lines with:
```php
            $refusal = $result->refused[$item->id] ?? null;

            $outcomes[$item->id] = [
                'value' => $refusal === null && is_string($value) ? $value : null,
                'issues' => match (true) {
                    $refusal !== null => [Issue::error('refused', "The provider refused to translate this: $refusal")],
                    is_string($value) => $this->guard->check($item->source, $value, $locale),
                    default => [Issue::error('missing_value', 'The driver returned no value for this key.')],
                },
```
(keep the remaining keys: `provider`, `model`, `invocation`, `input`, `output`).
6. `persist()`: in the `if ($outcome['value'] === null)` branch, before `return;`, add:
```php
            if ($this->refused($outcome['issues'])) {
                $report->refused[] = $ref;
            }
```
7. Add the helper after `blocking()`:
```php
    /** @param list<Issue> $issues */
    private function refused(array $issues): bool {
        foreach ($issues as $issue) {
            if ($issue->code === 'refused') {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 4: Run to see them pass, then the whole suite**

Run: `vendor/bin/pest tests/Feature/Translation` → PASS. Then `vendor/bin/pest` → green. Existing runner tests use `FakeTranslationDriver` through the gate; its circuit stays closed.

- [ ] **Step 5: Commit**

```bash
git add src/Translation/TranslationRunner.php src/Translation/TranslateReport.php tests/Feature/Translation/TranslationRunnerTest.php
git commit -m "Call drivers through the gate, keep paid-for drafts when a retry fails, and record refusals"
```

---

### Task 7: Jobs, queued and synchronous runs

**Files:**
- Modify: `src/Jobs/TranslateBatch.php`
- Modify: `src/Translation/Translator.php`
- Modify: `src/Console/TranslateCommand.php`
- Test: `tests/Feature/Translation/TranslateBatchResilienceTest.php` (new), `tests/Feature/Translation/TranslatorTest.php` (edit one test)

**Interfaces:**
- Consumes: everything from Tasks 1–6.
- Produces:
  - `TranslateBatch::__construct(string $locale, int $fileId, array $keyIds, bool $force = false, ?array $scope = null)`, where `$scope` is `RunScope::toArray()` (plain arrays serialize safely)
  - `TranslateBatch::handle(TranslationRunner $runner, Suspensions $suspensions, Circuits $circuits): void`
  - `Translator::translate(...)`: unchanged signature. Queued jobs carry the scope; a synchronous run stops at the first provider problem, sets `$report->stopped` and suspends when appropriate.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Translation/TranslateBatchResilienceTest.php`:
```php
<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Events\TranslationSuspended;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRateLimited;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use LonelyLights\Prosetta\Jobs\TranslateBatch;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;
use LonelyLights\Prosetta\Translation\TranslationRunner;
use LonelyLights\Prosetta\Translation\Translator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    config([
        'prosetta.resilience.cache_store' => 'array',
        'prosetta.resilience.jitter' => 0,
        'prosetta.resilience.backoff' => [30, 60],
        'prosetta.resilience.circuit' => ['failure_threshold' => 2, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 3600],
    ]);
    Event::fake([TranslationSuspended::class]);
});

function resilientJob(ScriptedDriver $driver): TranslateBatch {
    app()->instance(TranslationDriver::class, $driver);
    $id = app(KeyFinder::class)->find('auth.failed')->id;

    return (new TranslateBatch('es', 1, [$id], false, (new RunScope(['es'], ['*'], []))->toArray()))->withFakeQueueInteractions();
}

function handle(TranslateBatch $job): void {
    $job->handle(app(TranslationRunner::class), app(Suspensions::class), app(Circuits::class));
}

it('backs off an unavailable provider by attempt', function () {
    $job = resilientJob((new ScriptedDriver)->fail(new ProviderUnavailable('down')));
    handle($job);

    $job->assertReleased(30);
});

it('waits as long as a rate limit asks', function () {
    $job = resilientJob((new ScriptedDriver)->fail(new ProviderRateLimited(retryAfter: 42)));
    handle($job);

    $job->assertReleased(42);
});

it('waits out an open circuit without calling the provider', function () {
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    handle(resilientJob($driver));
    handle(resilientJob($driver));

    $job = resilientJob($driver);
    handle($job);

    $job->assertReleased(300);
    expect($driver->calls)->toHaveCount(2);
});

it('suspends quietly on a halt instead of failing', function () {
    $job = resilientJob((new ScriptedDriver)->fail(new ProviderRejected('bad key')));
    handle($job);

    $job->assertDeleted();
    $job->assertNotFailed();
    expect(app(Suspensions::class)->all())->toHaveCount(1);
    Event::assertDispatched(TranslationSuspended::class, fn (TranslationSuspended $event) => $event->reason === 'rejected');
});

it('suspends once the outage outlasts outage_timeout', function () {
    config(['prosetta.resilience.outage_timeout' => 600]);
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    handle(resilientJob($driver));
    handle(resilientJob($driver));
    $this->travel(700)->seconds();

    $job = resilientJob($driver);
    handle($job);

    $job->assertDeleted();
    expect(app(Suspensions::class)->all())->toHaveCount(1);
});

it('keeps retryUntil beyond the outage timeout', function () {
    config(['prosetta.resilience.outage_timeout' => 21600, 'prosetta.resilience.circuit.max_cooldown' => 3600]);

    expect((new TranslateBatch('es', 1, [1]))->retryUntil()->getTimestamp())
        ->toBeGreaterThanOrEqual(now()->addSeconds(21600 + 3600)->getTimestamp());
});

it('stops a synchronous run at the first provider problem and suspends it', function () {
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->fail(new ProviderRejected('bad key')));

    $report = app(Translator::class)->translate(['es'], ['*'], queue: false);

    expect($report->stopped)->toContain('bad key')
        ->and(app(Suspensions::class)->all())->toHaveCount(1);
});

it('stops a synchronous run at its own budget without suspending it', function () {
    config(['prosetta.budgets.per_run' => 1]);
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $report = app(Translator::class)->translate(['es'], ['*'], queue: false);

    expect($report->stopped)->toContain('per_run')
        ->and(app(Suspensions::class)->all())->toBe([]);
});
```

In `tests/Feature/Translation/TranslatorTest.php`, update the `retryUntil` expectation in "survives being released…" to `now()->addHours(6)` (the new minimum), keeping the rest.

- [ ] **Step 2: Run to see them fail**

Run: `vendor/bin/pest tests/Feature/Translation/TranslateBatchResilienceTest.php`
Expected: FAIL. `TranslateBatch` has no 5th constructor argument, and `handle()` has the wrong signature.

- [ ] **Step 3: Implement**

`src/Jobs/TranslateBatch.php`: replace the class body (keep its existing imports and add the new ones):
```php
use LonelyLights\Prosetta\Exceptions\Provider\ProviderException;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderQuotaExhausted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRateLimited;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Resilience\Backoff;
use LonelyLights\Prosetta\Resilience\BudgetExhausted;
use LonelyLights\Prosetta\Resilience\CallDeferred;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;
```
```php
/**
 * One chunk of one file for one locale. Idempotent: the runner re-checks
 * before calling the AI. Provider trouble never fails the job: it is released
 * with backoff, waits out an open circuit, or ends quietly with its run
 * suspended for prosetta:resume.
 */
final class TranslateBatch implements ShouldQueue {
    use Batchable, InteractsWithQueue, Queueable;

    /** Seconds a worker may spend running this job before it is treated as timed out. */
    public int $timeout = 300;

    /**
     * @param list<int> $keyIds
     * @param array<string, mixed>|null $scope the run's RunScope::toArray(), to suspend and resume it whole
     */
    public function __construct(
        public string $locale,
        public int $fileId,
        public array $keyIds,
        public bool $force = false,
        public ?array $scope = null,
    ) {}

    /**
     * Releases for rate limits, overlap and open circuits all consume
     * attempts, so retries are bounded by time: long enough that Prosetta,
     * not the queue, decides when an outage has lasted too long.
     */
    public function retryUntil(): DateTimeInterface {
        $outage = (int) config('prosetta.resilience.outage_timeout', 21600);
        $cooldown = (int) config('prosetta.resilience.circuit.max_cooldown', 3600);

        return now()->addSeconds(max(21600, $outage + $cooldown + 600));
    }

    /** @return list<object> */
    public function middleware(): array {
        return [
            new RateLimited('prosetta-ai'),
            (new WithoutOverlapping("prosetta:$this->locale:$this->fileId"))->releaseAfter(30)->expireAfter(600),
        ];
    }

    /** @throws Throwable when a database transaction fails */
    public function handle(TranslationRunner $runner, Suspensions $suspensions, Circuits $circuits): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $runner->run($this->locale, $this->keyIds, $this->force, $this->batchId);
        } catch (CallDeferred $deferred) {
            if ($deferred->reason === 'held' || $deferred->outage) {
                $this->stop($suspensions, $deferred->circuit, $deferred->reason === 'held' ? 'halted' : 'outage');

                return;
            }

            $this->release($deferred->seconds);
        } catch (ProviderRejected|ProviderQuotaExhausted $halt) {
            $this->stop($suspensions, (string) $halt->circuit, $halt instanceof ProviderQuotaExhausted ? 'quota' : 'rejected');
        } catch (ProviderException $transient) {
            if ($transient->circuit !== null && $circuits->for($transient->circuit)->outageExceeded()) {
                $this->stop($suspensions, $transient->circuit, 'outage');

                return;
            }

            $this->release($transient instanceof ProviderRateLimited && $transient->retryAfter !== null
                ? $transient->retryAfter
                : Backoff::delay($this->attempts()));
        } catch (BudgetExhausted $budget) {
            $this->stop($suspensions, 'budget', $budget->period, suspend: $budget->period !== 'per_run');
        }
    }

    /** Ends the job quietly (never failed), cancels its batch, and keeps the run for prosetta:resume. */
    private function stop(Suspensions $suspensions, string $circuit, string $reason, bool $suspend = true): void {
        if ($suspend) {
            $suspensions->suspend($circuit, $this->scope !== null ? RunScope::fromArray($this->scope) : new RunScope([$this->locale], [], [], $this->force), $reason);
        }

        $this->batch()?->cancel();
        $this->delete();
    }
}
```

`src/Translation/Translator.php`:
1. Imports: `use Illuminate\Support\Str;`, `use LonelyLights\Prosetta\Exceptions\Provider\ProviderException;`, `use LonelyLights\Prosetta\Resilience\BudgetExhausted;`, `use LonelyLights\Prosetta\Resilience\CallDeferred;`, `use LonelyLights\Prosetta\Resilience\RunScope;`, `use LonelyLights\Prosetta\Resilience\Suspensions;`.
2. Constructor: add `private Suspensions $suspensions,`.
3. In `translate()`, after `$size = …`, add `$scope = new RunScope(array_values($locales), array_values($namespaces), array_values($keys), $force);`.
4. Replace the `if (! $queue) { … }` block with:
```php
        if (! $queue) {
            $report = new TranslateReport;
            $runId = (string) Str::uuid();

            foreach ($work as $locale => $files) {
                foreach ($files as $ids) {
                    foreach (array_chunk($ids, $size) as $chunk) {
                        try {
                            $report->merge($this->runner->run($locale, $chunk, $force, $runId));
                        } catch (CallDeferred|ProviderException|BudgetExhausted $e) {
                            $report->stopped = $e->getMessage();
                            $this->suspendSync($e, $scope);

                            return $report;
                        }
                    }
                }
            }

            return $report;
        }
```
5. In the queued branch, change `new TranslateBatch($locale, $fileId, $chunk, $force)` to `new TranslateBatch($locale, $fileId, $chunk, $force, $scope->toArray())`.
6. Add the private method:
```php
    /** A synchronous run suspends like a queued one, except when only its own per-run budget ran out. */
    private function suspendSync(CallDeferred|ProviderException|BudgetExhausted $e, RunScope $scope): void {
        [$circuit, $reason] = match (true) {
            $e instanceof BudgetExhausted => ['budget', $e->period],
            $e instanceof CallDeferred => [$e->circuit, $e->reason === 'held' ? 'halted' : 'outage'],
            default => [(string) $e->circuit, class_basename($e)],
        };

        if ($reason !== 'per_run') {
            $this->suspensions->suspend($circuit, $scope, $reason);
        }
    }
```

`src/Console/TranslateCommand.php`: after the info line for a synchronous result, and before the `withIssues` loop, add:
```php
        if ($result->stopped !== null) {
            $this->components->error('Stopped early: '.$result->stopped.' The rest is suspended for prosetta:resume unless only the per-run budget ran out.');
        }
```
and change the return to `return $result->failed === [] && $result->stopped === null ? self::SUCCESS : self::FAILURE;`.

- [ ] **Step 4: Run to see them pass, then the whole suite**

Run: `vendor/bin/pest tests/Feature/Translation` → PASS. Then `vendor/bin/pest` → green.

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/TranslateBatch.php src/Translation/Translator.php src/Console/TranslateCommand.php tests/Feature/Translation
git commit -m "Release, wait out or suspend translation jobs by error class instead of retrying at once"
```

---

### Task 8: Resume, circuit status and reset, scheduling, logging

**Files:**
- Create: `src/Console/ResumeCommand.php`, `src/Console/CircuitCommand.php`, `src/Events/TranslationResumed.php`, `src/Resilience/LogResilienceEvents.php`
- Modify: `src/ProsettaServiceProvider.php`
- Test: `tests/Feature/Console/ResilienceCommandsTest.php`

**Interfaces:**
- Consumes: `Suspensions`, `Circuits`, `ProviderGate::test()`, `Budget`, `Translator::translate()`.
- Produces:
  - `prosetta:resume`
  - `prosetta:circuit {action=status} {circuit?}`, where the action is `status` or `reset`
  - Event `TranslationResumed(string $circuit, int $scopes)`
  - A scheduled `prosetta:resume` when `resilience.resume_every` is set
  - Log lines for the six resilience events

- [ ] **Step 1: Write the failing test**

`tests/Feature/Console/ResilienceCommandsTest.php`:
```php
<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Events\TranslationResumed;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\HealthCheckedScriptedDriver;
use LonelyLights\Prosetta\Testing\ScriptedDriver;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
});

function suspendSpanish(string $circuit): void {
    app(Suspensions::class)->suspend($circuit, new RunScope(['es'], ['*'], []), 'outage');
}

it('requeues suspended work when the circuit is closed', function () {
    Bus::fake();
    Event::fake([TranslationResumed::class]);
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    suspendSpanish('scripted-driver:default');

    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertBatchCount(1);
    expect(app(Suspensions::class)->all())->toBe([]);
    Event::assertDispatched(TranslationResumed::class, fn (TranslationResumed $event) => $event->scopes === 1);
});

it('leaves work suspended while the cooldown runs', function () {
    Bus::fake();
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    $circuit = app(Circuits::class)->for('scripted-driver:default');
    foreach (range(1, 5) as $ignored) {
        $circuit->recordFailure('down');
    }
    suspendSpanish('scripted-driver:default');

    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertNothingBatched();
    expect(app(Suspensions::class)->all())->toHaveCount(1);
});

it('tests with the health check after the cooldown and resumes only when it passes', function () {
    Bus::fake();
    $driver = (new HealthCheckedScriptedDriver)->failHealth(new ProviderUnavailable('still down'));
    app()->instance(TranslationDriver::class, $driver);
    $circuit = app(Circuits::class)->for('health-checked-scripted-driver:default');
    foreach (range(1, 5) as $ignored) {
        $circuit->recordFailure('down');
    }
    suspendSpanish('health-checked-scripted-driver:default');
    $this->travel(301)->seconds();

    $this->artisan('prosetta:resume')->assertSuccessful();
    Bus::assertNothingBatched();

    $this->travel(601)->seconds();
    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertBatchCount(1);
    expect($driver->healthChecks)->toBe(2)
        ->and(app(Suspensions::class)->all())->toBe([]);
});

it('waits for the next day when the daily budget is spent', function () {
    Bus::fake();
    config(['prosetta.budgets.daily' => 10]);
    app(\LonelyLights\Prosetta\Resilience\Budget::class)->record(null, 10);
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    suspendSpanish('budget');

    $this->artisan('prosetta:resume')->assertSuccessful();
    Bus::assertNothingBatched();

    $this->travelTo(now()->addDay()->startOfDay()->addMinute());
    $this->artisan('prosetta:resume')->assertSuccessful();
    Bus::assertBatchCount(1);
});

it('resuming twice queues nothing new', function () {
    Bus::fake();
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    suspendSpanish('scripted-driver:default');

    $this->artisan('prosetta:resume')->assertSuccessful();
    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertBatchCount(1);
});

it('shows and resets circuits', function () {
    $circuit = app(Circuits::class)->for('scripted-driver:default');
    $circuit->trip('rejected', 'bad key');

    $this->artisan('prosetta:circuit')->expectsOutputToContain('scripted-driver:default')->assertSuccessful();
    $this->artisan('prosetta:circuit reset')->assertSuccessful();

    expect($circuit->decision()->kind)->toBe('call');
});

it('schedules resume when resume_every is set', function () {
    config(['prosetta.resilience.resume_every' => 10]);
    (new \LonelyLights\Prosetta\ProsettaServiceProvider(app()))->boot();

    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'prosetta:resume'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/10 * * * *');
});

it('logs circuit and suspension events', function () {
    Log::spy();
    event(new \LonelyLights\Prosetta\Events\CircuitOpened('scripted-driver:default', 300, 5, 'down'));

    Log::shouldHaveReceived('channel')->withArgs(fn ($channel) => $channel === null);
});
```
If `Log::spy()` with `channel` proves awkward, write the logging assertion with a `Log::shouldReceive('channel->warning')->once()` expectation instead. The requirement is one warning per `CircuitOpened`.

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/pest tests/Feature/Console/ResilienceCommandsTest.php` → FAIL. `Command "prosetta:resume" is not defined`.

- [ ] **Step 3: Implement**

`src/Events/TranslationResumed.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** prosetta:resume queued $scopes suspended runs for this circuit again. */
final readonly class TranslationResumed {
    use Dispatchable;

    public function __construct(public string $circuit, public int $scopes) {}
}
```

`src/Console/ResumeCommand.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use LonelyLights\Prosetta\Contracts\ChecksHealth;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Events\TranslationResumed;
use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\ProviderGate;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Translation\Translator;
use Throwable;

final class ResumeCommand extends Command {
    protected $signature = 'prosetta:resume';

    protected $description = 'Test providers whose circuits are due and queue suspended translation work again.';

    /** @throws Throwable when a batch cannot be dispatched */
    public function handle(Suspensions $suspensions, Circuits $circuits, ProviderGate $gate, Budget $budget, Translator $translator, Container $container): int {
        $resumed = [];
        $tested = [];

        foreach ($suspensions->all() as $id => $suspended) {
            if (($period = $budget->exhausted(null)) !== null) {
                $this->line("Waiting: the $period budget is used up.");

                break;
            }

            $name = $suspended['circuit'];

            if ($name !== 'budget' && ! ($tested[$name] ??= $this->ready($circuits->for($name), $gate, $container))) {
                $this->line("Waiting: [$name] is not ready yet.");

                continue;
            }

            $scope = $suspended['scope'];
            $translator->translate($scope->locales, $scope->namespaces, $scope->keys, $scope->force);
            $suspensions->clear($id);
            $resumed[$name] = ($resumed[$name] ?? 0) + 1;
        }

        foreach ($resumed as $name => $count) {
            event(new TranslationResumed($name, $count));
            $this->components->info("Resumed $count suspended run(s) for [$name].");
        }

        return self::SUCCESS;
    }

    /** Closed: go. Held or cooling down: wait. Due for a test: health-check it, or let the queued jobs test it. */
    private function ready(\LonelyLights\Prosetta\Resilience\Circuit $circuit, ProviderGate $gate, Container $container): bool {
        $decision = $circuit->decision();

        if ($decision->kind === 'call') {
            return true;
        }

        if ($decision->kind !== 'test') {
            return false;
        }

        $driver = $container->bound(TranslationDriver::class) ? $container->make(TranslationDriver::class) : null;

        if ($driver instanceof ChecksHealth) {
            return $gate->test($driver, $circuit, $decision);
        }

        # No Health Check: the Requeued Jobs Test the Circuit Themselves, One at a Time
        $decision->release();

        return true;
    }
}
```

`src/Console/CircuitCommand.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\Suspensions;

final class CircuitCommand extends Command {
    protected $signature = 'prosetta:circuit
        {action=status : status or reset}
        {circuit? : One circuit to reset; all when omitted}';

    protected $description = 'Show translation circuits, suspended work and budgets, or reset circuits after a halt.';

    public function handle(Circuits $circuits, Suspensions $suspensions, Budget $budget): int {
        if ($this->argument('action') === 'reset') {
            $names = $this->argument('circuit') !== null ? [(string) $this->argument('circuit')] : $circuits->names();

            foreach ($names as $name) {
                $circuits->for($name)->reset();
                $this->components->info("Reset [$name]. Suspended work resumes on the next prosetta:resume.");
            }

            return self::SUCCESS;
        }

        $rows = array_map(function (string $name) use ($circuits) {
            $state = $circuits->for($name)->state();

            return [
                $name,
                $state['state'] === 'open' ? ($state['reason'] === 'halt' ? "halted ({$state['halt']})" : 'open') : 'closed',
                $state['failures'],
                $state['until'] === null ? ($state['state'] === 'open' ? 'until reset' : '-') : date('Y-m-d H:i:s', $state['until']),
                $state['message'] ?? '',
            ];
        }, $circuits->names());

        $this->table(['Circuit', 'State', 'Failures', 'Next test', 'Last error'], $rows);
        $this->line(count($suspensions->all()).' suspended run(s).');

        foreach ($budget->usage() as $period => ['used' => $used, 'limit' => $limit]) {
            $this->line("$period: $used / ".($limit ?? 'no limit').' tokens');
        }

        return self::SUCCESS;
    }
}
```

`src/Resilience/LogResilienceEvents.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use LonelyLights\Prosetta\Events\BudgetReached;
use LonelyLights\Prosetta\Events\CircuitClosed;
use LonelyLights\Prosetta\Events\CircuitOpened;
use LonelyLights\Prosetta\Events\TranslationHalted;
use LonelyLights\Prosetta\Events\TranslationResumed;
use LonelyLights\Prosetta\Events\TranslationSuspended;

/** One log line per resilience event, on prosetta.log_channel (null = the default channel). */
final class LogResilienceEvents {
    public function subscribe(Dispatcher $events): void {
        $events->listen(CircuitOpened::class, fn (CircuitOpened $e) => $this->log('warning', "Circuit [$e->circuit] opened after $e->failures failures; next test in $e->cooldown s. Last error: $e->message"));
        $events->listen(CircuitClosed::class, fn (CircuitClosed $e) => $this->log('info', "Circuit [$e->circuit] closed after $e->downtime s of downtime."));
        $events->listen(TranslationHalted::class, fn (TranslationHalted $e) => $this->log('warning', "Translation halted on [$e->circuit] ($e->reason): $e->message"));
        $events->listen(TranslationSuspended::class, fn (TranslationSuspended $e) => $this->log('warning', "Translation suspended on [$e->circuit] ($e->reason) for ".implode(', ', $e->scope->locales ?: ['every locale']).'.'));
        $events->listen(TranslationResumed::class, fn (TranslationResumed $e) => $this->log('info', "Resumed $e->scopes suspended run(s) on [$e->circuit]."));
        $events->listen(BudgetReached::class, fn (BudgetReached $e) => $this->log('warning', "The $e->period token budget is used up ($e->used / $e->limit)."));
    }

    private function log(string $level, string $message): void {
        Log::channel(config('prosetta.log_channel'))->{$level}("[prosetta] $message");
    }
}
```

`src/ProsettaServiceProvider.php`:
- Add `ResumeCommand::class, CircuitCommand::class` to the `$this->commands([...])` list (and their imports).
- At the end of `boot()`:
```php
        Event::subscribe(LogResilienceEvents::class);

        $every = config('prosetta.resilience.resume_every');

        if ($every !== null && (int) $every > 0) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($every): void {
                $schedule->command('prosetta:resume')->cron('*/'.max(1, min(59, (int) $every)).' * * * *')->withoutOverlapping();
            });
        }
```
- Imports: `use Illuminate\Console\Scheduling\Schedule;`, `use Illuminate\Support\Facades\Event;`, `use LonelyLights\Prosetta\Console\CircuitCommand;`, `use LonelyLights\Prosetta\Console\ResumeCommand;`, `use LonelyLights\Prosetta\Resilience\LogResilienceEvents;`.

If the scheduling test sees `Schedule` resolved before `boot()` ran again, register the schedule directly when `$this->app->resolved(Schedule::class)` is true. `callAfterResolving` covers both cases in Laravel 11+, so no extra branch should be needed. Verify this with the test.

- [ ] **Step 4: Run to see it pass, then the whole suite** (`vendor/bin/pest`)

- [ ] **Step 5: Commit**

```bash
git add src/Console/ResumeCommand.php src/Console/CircuitCommand.php src/Events/TranslationResumed.php src/Resilience/LogResilienceEvents.php src/ProsettaServiceProvider.php tests/Feature/Console/ResilienceCommandsTest.php
git commit -m "Add prosetta:resume and prosetta:circuit, schedule resume, and log resilience events"
```

---

### Task 9: Estimating a run before spending

**Files:**
- Create: `src/Translation/Estimator.php`
- Modify: `src/Console/TranslateCommand.php`
- Test: `tests/Feature/Translation/EstimatorTest.php`

**Interfaces:**
- Consumes: `Translator::workList()`, `Budget::usage()`.
- Produces:
  - `Estimator::estimate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false): array<string, array{strings: int, chars: int, input: int, output: int, from_history: bool}>`, keyed by locale
  - `prosetta:translate --estimate`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Translation/EstimatorTest.php`:
```php
<?php

use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Translation\Estimator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

it('estimates from the default rates before a locale has history', function () {
    $estimate = app(Estimator::class)->estimate(['ar']);

    expect($estimate['ar']['strings'])->toBeGreaterThan(0)
        ->and($estimate['ar']['from_history'])->toBeFalse()
        ->and($estimate['ar']['input'])->toBe((int) round(0.3 * $estimate['ar']['chars'] + 12 * $estimate['ar']['strings']))
        ->and($estimate['ar']['output'])->toBe((int) round(0.3 * $estimate['ar']['chars'] + 8 * $estimate['ar']['strings']));
});

it('estimates from a locale\'s own history once it has fifty AI drafts', function () {
    $file = \LonelyLights\Prosetta\Models\TranslationFile::query()->first();

    foreach (range(1, 50) as $i) {
        $key = \LonelyLights\Prosetta\Models\TranslationKey::query()->create([
            'file_id' => $file->id, 'kind' => \LonelyLights\Prosetta\Enums\KeyKind::File, 'key' => "history.$i",
            'source_value' => 'Ten chars.', 'source_hash' => 'h'.$i,
        ]);
        Translation::query()->create([
            'key_id' => $key->id, 'locale' => 'ar', 'value' => 'x', 'source_hash' => 'h'.$i,
            'status' => 'draft', 'origin' => 'ai', 'input_tokens' => 20, 'output_tokens' => 30,
        ]);
    }

    $estimate = app(Estimator::class)->estimate(['ar'])['ar'];

    expect($estimate['from_history'])->toBeTrue()
        ->and($estimate['input'])->toBe((int) round(2.0 * $estimate['chars']))
        ->and($estimate['output'])->toBe((int) round(3.0 * $estimate['chars']));
});

it('prints an estimate without queueing anything', function () {
    \Illuminate\Support\Facades\Bus::fake();

    $this->artisan('prosetta:translate --locale=ar --estimate')
        ->expectsOutputToContain('ar')
        ->assertSuccessful();

    \Illuminate\Support\Facades\Bus::assertNothingBatched();
});
```
**Why the history test creates its own keys:** `prosetta_translations` is unique on (`key_id`, `locale`), and the fixture has only a handful of keys, so the test adds 50 keys of 10 characters each, with one AI draft each (20 input and 30 output tokens). That gives history rates of 2.0 and 3.0 tokens per character. If `create()` rejects a column because it isn't fillable, use `forceFill([...])->save()` for the seed rows.

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/pest tests/Feature/Translation/EstimatorTest.php` → FAIL. `Class ... Estimator not found`.

- [ ] **Step 3: Implement**

`src/Translation/Estimator.php`:
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Prices a run before it spends anything: strings, source characters and
 * expected tokens per locale, from the locale's own AI history once it has
 * 50 drafts, otherwise from prosetta.budgets.estimate.
 */
final readonly class Estimator {
    private const int HISTORY_MINIMUM = 50;

    public function __construct(private Translator $translator) {}

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys
     * @return array<string, array{strings: int, chars: int, input: int, output: int, from_history: bool}>
     */
    public function estimate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false): array {
        $keyModel = Settings::model('key');
        $estimates = [];

        foreach ($this->translator->workList($locales, $namespaces, $keys, $force) as $locale => $files) {
            $ids = array_merge(...array_values($files));
            $chars = (int) $keyModel::query()->whereKey($ids)->get(['source_value'])->sum(fn ($key) => mb_strlen((string) $key->source_value));
            $strings = count($ids);
            $history = $this->history($locale);

            $estimates[$locale] = $history !== null
                ? ['strings' => $strings, 'chars' => $chars, 'input' => (int) round($history['input'] * $chars), 'output' => (int) round($history['output'] * $chars), 'from_history' => true]
                : ['strings' => $strings, 'chars' => $chars, 'input' => $this->rate('input', $chars, $strings), 'output' => $this->rate('output', $chars, $strings), 'from_history' => false];
        }

        return $estimates;
    }

    /** @return array{input: float, output: float}|null tokens per source character, from this locale's AI drafts */
    private function history(string $locale): ?array {
        $translationModel = Settings::model('translation');
        $rows = $translationModel::query()->with('key:id,source_value')
            ->where('locale', $locale)->where('origin', TranslationOrigin::Ai)->whereNotNull('input_tokens')
            ->get(['key_id', 'input_tokens', 'output_tokens']);

        if ($rows->count() < self::HISTORY_MINIMUM) {
            return null;
        }

        $chars = max(1, (int) $rows->sum(fn ($row) => mb_strlen((string) $row->key?->source_value)));

        return ['input' => $rows->sum('input_tokens') / $chars, 'output' => $rows->sum('output_tokens') / $chars];
    }

    private function rate(string $side, int $chars, int $strings): int {
        $rates = (array) config('prosetta.budgets.estimate', []);

        return (int) round((float) ($rates[$side.'_per_char'] ?? 0.3) * $chars + (float) ($rates[$side.'_per_item'] ?? 10) * $strings);
    }
}
```
(Check that `Translation` has a `key()` relation with `$translation->key`; it's used in `ReviewService::edit()`. If the relation name differs, use it.)

`src/Console/TranslateCommand.php`: add `{--estimate : Print what the run would cost, and queue nothing}` to the signature. At the top of `handle()` (add `Estimator $estimator, Budget $budget` parameters and their imports), insert:
```php
        if ($this->option('estimate')) {
            $estimate = $estimator->estimate($this->option('locale'), $this->option('namespace'), $this->option('key'), (bool) $this->option('force'));
            $this->table(['Locale', 'Strings', 'Characters', 'Input tokens', 'Output tokens', 'Based on'], array_map(
                fn (string $locale, array $row) => [$locale, $row['strings'], $row['chars'], $row['input'], $row['output'], $row['from_history'] ? 'history' : 'defaults'],
                array_keys($estimate), $estimate,
            ));
            $total = array_sum(array_map(fn (array $row) => $row['input'] + $row['output'], $estimate));
            $this->line("Total: about $total tokens.");

            foreach ($budget->usage() as $period => ['used' => $used, 'limit' => $limit]) {
                if ($limit !== null) {
                    $this->line("$period budget: ".max(0, $limit - $used)." tokens left of $limit.");
                }
            }

            return self::SUCCESS;
        }
```

- [ ] **Step 4: Run to see it pass, then the whole suite** (`vendor/bin/pest`)

- [ ] **Step 5: Commit**

```bash
git add src/Translation/Estimator.php src/Console/TranslateCommand.php tests/Feature/Translation/EstimatorTest.php
git commit -m "Estimate a translation run's tokens before spending them"
```

---

### Task 10: Documentation

**Files:**
- Modify: `README.md`: a "Resilience" section after the commands table, covering the error classes, `ChecksHealth`, the config blocks with defaults, the commands, the events, and "run `queue:restart` after deploys"
- Modify: `docs/handoff/2026-09-22-undaunted-adoption.md`: append "§11. Resilience layer (added 2026-09-23)" with the Undaunted steps from spec §12 and Task 11/12 below
- Modify: `docs/superpowers/specs/2026-09-23-automation-gaps-and-edge-cases.md`: mark G1 **Fixed** with the commit SHA; mark the provider and network rows **Handled**; mark G8 as **Partly fixed** (token budgets and an estimate; money budgets pending)

- [ ] **Step 1:** Write the three documents from the spec (no new behavior).
- [ ] **Step 2:** Run `vendor/bin/pest` → green (docs only; confirms nothing else changed).
- [ ] **Step 3: Commit**

```bash
git add README.md docs
git commit -m "Document the resilience layer and mark the gaps it closes"
```

---

### Task 11 (Undaunted): map laravel/ai errors and add the health check

Work in `C:\Websites\undaunted\undaunted-web` on a branch `feat/translation-resilience`.

**Files:**
- Modify: `app/Services/Translation/LaravelAiTranslationDriver.php`
- Test: `tests/Feature/Ai/TranslationDriverTest.php` (append)

**Interfaces:**
- Consumes: Prosetta's provider exceptions and `ChecksHealth` (through the junction; Tasks 1–10 are already in the package).
- Produces: `LaravelAiTranslationDriver implements TranslationDriver, ChecksHealth`, with the mapping from spec §12.

- [ ] **Step 1: Write the failing tests** (append to `tests/Feature/Ai/TranslationDriverTest.php`)

```php
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use LonelyLights\Prosetta\Contracts\ChecksHealth;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderQuotaExhausted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRateLimited;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;

function httpFailure(int $status, array $headers = []): RequestException {
    return new RequestException(new ClientResponse(new Psr7Response($status, $headers, '{"error":{"message":"x"}}')));
}

it('maps laravel/ai failures onto Prosetta\'s error classes', function (Throwable $thrown, string $expected) {
    Translator::fake(fn () => throw $thrown);

    expect(fn () => app(TranslationDriver::class)->translate(capReachedBatch()))->toThrow($expected);
})->with([
    'rate limited' => [RateLimitedException::forProvider('openai', 429, httpFailure(429)), ProviderRateLimited::class],
    'overloaded' => [ProviderOverloadedException::forProvider('openai', 503), ProviderUnavailable::class],
    'unreachable' => [ProviderConnectionException::forProvider('openai'), ProviderUnavailable::class],
    'out of credits' => [InsufficientCreditsException::forProvider('openai', 402), ProviderQuotaExhausted::class],
    'bad key' => [httpFailure(401), ProviderRejected::class],
    'unknown model' => [httpFailure(404), ProviderRejected::class],
    'server error' => [httpFailure(500), ProviderUnavailable::class],
]);

it('passes on the provider\'s Retry-After', function () {
    Translator::fake(fn () => throw RateLimitedException::forProvider('openai', 429, httpFailure(429, ['Retry-After' => '42'])));

    try {
        app(TranslationDriver::class)->translate(capReachedBatch());
    } catch (ProviderRateLimited $e) {
        expect($e->retryAfter)->toBe(42);
    }
});

it('checks its health with one tiny prompt', function () {
    Translator::fake([['translations' => [['id' => 'health', 'value' => 'OK']]]]);

    $driver = app(TranslationDriver::class);
    expect($driver)->toBeInstanceOf(ChecksHealth::class);
    $driver->checkHealth();

    Translator::assertPrompted(fn ($prompt) => json_decode($prompt->prompt, true)['items'] === [['id' => 'health', 'text' => 'OK']]);
});
```
Check the `forProvider` signatures in `vendor/laravel/ai/src/Exceptions/*.php` (`RateLimitedException::forProvider(string $provider, int $code = 0, ?Throwable $previous = null)`), and adjust the dataset calls if an exception class takes different arguments.

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test tests/Feature/Ai/TranslationDriverTest.php` → FAIL. Raw laravel/ai exceptions escape, and there's no `checkHealth()`.

- [ ] **Step 3: Implement** (`app/Services/Translation/LaravelAiTranslationDriver.php`)

Add the imports for the laravel/ai exceptions, `Illuminate\Http\Client\RequestException`, Prosetta's `ChecksHealth` and the four provider exceptions. Then:
1. `final readonly class LaravelAiTranslationDriver implements TranslationDriver, ChecksHealth {`
2. In `translate()`, replace `$response = $this->agent->prompt(json_encode([...]), model: $batch->model);` with `$response = $this->prompt(json_encode([...], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $batch->model);`, keeping the payload exactly as it is.
3. Add:
```php
    /** One word to Spanish with the configured model: proves the key, the model and the provider for a few tokens. */
    public function checkHealth(): void {
        $model = config('prosetta.ai.model');

        $this->prompt((string) json_encode([
            'from' => 'en',
            'to' => ['code' => 'es', 'language' => 'Spanish', 'script' => 'Latin'],
            'variant_of' => null,
            'items' => [['id' => 'health', 'text' => 'OK']],
        ]), is_string($model) && $model !== '' ? $model : null);
    }

    /** Prompts the Translator agent, turning laravel/ai's failures into Prosetta's error classes. */
    private function prompt(string $payload, ?string $model): mixed {
        try {
            return $this->agent->prompt($payload, model: $model);
        } catch (RateLimitedException $e) {
            throw new ProviderRateLimited($e->getMessage(), $this->retryAfter($e), $e);
        } catch (ProviderOverloadedException|ProviderConnectionException $e) {
            throw new ProviderUnavailable($e->getMessage(), 0, $e);
        } catch (InsufficientCreditsException $e) {
            throw new ProviderQuotaExhausted($e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $status = $e->response->status();

            throw match (true) {
                in_array($status, [400, 401, 403, 404, 422], true) => new ProviderRejected($e->getMessage(), 0, $e),
                $status === 408 || $status >= 500 => new ProviderUnavailable($e->getMessage(), 0, $e),
                default => $e,
            };
        }
    }

    /** The provider's Retry-After, in seconds, when it sent a numeric one. */
    private function retryAfter(RateLimitedException $e): ?int {
        $previous = $e->getPrevious();
        $header = $previous instanceof RequestException ? $previous->response->header('Retry-After') : '';

        return is_numeric($header) ? max(1, (int) $header) : null;
    }
```
4. Update the class docblock. Replace "Deliberately not fail-soft: a thrown provider error fails the queued chunk, and Prosetta's job retries it until retryUntil()" with: "Provider errors are mapped onto Prosetta's error classes, so its circuit breaker can back off, wait out an outage, or halt on a bad key or empty credits."

- [ ] **Step 4: Run to see them pass**

Run: `php artisan test tests/Feature/Ai` → PASS.

- [ ] **Step 5: Commit** (Undaunted, on `feat/translation-resilience`)

```bash
git add app/Services/Translation/LaravelAiTranslationDriver.php tests/Feature/Ai/TranslationDriverTest.php
git commit -m "feat(locales): map laravel/ai failures onto Prosetta's error classes and add a health check"
```

---

### Task 12 (Undaunted): configure, schedule and prove it end to end

**Files:**
- Modify: `config/prosetta.php` (Undaunted's copy)
- Modify: `composer.json` (`dev` script)
- Create: `tests/Feature/Ai/TranslationResilienceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Ai/TranslationResilienceTest.php`:
```php
<?php

declare(strict_types=1);

use App\Ai\Agents\Translator;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;

/**
 * Undaunted's side of Prosetta's resilience layer: the configured values,
 * and a provider outage that opens the circuit and recovers through
 * prosetta:resume and the driver's health check.
 */
it('uses the values chosen for Undaunted', function () {
    expect(config('prosetta.resilience.halt_hold'))->toBe(600)
        ->and(config('prosetta.resilience.resume_every'))->toBe(10)
        ->and(config('prosetta.budgets.per_run'))->toBe(250_000)
        ->and(config('prosetta.budgets.daily'))->toBe(500_000)
        ->and(config('prosetta.budgets.monthly'))->toBe(5_000_000);
});

it('opens the circuit on repeated overloads and recovers through prosetta:resume', function () {
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
    Bus::fake();
    $gate = app(\LonelyLights\Prosetta\Resilience\ProviderGate::class);
    $driver = app(\LonelyLights\Prosetta\Contracts\TranslationDriver::class);
    $name = Circuits::nameFor($driver, null);

    Translator::fake(fn () => throw ProviderOverloadedException::forProvider('openai', 503));
    foreach (range(1, 5) as $ignored) {
        rescue(fn () => $gate->call($driver, $name, null, fn () => $driver->translate(capReachedBatch())), report: false);
    }
    expect(app(Circuits::class)->for($name)->decision()->kind)->toBe('wait');

    app(Suspensions::class)->suspend($name, new RunScope(['es'], ['identity'], []), 'outage');
    $this->travel(301)->seconds();
    Translator::fake([['translations' => [['id' => 'health', 'value' => 'OK']]]]);

    $this->artisan('prosetta:resume')->assertSuccessful();

    expect(app(Circuits::class)->for($name)->decision()->kind)->toBe('call')
        ->and(app(Suspensions::class)->all())->toBe([]);
});
```
`capReachedBatch()` is defined in `TranslationDriverTest.php`. Pest loads test files in one process, so it's available; if it isn't, copy the helper into this file under another name.

- [ ] **Step 2: Run to see it fail**

Run: `php artisan test tests/Feature/Ai/TranslationResilienceTest.php` → FAIL on the config values.

- [ ] **Step 3: Implement**

Undaunted `config/prosetta.php`, before `'log_channel'`:
```php
    # Provider Trouble: Back Off, Open the Circuit After Repeated Failures, and Test Again With the Driver's
    # One-Word Health Check. A Halt (Bad Key, No Credits) Tests Itself After 10 Minutes; prosetta:resume Runs
    # Every 10 Minutes, so Work Suspended by an Outage or a Budget Restarts on Its Own
    'resilience' => [
        'cache_store' => env('PROSETTA_CACHE_STORE'),   # Redis in Production, so Every Worker Shares the Circuit
        'backoff' => [30, 60, 120, 300, 600, 900],
        'jitter' => 0.2,
        'circuit' => ['failure_threshold' => 5, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 3600],
        'outage_timeout' => 21600,
        'halt_hold' => 600,
        'unknown_errors' => 'transient',
        'resume_every' => 10,
    ],

    # Token Budgets (Input + Output); the Spanish Run Used About 74k for 1,795 Strings
    'budgets' => [
        'per_run' => 250_000,
        'daily' => 500_000,
        'monthly' => 5_000_000,
        'estimate' => ['input_per_char' => 0.3, 'output_per_char' => 0.3, 'input_per_item' => 12, 'output_per_item' => 8],
    ],

```
`composer.json` `dev` script: add a scheduler process so `prosetta:resume` (and the existing schedules) run locally. In the `npx concurrently` string, add `\"php artisan schedule:work\"` before `\"npm run dev\"`, add a colour (`#fde68a`) to `-c`, and add `schedule` to `--names`, in matching positions. Confirm the positions line up by reading the string back.

- [ ] **Step 4: Run to see it pass, then everything**

Run: `php artisan test tests/Feature/Ai` → PASS.
Run: `php artisan test --parallel` → all green (720 before, plus the new ones).
Run: `npm run -s test:run` → green (nothing front-end changed; confirms that).
Run: `vendor/bin/pint --test app config tests` → passed.
Run: `composer validate --no-check-publish` → valid.

- [ ] **Step 5: Commit, merge, push**

```bash
git add config/prosetta.php composer.json tests/Feature/Ai/TranslationResilienceTest.php
git commit -m "feat(locales): turn on Prosetta's resilience layer with Undaunted's thresholds and a local scheduler"
git switch main
git merge --no-ff feat/translation-resilience -m "Merge feat/translation-resilience: resilient translation runs"
git branch -d feat/translation-resilience
```
Push both repos only when the user says so.

After merging, restart the running translations worker (`php artisan queue:restart`) so it loads the new driver, and tell the user to restart `composer dev` to start the scheduler process.
