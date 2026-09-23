<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Contracts\Events\Dispatcher;
use LonelyLights\Prosetta\Events\BudgetReached;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Token budgets per run, day and month, summed from the durable usage
 * ledger and checked before the next call. A batch can overshoot by at most
 * its own tokens; that is the price of not estimating every call.
 */
final readonly class Budget {
    public function __construct(private Dispatcher $events, private UsageLedger $ledger) {}

    public function exhausted(?string $runId): ?string {
        foreach ($this->periods($runId) as $period => $bounds) {
            $limit = $this->limit($period);

            if ($limit !== null && $this->used($bounds) >= $limit) {
                return $period;
            }
        }

        return null;
    }

    /** $tokens is unused: the ledger row is the record; this only checks whether a period has just crossed its limit. */
    public function record(?string $runId, int $tokens): void {
        $cache = Settings::cacheStore();

        foreach ($this->periods($runId) as $period => $bounds) {
            $limit = $this->limit($period);

            if ($limit === null) {
                continue;
            }

            $used = $this->used($bounds);

            if ($used >= $limit && $cache->add("prosetta:budget:$period:{$bounds['key']}:reached", true, $bounds['ttl'])) {
                $this->events->dispatch(new BudgetReached($period, $used, $limit));
            }
        }
    }

    /** @return array<string, array{used: int, limit: ?int}> */
    public function usage(?string $runId = null): array {
        $usage = [];

        foreach ($this->periods($runId) as $period => $bounds) {
            $usage[$period] = ['used' => $this->used($bounds), 'limit' => $this->limit($period)];
        }

        return $usage;
    }

    /**
     * Daily, then monthly, then per_run: exhausted() names the first one reached, so a run over
     * both a shared budget and its own is suspended (per_run alone isn't) and resumes later.
     *
     * @return array<string, array{key: string, runId: ?string, from: ?\Carbon\CarbonInterface, ttl: int}>
     */
    private function periods(?string $runId): array {
        $now = now();
        $periods = [
            'daily' => ['key' => $now->format('Y-m-d'), 'runId' => null, 'from' => $now->copy()->startOfDay(), 'ttl' => 2 * 86400],
            'monthly' => ['key' => $now->format('Y-m'), 'runId' => null, 'from' => $now->copy()->startOfMonth(), 'ttl' => 40 * 86400],
        ];

        if ($runId !== null) {
            $periods['per_run'] = ['key' => $runId, 'runId' => $runId, 'from' => null, 'ttl' => 7 * 86400];
        }

        return $periods;
    }

    /** @param array{key: string, runId: ?string, from: ?\Carbon\CarbonInterface, ttl: int} $bounds */
    private function used(array $bounds): int {
        return $this->ledger->sum($bounds['runId'], $bounds['from']);
    }

    private function limit(string $period): ?int {
        $limit = config("prosetta.budgets.$period");

        return $limit === null ? null : max(0, (int) $limit);
    }
}
