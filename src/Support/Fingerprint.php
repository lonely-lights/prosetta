<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

/** The one hash Prosetta uses for source values, file values and key lookups. */
final class Fingerprint {
    public static function of(string $value): string {
        return hash('sha256', $value);
    }
}
