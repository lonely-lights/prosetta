<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Prices a run before it spends anything: strings, source characters and
 * expected tokens per locale, from the locale's own AI history once it has
 * 50 drafts, otherwise from prosetta.budgets.estimate.
 */
final readonly class Estimator {
    private const int HISTORY_MINIMUM = 50;

    public function __construct(private Translator $translator) {}

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys
     * @return array<string, array{strings: int, chars: int, input: int, output: int, from_history: bool}>
     */
    public function estimate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false): array {
        $keyModel = Settings::model('key');
        $estimates = [];

        foreach ($this->translator->workList($locales, $namespaces, $keys, $force) as $locale => $files) {
            $ids = array_merge(...array_values($files));
            $lengths = $keyModel::query()->whereKey($ids)->get(['source_value'])
                ->map(fn ($key) => mb_strlen((string) $key->source_value))->all();
            $chars = (int) array_sum($lengths);
            $strings = count($ids);
            $history = $this->history($locale);

            $estimates[$locale] = $history !== null
                ? ['strings' => $strings, 'chars' => $chars, 'input' => (int) round($this->fromHistory($history, 'input', $lengths)), 'output' => (int) round($this->fromHistory($history, 'output', $lengths)), 'from_history' => true]
                : ['strings' => $strings, 'chars' => $chars, 'input' => $this->rate('input', $chars, $strings), 'output' => $this->rate('output', $chars, $strings), 'from_history' => false];
        }

        return $estimates;
    }

    /**
     * Scales the locale's real per-string cost (the history median) by the default model's
     * shape, so short-string history no longer inflates long strings: for a work string of
     * length c, medianPerItem × (defaultTokens(c) ÷ defaultTokens(L)), summed over the list.
     *
     * @param array{inPerItem: float, outPerItem: float, l: float} $history
     * @param list<int> $lengths
     */
    private function fromHistory(array $history, string $side, array $lengths): float {
        $perItem = $side === 'input' ? $history['inPerItem'] : $history['outPerItem'];
        $atL = $this->defaultTokens($side, $history['l']);
        $total = 0.0;

        foreach ($lengths as $length) {
            $total += $atL > 0.0 ? $perItem * ($this->defaultTokens($side, (float) $length) / $atL) : $perItem;
        }

        return $total;
    }

    /** @return array{inPerItem: float, outPerItem: float, l: float}|null medians of this locale's AI drafts: tokens per string and source length */
    private function history(string $locale): ?array {
        $translationModel = Settings::model('translation');
        $rows = $translationModel::query()->with('key:id,source_value')
            ->where('locale', $locale)->where('origin', TranslationOrigin::Ai)->whereNotNull('input_tokens')
            ->get(['key_id', 'input_tokens', 'output_tokens']);

        if ($rows->count() < self::HISTORY_MINIMUM) {
            return null;
        }

        return [
            'inPerItem' => $this->median($rows->pluck('input_tokens')->map(fn ($v) => (int) $v)->all()),
            'outPerItem' => $this->median($rows->pluck('output_tokens')->map(fn ($v) => (int) $v)->all()),
            'l' => $this->median($rows->map(fn ($row) => mb_strlen((string) $row->key?->source_value))->all()),
        ];
    }

    /** @param list<int> $values */
    private function median(array $values): float {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $mid = intdiv($count, 2);

        return $count % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : (float) $values[$mid];
    }

    private function defaultTokens(string $side, float $length): float {
        $rates = (array) config('prosetta.budgets.estimate', []);
        $defaultPerItem = $side === 'input' ? 12 : 8;

        return (float) ($rates[$side.'_per_char'] ?? 0.3) * $length + (float) ($rates[$side.'_per_item'] ?? $defaultPerItem);
    }

    private function rate(string $side, int $chars, int $strings): int {
        $rates = (array) config('prosetta.budgets.estimate', []);
        $defaultPerItem = $side === 'input' ? 12 : 8;

        return (int) round((float) ($rates[$side.'_per_char'] ?? 0.3) * $chars + (float) ($rates[$side.'_per_item'] ?? $defaultPerItem) * $strings);
    }
}
