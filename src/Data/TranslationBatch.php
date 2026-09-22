<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/**
 * Everything a driver needs for one call. variantOf is set when the target
 * is a regional variant of the source (en_GB of en): adapt spelling and
 * usage, and return the source unchanged when nothing differs. feedback
 * lists the guard's complaints per item id on a retry.
 */
final readonly class TranslationBatch {
    /**
     * @param list<TranslationItem> $items
     * @param array<string, list<string>> $feedback
     */
    public function __construct(
        public string $sourceLocale,
        public LocaleDescriptor $target,
        public ?string $variantOf,
        public ?string $model,
        public array $items,
        public array $feedback = [],
    ) {}

    /** @param list<TranslationItem> $items */
    public function withItems(array $items): self {
        return new self($this->sourceLocale, $this->target, $this->variantOf, $this->model, $items, $this->feedback);
    }

    /** @param array<string, list<string>> $feedback */
    public function withFeedback(array $feedback): self {
        return new self($this->sourceLocale, $this->target, $this->variantOf, $this->model, $this->items, $feedback);
    }
}
