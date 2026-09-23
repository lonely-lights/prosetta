<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use LonelyLights\Prosetta\Exceptions\ProsettaException;

/**
 * The gate made no call: the circuit is open (retry in $seconds) or held
 * after a halt. $outage is true once the circuit has been open longer than
 * outage_timeout, which tells the job to suspend instead of waiting.
 */
final class CallDeferred extends ProsettaException {
    public function __construct(public readonly string $circuit, public readonly int $seconds, public readonly string $reason, public readonly bool $outage = false) {
        parent::__construct($reason === 'held'
            ? "Translation is halted for [$circuit] until the hold ends or prosetta:circuit reset."
            : "The provider behind [$circuit] is failing; the next try is in $seconds s.");
    }
}
