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
