<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

final readonly class JsonWriter {
    /** @param array<array-key, string> $values */
    public function render(array $values): string {
        return json_encode(
            $values,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
