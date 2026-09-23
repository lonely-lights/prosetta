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

        return max(1, $seconds + ($range > 0 ? mt_rand(-$range, $range) : 0));
    }
}
