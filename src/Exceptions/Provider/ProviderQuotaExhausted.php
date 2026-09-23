<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

/** Out of credits or quota: stops until someone tops up, or the hold ends and a test passes. */
final class ProviderQuotaExhausted extends ProviderException {}
