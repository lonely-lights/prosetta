<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Contracts\Cache\Lock;

/**
 * What a circuit allows right now: call the provider, make the one test
 * call (holding the test lock), wait $seconds, or stay held until reset.
 * A wait with $testing set is only for another caller's test call in
 * flight: the cooldown is over, so it never counts towards an outage.
 */
final readonly class Decision {
    private function __construct(public string $kind, public int $seconds = 0, public ?Lock $lock = null, public bool $testing = false) {}

    public static function call(): self {
        return new self('call');
    }

    public static function test(Lock $lock): self {
        return new self('test', 0, $lock);
    }

    public static function wait(int $seconds, bool $testing = false): self {
        return new self('wait', max(1, $seconds), testing: $testing);
    }

    public static function held(): self {
        return new self('held');
    }

    public function release(): void {
        $this->lock?->release();
    }
}
