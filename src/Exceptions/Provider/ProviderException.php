<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

use LonelyLights\Prosetta\Exceptions\ProsettaException;

/**
 * What a TranslationDriver throws when its provider fails, so Prosetta can
 * tell a passing outage from a problem retrying won't fix. The gate fills in
 * the circuit the failure belongs to.
 */
abstract class ProviderException extends ProsettaException {
    public ?string $circuit = null;
}
