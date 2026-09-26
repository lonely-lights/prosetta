<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Contracts\PriceCatalogue;
use LonelyLights\Prosetta\Data\Cost;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Durable token usage: one row per successful provider call, so budgets
 * survive a cache clear and the estimator can read real per-string costs.
 */
final readonly class UsageLedger {
    public function __construct(private PriceCatalogue $prices) {}

    public function record(?string $runId, string $circuit, string $locale, int $input, int $output, ?string $model = null): void {
        DB::table(Settings::table('usage'))->insert([
            'run_id' => $runId,
            'circuit' => $circuit,
            'locale' => $locale,
            'input_tokens' => max(0, $input),
            'output_tokens' => max(0, $output),
            'model' => $model,
            'created_at' => now(),
        ]);
    }

    /** Sums input + output tokens, optionally scoped to a run, a created_at window (from inclusive, to exclusive), and/or a locale. */
    public function sum(?string $runId = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $locale = null): int {
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

        $query->when($locale !== null, fn ($query) => $query->where('locale', $locale));

        return (int) $query->sum('input_tokens') + (int) $query->sum('output_tokens');
    }

    /**
     * What the usage cost, priced per model by the PriceCatalogue, scoped like sum().
     * Tokens with no model or no price are counted in unpricedTokens rather than guessed.
     */
    public function cost(?string $runId = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $locale = null): Cost {
        $query = DB::table(Settings::table('usage'))
            ->when($runId !== null, fn ($query) => $query->where('run_id', $runId))
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<', $to))
            ->when($locale !== null, fn ($query) => $query->where('locale', $locale));

        $amount = 0.0;
        $unpriced = 0;

        foreach ($query->groupBy('model')->selectRaw('model, sum(input_tokens) as input, sum(output_tokens) as output')->get() as $row) {
            $input = (int) $row->input;
            $output = (int) $row->output;
            $price = $row->model === null ? null : $this->prices->price((string) $row->model);

            if ($price === null) {
                $unpriced += $input + $output;

                continue;
            }

            $amount += $price->of($input, $output);
        }

        return new Cost(round($amount, 6), $unpriced, $this->prices->currency());
    }
}
