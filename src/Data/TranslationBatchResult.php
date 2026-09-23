<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/** Values keyed by item id, what the call cost, and any items the provider refused. */
final readonly class TranslationBatchResult {
    /**
     * @param array<string, string> $values
     * @param array<string, string> $refused item id => the provider's reason
     */
    public function __construct(
        public array $values,
        public string $provider,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?string $invocationId = null,
        public array $refused = [],
    ) {}
}
