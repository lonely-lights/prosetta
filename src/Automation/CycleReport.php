<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

/** What one cycle did: counts, the refs that need a human, the files written and the tokens spent. */
final readonly class CycleReport {
    /**
     * @param list<string> $flagged "{locale} {ref}" drafts from this cycle that carry any issue
     * @param list<string> $files lang files the export wrote
     */
    public function __construct(
        public bool $skipped = false,
        public string $reason = '',
        public int $drafted = 0,
        public int $updated = 0,
        public int $confirmed = 0,
        public int $approved = 0,
        public array $flagged = [],
        public array $files = [],
        public int $tokens = 0,
        public ?string $batchId = null,
    ) {}

    public static function skip(string $reason): self {
        return new self(skipped: true, reason: $reason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'skipped' => $this->skipped, 'reason' => $this->reason,
            'drafted' => $this->drafted, 'updated' => $this->updated, 'confirmed' => $this->confirmed, 'approved' => $this->approved,
            'flagged' => $this->flagged, 'files' => $this->files, 'tokens' => $this->tokens, 'batch_id' => $this->batchId,
        ];
    }
}
