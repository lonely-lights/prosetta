<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Contracts;

use LonelyLights\Prosetta\Exceptions\Provider\ProviderException;

/**
 * Optional for a TranslationDriver. A near-free call that proves the
 * provider answers, used to test an open circuit without risking a batch.
 */
interface ChecksHealth {
    /** @throws ProviderException when the provider does not answer properly */
    public function checkHealth(): void;
}
