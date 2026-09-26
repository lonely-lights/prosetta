<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Pricing;

use LonelyLights\Prosetta\Contracts\PriceCatalogue;
use LonelyLights\Prosetta\Data\ModelPrice;

/**
 * Prices from prosetta.ai.prices: 'model' => ['input' => 3.0, 'output' => 15.0],
 * per million tokens. A provider's dated id ("gpt-4.1-2025-04-14",
 * "claude-sonnet-5-20260101") finds the undated entry; nothing looser, so
 * "gpt-4.1-mini" never borrows "gpt-4.1"'s price.
 */
final readonly class ConfigPriceCatalogue implements PriceCatalogue {
    public function price(string $model): ?ModelPrice {
        # Read the Array Directly: config()'s Dot Notation Would Split a Name Like "gpt-4.1"
        $prices = (array) config('prosetta.ai.prices', []);
        $price = $prices[$model] ?? $prices[(string) preg_replace('/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', $model)] ?? null;

        if (! is_array($price) || ! is_numeric($price['input'] ?? null) || ! is_numeric($price['output'] ?? null)) {
            return null;
        }

        return new ModelPrice((float) $price['input'], (float) $price['output']);
    }

    public function currency(): string {
        return (string) config('prosetta.ai.currency', 'USD');
    }
}
