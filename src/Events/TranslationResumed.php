<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

/** prosetta:resume queued $scopes suspended runs for this circuit again. */
final readonly class TranslationResumed {
    public function __construct(public string $circuit, public int $scopes) {}
}
