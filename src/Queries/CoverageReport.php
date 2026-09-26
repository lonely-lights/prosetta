<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

/** Coverage per language and the automation's health, for an overview page. */
final readonly class CoverageReport {
    /**
     * @param list<array{code: string, name: string, nativeName: string, mode: string, keys: int, approved: int, draft: int, flagged: int, pending: int, stale: int, missing: int, held: int, tokensThisMonth: int, costThisMonth: float, unpricedTokensThisMonth: int}> $languages
     * @param array<string, mixed>|null $lastReport
     * @param list<array{name: string, state: string, reason: ?string, until: ?int}> $circuits
     * @param array<string, array{used: int, limit: ?int}> $budget
     * @param list<string> $problems
     */
    public function __construct(
        public array $languages,
        public ?int $lastCycleAt,
        public ?array $lastReport,
        public array $circuits,
        public array $budget,
        public array $problems,
        public bool $editable,
        /** ISO 4217 code the costs are in. */
        public string $currency = 'USD',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
