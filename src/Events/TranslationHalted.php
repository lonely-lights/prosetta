<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

/** A provider problem retrying won't fix; reason is rejected, quota or unknown. Raised once per trip. */
final readonly class TranslationHalted {
    public function __construct(public string $circuit, public string $reason, public string $message) {}
}
