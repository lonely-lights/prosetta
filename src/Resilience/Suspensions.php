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
