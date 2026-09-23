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

    /** @var list<string> "{locale} {ref}" the provider refused to translate */
    public array $refused = [];

    /** Why the run stopped early (outage, halt or budget), or null when it ran to the end. */
    public ?string $stopped = null;

    public int $skipped = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public function merge(self $other): void {
        $this->drafted = [...$this->drafted, ...$other->drafted];
        $this->withIssues = [...$this->withIssues, ...$other->withIssues];
        $this->failed = [...$this->failed, ...$other->failed];
        $this->refused = [...$this->refused, ...$other->refused];
        $this->stopped ??= $other->stopped;
        $this->skipped += $other->skipped;
        $this->inputTokens += $other->inputTokens;
        $this->outputTokens += $other->outputTokens;
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'drafted' => $this->drafted, 'with_issues' => $this->withIssues, 'failed' => $this->failed,
            'refused' => $this->refused, 'stopped' => $this->stopped,
            'skipped' => $this->skipped, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens,
        ];
    }
}
