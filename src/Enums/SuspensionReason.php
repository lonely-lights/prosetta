<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

use LonelyLights\Prosetta\Exceptions\Provider\ProviderException;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderQuotaExhausted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;

/** The words a suspension is stored under. A queued job and a synchronous run agree on all of them. */
enum SuspensionReason: string {
    case Outage = 'outage';
    case Halted = 'halted';
    case Rejected = 'rejected';
    case Quota = 'quota';
    case Unknown = 'unknown';
    case Daily = 'daily';
    case Monthly = 'monthly';

    /**
     * Classifies a halt from ProviderRejected or ProviderQuotaExhausted. Under
     * resilience.unknown_errors=halt, the gate classifies an error it doesn't
     * recognize as ProviderRejected with the original, non-provider exception
     * chained as getPrevious(); that case keeps its own word instead of the
     * generic 'rejected', so a queued job and a synchronous run agree.
     */
    public static function forHalt(ProviderRejected|ProviderQuotaExhausted $halt): self {
        if ($halt instanceof ProviderQuotaExhausted) {
            return self::Quota;
        }

        $previous = $halt->getPrevious();

        return $previous !== null && ! $previous instanceof ProviderException ? self::Unknown : self::Rejected;
    }
}
