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
            $chars = (int) $keyModel::query()->whereKey($ids)->get(['source_value'])->sum(fn ($key) => mb_strlen((string) $key->source_value));
            $strings = count($ids);
            $history = $this->history($locale);

            $estimates[$locale] = $history !== null
                ? ['strings' => $strings, 'chars' => $chars, 'input' => (int) round($history['input'] * $chars), 'output' => (int) round($history['output'] * $chars), 'from_history' => true]
                : ['strings' => $strings, 'chars' => $chars, 'input' => $this->rate('input', $chars, $strings), 'output' => $this->rate('output', $chars, $strings), 'from_history' => false];
        }

        return $estimates;
    }

    /** @return array{input: float, output: float}|null tokens per source character, from this locale's AI drafts */
    private function history(string $locale): ?array {
        $translationModel = Settings::model('translation');
        $rows = $translationModel::query()->with('key:id,source_value')
            ->where('locale', $locale)->where('origin', TranslationOrigin::Ai)->whereNotNull('input_tokens')
            ->get(['key_id', 'input_tokens', 'output_tokens']);

        if ($rows->count() < self::HISTORY_MINIMUM) {
            return null;
        }

        $chars = max(1, (int) $rows->sum(fn ($row) => mb_strlen((string) $row->key?->source_value)));

        return ['input' => $rows->sum('input_tokens') / $chars, 'output' => $rows->sum('output_tokens') / $chars];
    }

    private function rate(string $side, int $chars, int $strings): int {
        $rates = (array) config('prosetta.budgets.estimate', []);
        $defaultPerItem = $side === 'input' ? 12 : 8;

        return (int) round((float) ($rates[$side.'_per_char'] ?? 0.3) * $chars + (float) ($rates[$side.'_per_item'] ?? $defaultPerItem) * $strings);
    }
}
