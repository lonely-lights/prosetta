<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Models\TranslationKey;

/** One key with its English and a cell for each language the viewer can see. */
final readonly class KeyRow {
    /** @param array<string, KeyCell> $cells locale => cell */
    public function __construct(
        public int $keyId,
        public string $keyRef,
        public string $namespace,
        public string $group,
        public string $key,
        public string $source,
        public ?string $context,
        public array $cells,
    ) {}

    /** @param array<string, KeyCell> $cells */
    public static function from(TranslationKey $key, array $cells): self {
        return new self((int) $key->getKey(), $key->ref()->toString(), $key->file->namespace, $key->file->group, $key->key, $key->source_value, $key->context, $cells);
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [...get_object_vars($this), 'cells' => array_map(fn (KeyCell $cell) => $cell->toArray(), $this->cells)];
    }
}
