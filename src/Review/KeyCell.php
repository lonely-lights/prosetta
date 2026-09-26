<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Models\Translation;

/** One key in one language, as a keys browser shows it. */
final readonly class KeyCell {
    /** @param list<array{code: string, severity: string, message: string}> $issues */
    public function __construct(
        public string $status,
        public ?string $value,
        public ?string $candidate,
        public ?string $approved,
        public array $issues,
        public ?int $translationId,
        public ?string $fingerprint,
    ) {}

    public static function from(?Translation $translation, string $status): self {
        return new self(
            $status,
            $translation->approved_value ?? $translation?->value,
            $translation?->value,
            $translation?->approved_value,
            $translation->issues ?? [],
            $translation === null ? null : (int) $translation->getKey(),
            $translation === null ? null : ReviewService::fingerprint($translation),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
