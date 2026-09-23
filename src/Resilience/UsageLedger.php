<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Durable token usage: one row per successful provider call, so budgets
 * survive a cache clear and the estimator can read real per-string costs.
 */
final readonly class UsageLedger {
    public function record(?string $runId, string $circuit, string $locale, int $input, int $output): void {
        DB::table(Settings::table('usage'))->insert([
            'run_id' => $runId,
            'circuit' => $circuit,
            'locale' => $locale,
            'input_tokens' => max(0, $input),
            'output_tokens' => max(0, $output),
            'created_at' => now(),
        ]);
    }

    /** Sums input + output tokens, optionally scoped to a run and/or a created_at window (from inclusive, to exclusive). */
    public function sum(?string $runId = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): int {
        $query = DB::table(Settings::table('usage'));

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<', $to);
        }

        return (int) $query->sum('input_tokens') + (int) $query->sum('output_tokens');
    }
}
