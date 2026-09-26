<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/**
 * What some usage cost, and how many of its tokens couldn't be priced (an
 * unknown model, or usage recorded before models were); show "about" when
 * that isn't zero.
 */
final readonly class Cost {
    public function __construct(
        public float $amount,
        public int $unpricedTokens,
        public string $currency,
    ) {}
}
