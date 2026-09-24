<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Translation\TranslateReport;

/**
 * What a TranslationDriver throws when its provider fails, so Prosetta can
 * tell a passing outage from a problem retrying won't fix. The gate fills in
 * the circuit the failure belongs to.
 */
abstract class ProviderException extends ProsettaException {
    public ?string $circuit = null;

    /** What TranslationRunner::run() had already drafted or failed before it rethrew, when it got that far. */
    public ?TranslateReport $partial = null;
}
