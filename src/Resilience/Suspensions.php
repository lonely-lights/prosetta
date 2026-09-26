<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use LonelyLights\Prosetta\Events\TranslationSuspended;
use LonelyLights\Prosetta\Support\Settings;

/** Runs stopped by an outage, a halt or a budget, kept in the cache until prosetta:resume queues them again. */
final readonly class Suspensions {
    private const string KEY = 'prosetta:suspended';

    /** Seconds the write lock is held before it expires on its own, well past the time any mutation needs. */
    private const int WRITE_LOCK_SECONDS = 10;

    /** Seconds a mutation blocks waiting for the write lock before giving up. */
    private const int WRITE_LOCK_WAIT_SECONDS = 5;

    public function __construct(private Dispatcher $events) {}

    public function suspend(string $circuit, RunScope $scope, string $reason): void {
        $this->mutate(function () use ($circuit, $scope, $reason) {
            $stored = $this->stored();
            $id = $circuit.'|'.$scope->id();
            $new = ! isset($stored[$id]);
            $stored[$id] = ['circuit' => $circuit, 'scope' => $scope->toArray(), 'reason' => $reason, 'at' => now()->getTimestamp()];
            Settings::cacheStore()->forever(self::KEY, $stored);

            if ($new) {
                $this->events->dispatch(new TranslationSuspended($circuit, $reason, $scope));
            }
        });
    }

    /** @return array<string, array{circuit: string, scope: RunScope, reason: string, at: int}> */
    public function all(): array {
        return array_map(fn (array $row) => [...$row, 'scope' => RunScope::fromArray($row['scope'])], $this->stored());
    }

    public function clear(string $id): void {
        $this->mutate(function () use ($id) {
            $stored = $this->stored();
            unset($stored[$id]);
            Settings::cacheStore()->forever(self::KEY, $stored);
        });
    }

    /**
     * Serializes a read-compute-write transition through a short blocking lock.
     *
     * @param Closure(): void $change
     */
    private function mutate(Closure $change): void {
        Settings::cacheStore()->locked(self::KEY.':write', self::WRITE_LOCK_SECONDS, self::WRITE_LOCK_WAIT_SECONDS, $change);
    }

    /** @return array<string, array{circuit: string, scope: array<string, mixed>, reason: string, at: int}> */
    private function stored(): array {
        return (array) Settings::cacheStore()->get(self::KEY, []);
    }
}
