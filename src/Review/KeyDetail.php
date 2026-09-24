<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

/** One key's row plus its review history in each of the viewer's languages. */
final readonly class KeyDetail {
    /** @param array<string, list<array{action: string, reviewer: ?string, previous: ?string, new: ?string, notes: ?string, at: string}>> $history */
    public function __construct(public KeyRow $row, public array $history) {}

    /** @return array{row: array<string, mixed>, history: array<string, list<array<string, ?string>>>} */
    public function toArray(): array {
        return ['row' => $this->row->toArray(), 'history' => $this->history];
    }
}
