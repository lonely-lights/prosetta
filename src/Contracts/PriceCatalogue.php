<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Contracts;

use LonelyLights\Prosetta\Data\ModelPrice;

/**
 * What each AI model costs, so token usage can be shown as money. The
 * default reads prosetta.ai.prices; bind your own to read a database table.
 */
interface PriceCatalogue {
    /** The price per million tokens, or null when the model isn't priced. */
    public function price(string $model): ?ModelPrice;

    /** ISO 4217 code every price is in, e.g. "USD". */
    public function currency(): string;
}
