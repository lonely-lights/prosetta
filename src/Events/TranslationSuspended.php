<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Resilience\RunScope;

/** A run stopped (outage, halt or budget) and its scope was kept for prosetta:resume. */
final readonly class TranslationSuspended {
    public function __construct(public string $circuit, public string $reason, public RunScope $scope) {}
}
