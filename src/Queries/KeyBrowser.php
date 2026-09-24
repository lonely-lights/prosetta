<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use LonelyLights\Prosetta\Automation\CycleFailures;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Models\TranslationReview;
use LonelyLights\Prosetta\Review\KeyCell;
use LonelyLights\Prosetta\Review\KeyDetail;
use LonelyLights\Prosetta\Review\KeyRow;
use LonelyLights\Prosetta\Review\Status;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Every current key with its English and a cell per language the viewer can
 * see: for a matrix, a file-by-file editor, or search results.
 */
final readonly class KeyBrowser {
    public const int SEARCH_LIMIT = 500;

    public function __construct(private CycleFailures $failures, private Rejections $rejections) {}

    /**
     * @param array{namespace?: ?string, group?: ?string, locale?: ?string, status?: ?string, search?: ?string} $filters
     * @return LengthAwarePaginator<int, KeyRow>
     */
    public function for(Viewer $viewer, array $filters = [], int $page = 1, int $perPage = 50): LengthAwarePaginator {
        $rows = $this->rows($viewer, $filters);

        if (($filters['search'] ?? '') !== '') {
            $rows = array_slice($rows, 0, self::SEARCH_LIMIT);
        }

        return new Paginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);
    }

    /** @param array{namespace?: ?string, group?: ?string, locale?: ?string, status?: ?string, search?: ?string} $filters */
    public function matchCount(Viewer $viewer, array $filters = []): int {
        return count($this->rows($viewer, $filters));
    }

    /** @return list<array{namespace: string, group: string, keys: int, needsWork: int}> */
    public function files(Viewer $viewer): array {
        $files = [];

        foreach ($this->rows($viewer, []) as $row) {
            $file = $files[$row->namespace.'::'.$row->group] ??= ['namespace' => $row->namespace, 'group' => $row->group, 'keys' => 0, 'needsWork' => 0];
            $file['keys']++;

            if (collect($row->cells)->contains(fn (KeyCell $cell) => in_array($cell->status, [...Status::NEEDS_PERSON, 'missing'], true))) {
                $file['needsWork']++;
            }

            $files[$row->namespace.'::'.$row->group] = $file;
        }

        return array_values($files);
    }

    public function key(Viewer $viewer, int $keyId): ?KeyDetail {
        $keyModel = Settings::model('key');
        /** @var TranslationKey|null $key */
        $key = $keyModel::query()->with('file')->whereKey($keyId)->whereNull('obsolete_at')->first();

        if ($key === null) {
            return null;
        }

        $row = $this->row($key, $viewer->locales(), $this->translationsFor($viewer->locales(), collect([$key])), $this->failures->all(), $this->rejections->all());
        $history = [];

        foreach ($viewer->locales() as $locale) {
            $translationId = $row->cells[$locale]->translationId;
            $reviewModel = Settings::model('review');
            $history[$locale] = $translationId === null ? [] : $reviewModel::query()->where('translation_id', $translationId)->orderBy('id')->get()
                ->map(fn (TranslationReview $review) => [
                    'action' => $review->action->value, 'reviewer' => $review->reviewer_id, 'previous' => $review->previous_value,
                    'new' => $review->new_value, 'notes' => $review->notes, 'at' => $review->created_at?->toIso8601String() ?? '',
                ])->values()->all();
        }

        return new KeyDetail($row, $history);
    }

    /**
     * @param array{namespace?: ?string, group?: ?string, locale?: ?string, status?: ?string, search?: ?string} $filters
     * @return list<KeyRow>
     */
    private function rows(Viewer $viewer, array $filters): array {
        $locales = array_values(array_filter($viewer->locales(), fn (string $code) => ($filters['locale'] ?? null) === null || $code === $filters['locale']));
        $keyModel = Settings::model('key');
        $keys = $keyModel::query()->with('file')->whereNull('obsolete_at')->orderBy('id')->get()
            ->filter(fn (TranslationKey $key) => (($filters['namespace'] ?? null) === null || $key->file->namespace === $filters['namespace'])
                && (($filters['group'] ?? null) === null || $key->file->group === $filters['group']))
            ->sortBy(fn (TranslationKey $key) => [$key->file->namespace, $key->file->group, $key->getKey()])
            ->values();
        $translations = $this->translationsFor($locales, $keys);
        $failures = $this->failures->all();
        $rejections = $this->rejections->all();
        $search = mb_strtolower((string) ($filters['search'] ?? ''));
        $rows = [];

        foreach ($keys as $key) {
            $row = $this->row($key, $locales, $translations, $failures, $rejections);

            if (($filters['status'] ?? null) !== null && ! collect($row->cells)->contains(fn (KeyCell $cell) => $cell->status === $filters['status'])) {
                continue;
            }

            if ($search !== '') {
                $text = mb_strtolower(implode("\n", [$row->keyRef, $row->source, ...array_map(fn (KeyCell $cell) => (string) $cell->value, $row->cells)]));

                if (! str_contains($text, $search)) {
                    continue;
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<string> $locales
     * @param array<string, Collection<int, Translation>> $translations locale => translations keyed by key id
     * @param array<string, array<string, array{hash: string, count: int}>> $failures
     * @param array<string, array<string, array{hash: string, count: int}>> $rejections
     */
    private function row(TranslationKey $key, array $locales, array $translations, array $failures, array $rejections): KeyRow {
        $cells = [];

        foreach ($locales as $locale) {
            $translation = $translations[$locale]->get($key->getKey());
            $cells[$locale] = KeyCell::from($translation, Status::of($key, $translation, $locale, $failures, $rejections));
        }

        return KeyRow::from($key, $cells);
    }

    /**
     * @param list<string> $locales
     * @param \Illuminate\Support\Collection<int, TranslationKey> $keys
     * @return array<string, Collection<int, Translation>>
     */
    private function translationsFor(array $locales, \Illuminate\Support\Collection $keys): array {
        $model = Settings::model('translation');
        $ids = $keys->map(fn (TranslationKey $key) => $key->getKey())->all();
        $byLocale = [];

        foreach ($locales as $locale) {
            $byLocale[$locale] = $model::query()->where('locale', $locale)->whereIn('key_id', $ids)->get()->keyBy('key_id');
        }

        return $byLocale;
    }
}
