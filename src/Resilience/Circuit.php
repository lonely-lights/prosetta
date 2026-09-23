<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use LonelyLights\Prosetta\Events\CircuitClosed;
use LonelyLights\Prosetta\Events\CircuitOpened;

/**
 * One provider's circuit breaker, its state in the cache so every worker
 * shares it. Closed: calls flow. Open: nobody calls until the cooldown ends,
 * then exactly one caller (holding a lock) tests; success closes it, failure
 * re-opens it with a longer cooldown. A halt trips it for halt_hold seconds,
 * or until reset when halt_hold is null.
 *
 * Every state transition (recordSuccess, recordFailure, trip) is serialized
 * through a short write lock, so two workers racing the same circuit can't
 * both observe the pre-transition state and double-fire CircuitOpened or a
 * halt trip.
 */
final class Circuit {
    /** Seconds the test lock is held before it expires on its own: a job's timeout plus a margin. */
    private const int TEST_LOCK_SECONDS = 330;

    /** Seconds the write lock is held before it expires on its own, well past the time any mutation needs. */
    private const int WRITE_LOCK_SECONDS = 10;

    /** Seconds a mutation blocks waiting for the write lock before giving up. */
    private const int WRITE_LOCK_WAIT_SECONDS = 5;

    public function __construct(public readonly string $name, private readonly CacheStore $cache, private readonly Dispatcher $events) {}

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

        return $lock->get() ? Decision::test($lock) : Decision::wait(60, testing: true);
    }

    /**
     * Only the test call ($decision of kind 'test') may close an open circuit:
     * a late success from a call that was already in flight when it opened
     * proves nothing about the provider now. A healthy circuit (closed, no
     * failures) is left alone without taking the write lock, so a paid-for
     * result is never thrown away by a lock timeout.
     */
    public function recordSuccess(?Decision $decision = null): void {
        $state = $this->state();

        if ($state['state'] === 'closed' && $state['failures'] === 0) {
            $decision?->release();

            return;
        }

        $this->mutate(function () use ($decision) {
            $state = $this->state();

            if ($state['state'] === 'open') {
                if ($this->isTest($decision)) {
                    $this->events->dispatch(new CircuitClosed($this->name, now()->getTimestamp() - (int) $state['opened_at']));
                    $this->cache->forget($this->key());
                }
            } elseif ($state['failures'] > 0) {
                $this->cache->forget($this->key());
            }
        });

        $decision?->release();
    }

    /**
     * Counts a failure on a closed circuit, opening it at the threshold. On an
     * open circuit only the test call re-opens it (a longer cooldown, or
     * another halt_hold); a late failure from a call already in flight only
     * updates the message, so N workers don't raise the cooldown N times.
     */
    public function recordFailure(string $message, ?Decision $decision = null): void {
        $this->mutate(function () use ($message, $decision) {
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
            } elseif (! $this->isTest($decision)) {
                $this->save($state);
            } elseif ($state['reason'] === 'halt') {
                $hold = $this->haltHold();
                $this->save([...$state, 'until' => $hold === null ? null : $now + $hold]);
            } else {
                $cooldown = min($this->setting('max_cooldown', 3600), (int) round(max(1, $state['cooldown']) * max(1.0, (float) config('prosetta.resilience.circuit.cooldown_multiplier', 2))));
                $this->save([...$state, 'cooldown' => $cooldown, 'until' => $now + $cooldown]);
            }
        });

        $decision?->release();
    }

    /** Trips the circuit for a halt; true when it wasn't already halted, so the caller raises TranslationHalted once. */
    public function trip(string $halt, string $message, ?Decision $decision = null): bool {
        $new = $this->mutate(function () use ($halt, $message) {
            $state = $this->state();
            $now = now()->getTimestamp();
            $hold = $this->haltHold();
            $new = ! ($state['state'] === 'open' && $state['reason'] === 'halt');

            $this->save([
                ...$state, 'state' => 'open', 'reason' => 'halt', 'halt' => $halt, 'message' => $message,
                'opened_at' => $state['opened_at'] ?? $now, 'cooldown' => $hold ?? 0, 'until' => $hold === null ? null : $now + $hold,
            ]);

            return $new;
        });

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

    /** Serializes a read-compute-write transition through a short blocking lock, separate from the test lock. */
    private function mutate(Closure $change): mixed {
        return $this->cache->locked($this->key().':write', self::WRITE_LOCK_SECONDS, self::WRITE_LOCK_WAIT_SECONDS, $change);
    }

    private function isTest(?Decision $decision): bool {
        return $decision?->kind === 'test';
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
