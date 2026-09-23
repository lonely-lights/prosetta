<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

use Throwable;

/** A 429. retryAfter is the provider's own wait, in seconds, when it gave one. */
final class ProviderRateLimited extends ProviderException {
    public function __construct(string $message = 'The provider is rate limiting requests.', public readonly ?int $retryAfter = null, ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
    }
}
