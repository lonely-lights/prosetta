<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Models\TranslationKey;

/** A key with nothing to show in one locale: no approved value and no candidate worth reviewing. */
final readonly class MissingItem {
    /** @param list<string> $placeholders */
    public function __construct(
        public string $keyRef,
        public string $locale,
        public string $source,
        public ?string $context,
        public ?int $maxLength,
        public array $placeholders,
    ) {}

    public static function fromKey(TranslationKey $key, string $locale): self {
        return new self($key->ref()->toString(), $locale, $key->source_value, $key->context, $key->max_length, $key->placeholders ?? []);
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
