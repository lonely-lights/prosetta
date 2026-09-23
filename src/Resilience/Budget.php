<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Contracts\Events\Dispatcher;
use LonelyLights\Prosetta\Events\BudgetReached;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Token budgets per run, day and month, counted in the cache after each
 * call and checked before the next. A batch can overshoot by at most its own
 * tokens; that is the price of not estimating every call.
 */
final readonly class Budget {
    public function __construct(private Dispatcher $events) {}

    public function exhausted(?string $runId): ?string {
        foreach ($this->periods($runId) as $period => [$key]) {
            $limit = $this->limit($period);

            if ($limit !== null && $this->used($key) >= $limit) {
                return $period;
            }
        }

        return null;
    }

    public function record(?string $runId, int $tokens): void {
        if ($tokens <= 0) {
            return;
        }

        $cache = Settings::cache();

        foreach ($this->periods($runId) as $period => [$key, $ttl]) {
            $cache->add($key, 0, $ttl);
            $used = (int) $cache->increment($key, $tokens);
            $limit = $this->limit($period);

            if ($limit !== null && $used >= $limit && $cache->add("$key:reached", true, $ttl)) {
                $this->events->dispatch(new BudgetReached($period, $used, $limit));
            }
        }
    }

    /** @return array<string, array{used: int, limit: ?int}> */
    public function usage(?string $runId = null): array {
        $usage = [];

        foreach ($this->periods($runId) as $period => [$key]) {
            $usage[$period] = ['used' => $this->used($key), 'limit' => $this->limit($period)];
        }

        return $usage;
    }

    /**
     * Daily, then monthly, then per_run: exhausted() names the first one reached, so a run over
     * both a shared budget and its own is suspended (per_run alone isn't) and resumes later.
     *
     * @return array<string, array{0: string, 1: int}> period => [cache key, seconds to keep it]
     */
    private function periods(?string $runId): array {
        $now = now();
        $periods = [
            'daily' => ['prosetta:budget:day:'.$now->format('Y-m-d'), 2 * 86400],
            'monthly' => ['prosetta:budget:month:'.$now->format('Y-m'), 40 * 86400],
        ];

        if ($runId !== null) {
            $periods['per_run'] = ["prosetta:budget:run:$runId", 7 * 86400];
        }

        return $periods;
    }

    private function used(string $key): int {
        return (int) Settings::cache()->get($key, 0);
    }

    private function limit(string $period): ?int {
        $limit = config("prosetta.budgets.$period");

        return $limit === null ? null : max(0, (int) $limit);
    }
}
