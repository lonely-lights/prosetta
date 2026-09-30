<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/**
 * What some usage cost, and how many of its tokens couldn't be priced (an
 * unknown model, or usage recorded before models were); show "about" when
 * that isn't zero. unpricedModels names each model with no price, so a host
 * can say which to add to prosetta.ai.prices.
 */
final readonly class Cost {
    /** @param list<string> $unpricedModels */
    public function __construct(
        public float $amount,
        public int $unpricedTokens,
        public string $currency,
        public array $unpricedModels = [],
    ) {}
}
