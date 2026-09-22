<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Sync;

final class SyncReport {
    /** @var list<string> */
    public array $added = [];

    /** @var list<string> */
    public array $changed = [];

    /** @var list<string> */
    public array $restored = [];

    /** @var list<string> */
    public array $obsoleted = [];

    public int $imported = 0;

    /** @var list<string> "{locale} {ref}" */
    public array $handEdits = [];

    /** Missing + stale + awaiting review + broken, across targets. Set only in check mode. */
    public ?int $outstanding = null;

    public function hasChanges(): bool {
        return $this->added !== [] || $this->changed !== [] || $this->restored !== [] || $this->obsoleted !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'added' => $this->added, 'changed' => $this->changed, 'restored' => $this->restored,
            'obsoleted' => $this->obsoleted, 'imported' => $this->imported, 'hand_edits' => $this->handEdits,
            'outstanding' => $this->outstanding,
        ];
    }
}
