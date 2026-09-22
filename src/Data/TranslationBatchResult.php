<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/** Values keyed by item id, plus what the call cost. */
final readonly class TranslationBatchResult {
    /** @param array<string, string> $values */
    public function __construct(
        public array $values,
        public string $provider,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?string $invocationId = null,
    ) {}
}
