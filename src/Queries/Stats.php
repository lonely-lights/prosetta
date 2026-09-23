<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use Illuminate\Database\Eloquent\Collection;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;

final readonly class Stats {
    private const array EMPTY = ['keys' => 0, 'approved' => 0, 'drafts' => 0, 'needs_review' => 0, 'stale' => 0, 'missing' => 0, 'issues' => 0, 'tokens' => 0];

    public function __construct(private LocaleSource $locales) {}

    /** @return array<string, array<string, array{keys: int, approved: int, drafts: int, needs_review: int, stale: int, missing: int, issues: int, tokens: int}>> */
    public function summary(?string $locale = null): array {
        $translationModel = Settings::model('translation');
        $keys = $this->currentKeys();
        $summary = [];

        foreach ($this->targetCodes($locale) as $code) {
            $translations = $translationModel::query()->where('locale', $code)->get()->keyBy('key_id');

            foreach ($keys as $key) {
                /** @var TranslationKey $key */
                $namespace = $key->file->namespace;
                $summary[$code][$namespace] ??= self::EMPTY;
                $row = &$summary[$code][$namespace];
                $translation = $translations->get($key->getKey());
                $row['keys']++;

                if (WorkState::isMissing($key, $translation)) {
                    $row['missing']++;
                }

                if (WorkState::isStale($key, $translation)) {
                    $row['stale']++;
                }

                if ($translation !== null) {
                    if ($translation->approved_value !== null && ! WorkState::isStale($key, $translation)) {
                        $row['approved']++;
                    }

                    if ($translation->status === TranslationStatus::Draft) {
                        $row['drafts']++;
                    }

                    if ($translation->status === TranslationStatus::NeedsReview) {
                        $row['needs_review']++;
                    }

                    if ($translation->hasBlockingIssues()) {
                        $row['issues']++;
                    }

                    $row['tokens'] += (int) $translation->input_tokens + (int) $translation->output_tokens;
                }

                unset($row);
            }
        }

        return $summary;
    }

    /**
     * Key-and-locale pairs that still need someone: missing, stale, awaiting
     * review or broken. Each pair counts once, however many of those apply.
     *
     * @param list<string>|null $namespaces
     */
    public function outstanding(?array $namespaces = null): int {
        $translationModel = Settings::model('translation');
        $keys = $this->currentKeys($namespaces);
        $total = 0;

        foreach ($this->targetCodes() as $code) {
            $translations = $translationModel::query()->where('locale', $code)->get()->keyBy('key_id');

            foreach ($keys as $key) {
                /** @var TranslationKey $key */
                if ($this->isOutstanding($key, $translations->get($key->getKey()))) {
                    $total++;
                }
            }
        }

        return $total;
    }

    private function isOutstanding(TranslationKey $key, ?Translation $translation): bool {
        return WorkState::isMissing($key, $translation)
            || WorkState::isStale($key, $translation)
            || ($translation !== null && (
                in_array($translation->status, [TranslationStatus::Draft, TranslationStatus::NeedsReview], true)
                || $translation->hasBlockingIssues()
            ));
    }

    /** @return list<string> */
    private function targetCodes(?string $locale = null): array {
        return array_values(array_filter(
            array_map(fn (LocaleDescriptor $descriptor) => $descriptor->code, $this->locales->targets()),
            fn (string $code) => $locale === null || $code === $locale,
        ));
    }

    /**
     * @param list<string>|null $namespaces
     * @return Collection<int, TranslationKey>
     */
    private function currentKeys(?array $namespaces = null): Collection {
        $keyModel = Settings::model('key');

        return $keyModel::query()->with('file')->whereNull('obsolete_at')->orderBy('id')->get()
            ->filter(fn (TranslationKey $key) => $namespaces === null || in_array($key->file->namespace, $namespaces, true))
            ->values();
    }
}
