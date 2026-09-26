<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Pricing;

use LonelyLights\Prosetta\Contracts\PriceCatalogue;
use LonelyLights\Prosetta\Data\ModelPrice;

/** Prices from prosetta.ai.prices: 'model' => ['input' => 3.0, 'output' => 15.0], per million tokens. */
final readonly class ConfigPriceCatalogue implements PriceCatalogue {
    public function price(string $model): ?ModelPrice {
        $price = config("prosetta.ai.prices.$model");

        if (! is_array($price) || ! is_numeric($price['input'] ?? null) || ! is_numeric($price['output'] ?? null)) {
            return null;
        }

        return new ModelPrice((float) $price['input'], (float) $price['output']);
    }

    public function currency(): string {
        return (string) config('prosetta.ai.currency', 'USD');
    }
}
