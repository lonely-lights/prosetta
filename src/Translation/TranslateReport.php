<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

final class TranslateReport {
    /** @var list<string> "{locale} {ref}" */
    public array $drafted = [];

    /** @var list<string> */
    public array $withIssues = [];

    /** @var list<string> */
    public array $failed = [];

    public int $skipped = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public function merge(self $other): void {
        $this->drafted = [...$this->drafted, ...$other->drafted];
        $this->withIssues = [...$this->withIssues, ...$other->withIssues];
        $this->failed = [...$this->failed, ...$other->failed];
        $this->skipped += $other->skipped;
        $this->inputTokens += $other->inputTokens;
        $this->outputTokens += $other->outputTokens;
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'drafted' => $this->drafted, 'with_issues' => $this->withIssues, 'failed' => $this->failed,
            'skipped' => $this->skipped, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens,
        ];
    }
}
