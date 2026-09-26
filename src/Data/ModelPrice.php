<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/** A model's price per million input and output tokens. */
final readonly class ModelPrice {
    public function __construct(
        public float $input,
        public float $output,
    ) {}

    public function of(int $inputTokens, int $outputTokens): float {
        return ($inputTokens * $this->input + $outputTokens * $this->output) / 1_000_000;
    }
}
