<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

/** A circuit stopped calling its provider after repeated failures. Raised on closed → open only. */
final readonly class CircuitOpened {
    public function __construct(public string $circuit, public int $cooldown, public int $failures, public string $message) {}
}
