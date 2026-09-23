<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

final readonly class TranslationItem {
    /** @param list<string> $placeholders */
    public function __construct(
        public string $id,
        public string $keyRef,
        public string $source,
        public ?string $context = null,
        public ?int $maxLength = null,
        public array $placeholders = [],
        public ?string $previous = null,
        public ?string $previousSource = null,
    ) {}
}
