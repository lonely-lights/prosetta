<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Support\WorkState;

/** One row of a review queue, ready to become Inertia props. */
final readonly class ReviewItem {
    /** @param list<array{code: string, severity: string, message: string}> $issues */
    public function __construct(
        public int $translationId,
        public string $keyRef,
        public string $locale,
        public string $source,
        public ?string $candidate,
        public ?string $approved,
        public string $status,
        public string $origin,
        public bool $stale,
        public array $issues,
        public ?string $context,
        public ?int $maxLength,
        public ?string $aiModel,
        public ?string $aiProvider,
    ) {}

    public static function fromTranslation(Translation $translation): self {
        $key = $translation->key;
        $candidateStale = $translation->value !== null && $translation->source_hash !== $key->source_hash;

        return new self(
            (int) $translation->getKey(),
            $key->ref()->toString(),
            $translation->locale,
            $key->source_value,
            $translation->value,
            $translation->approved_value,
            $translation->status->value,
            $translation->origin->value,
            WorkState::isStale($key, $translation) || $candidateStale,
            $translation->issues ?? [],
            $key->context,
            $key->max_length,
            $translation->ai_model,
            $translation->ai_provider,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
