<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

/** A circuit's test call succeeded; calls flow again. $downtime is seconds since it opened. */
final readonly class CircuitClosed {
    public function __construct(public string $circuit, public int $downtime) {}
}
