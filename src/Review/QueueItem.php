<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Translation\SourceChange;

/** One thing a person should look at in the review queue, ready for Inertia props or JSON. */
final readonly class QueueItem {
    /** @param list<array{code: string, severity: string, message: string}> $issues */
    public function __construct(
        public ?int $translationId,
        public int $keyId,
        public string $keyRef,
        public string $namespace,
        public string $group,
        public string $locale,
        public string $reason,
        public string $source,
        public ?string $previousSource,
        public ?string $diff,
        public ?string $candidate,
        public ?string $approved,
        public array $issues,
        public bool $blocking,
        public bool $warnings,
        public ?string $origin,
        public ?string $updatedAt,
        public ?string $fingerprint,
    ) {}

    public static function from(TranslationKey $key, ?Translation $translation, string $locale, string $reason): self {
        $previous = $translation?->approved_source_value;
        $previous = $previous !== null && $previous !== $key->source_value ? $previous : null;
        $issues = $translation?->issues ?? [];

        return new self(
            $translation === null ? null : (int) $translation->getKey(),
            (int) $key->getKey(),
            $key->ref()->toString(),
            $key->file->namespace,
            $key->file->group,
            $locale,
            $reason,
            $key->source_value,
            $previous,
            $previous === null ? null : SourceChange::diff($previous, $key->source_value),
            $translation?->value,
            $translation?->approved_value,
            $issues,
            $translation?->hasBlockingIssues() ?? false,
            collect($issues)->contains(fn (array $issue) => ($issue['severity'] ?? '') === 'warning'),
            $translation?->origin->value,
            $translation?->updated_at?->toIso8601String(),
            $translation === null ? null : ReviewService::fingerprint($translation),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
