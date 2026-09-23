<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;

final readonly class Stats {
    private const EMPTY = ['keys' => 0, 'approved' => 0, 'drafts' => 0, 'needs_review' => 0, 'stale' => 0, 'missing' => 0, 'issues' => 0, 'tokens' => 0];

    public function __construct(private LocaleSource $locales) {}

    /** @return array<string, array<string, array{keys: int, approved: int, drafts: int, needs_review: int, stale: int, missing: int, issues: int, tokens: int}>> */
    public function summary(?string $locale = null): array {
        $codes = array_values(array_filter(
            array_map(fn (LocaleDescriptor $descriptor) => $descriptor->code, $this->locales->targets()),
            fn (string $code) => $locale === null || $code === $locale,
        ));
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');
        $keys = $keyModel::query()->with('file')->whereNull('obsolete_at')->orderBy('id')->get();
        $summary = [];

        foreach ($codes as $code) {
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

    public function outstanding(): int {
        $total = 0;

        foreach ($this->summary() as $namespaces) {
            foreach ($namespaces as $row) {
                $total += $row['missing'] + $row['stale'] + $row['drafts'] + $row['needs_review'] + $row['issues'];
            }
        }

        return $total;
    }
}
