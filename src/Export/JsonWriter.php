<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

use JsonException;
use LonelyLights\Prosetta\Exceptions\ProsettaException;

final readonly class JsonWriter {
    /** @param array<array-key, string> $values */
    public function render(array $values): string {
        try {
            return json_encode(
                $values,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (JsonException $e) {
            throw new ProsettaException("Could not encode translations as JSON: {$e->getMessage()}", 0, $e);
        }
    }
}
