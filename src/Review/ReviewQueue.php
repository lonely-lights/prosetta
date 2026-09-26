<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use LonelyLights\Prosetta\Automation\CycleFailures;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Settings;

final readonly class ReviewQueue {
    public function __construct(private CycleFailures $failures, private Rejections $rejections, private Reports $reports) {}

    /**
     * @param array{status?: string|list<string>, stale?: bool, namespace?: string, group?: string, origin?: string, issues?: bool, search?: string} $filters
     * @return LengthAwarePaginator<int, ReviewItem>
     */
    public function forLocale(string $locale, array $filters = [], int $perPage = 50): LengthAwarePaginator {
        $t = Settings::table('translations');
        $k = Settings::table('keys');
        $f = Settings::table('files');
        $model = Settings::model('translation');

        $statuses = array_map(
            fn ($status) => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
            (array) ($filters['status'] ?? [TranslationStatus::Draft, TranslationStatus::NeedsReview]),
        );

        $query = $model::query()->select("$t.*")
            ->join($k, "$k.id", '=', "$t.key_id")
            ->join($f, "$f.id", '=', "$k.file_id")
            ->where("$t.locale", $locale)
            ->whereNull("$k.obsolete_at")
            ->with('key.file');

        if (($filters['stale'] ?? false) === true) {
            $query->where(fn ($where) => $where
                ->whereIn("$t.status", $statuses)
                ->orWhere(fn ($stale) => $stale->whereNotNull("$t.approved_source_hash")->whereColumn("$t.approved_source_hash", '!=', "$k.source_hash")));
        } else {
            $query->whereIn("$t.status", $statuses);
        }

        foreach (['namespace' => "$f.namespace", 'group' => "$f.group", 'origin' => "$t.origin"] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        if (($filters['issues'] ?? false) === true) {
            $query->whereNotNull("$t.issues");
        }

        $search = $filters['search'] ?? null;

        if (is_string($search) && $search !== '') {
            $query->where(fn ($where) => $this->contains($where, "$k.source_value", $search)->orWhere(fn ($or) => $this->contains($or, "$t.value", $search)));
        }

        return $query->orderBy("$f.namespace")->orderBy("$f.group")->orderBy("$k.id")
            ->paginate($perPage)
            ->through(fn (Translation $translation) => ReviewItem::fromTranslation($translation));
    }

    /**
     * Current keys with nothing in this locale: no translation row, or no
     * approved value and no candidate other than a rejected one.
     *
     * @param array{namespace?: string, group?: string, search?: string} $filters
     * @return LengthAwarePaginator<int, MissingItem>
     */
    public function missing(string $locale, array $filters = [], int $perPage = 50): LengthAwarePaginator {
        $t = Settings::table('translations');
        $k = Settings::table('keys');
        $f = Settings::table('files');
        $model = Settings::model('key');

        $query = $model::query()->select("$k.*")
            ->join($f, "$f.id", '=', "$k.file_id")
            ->leftJoin($t, fn ($join) => $join->on("$t.key_id", '=', "$k.id")->where("$t.locale", '=', $locale))
            ->whereNull("$k.obsolete_at")
            ->where(fn ($where) => $where
                ->whereNull("$t.id")
                ->orWhere(fn ($empty) => $empty
                    ->whereNull("$t.approved_value")
                    ->where(fn ($candidate) => $candidate->whereNull("$t.value")->orWhere("$t.status", TranslationStatus::Rejected->value))))
            ->with('file');

        foreach (['namespace' => "$f.namespace", 'group' => "$f.group"] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        $search = $filters['search'] ?? null;

        if (is_string($search) && $search !== '') {
            $this->contains($query, "$k.source_value", $search);
        }

        return $query->orderBy("$f.namespace")->orderBy("$f.group")->orderBy("$k.id")
            ->paginate($perPage)
            ->through(fn (TranslationKey $key) => MissingItem::fromKey($key, $locale));
    }

    /**
     * What needs a person, across the viewer's languages.
     *
     * @param array{locale?: ?string, reason?: ?string, namespace?: ?string, group?: ?string, search?: ?string} $filters
     * @return LengthAwarePaginator<int, QueueItem>
     */
    public function for(Viewer $viewer, array $filters = [], int $page = 1, int $perPage = 50): LengthAwarePaginator {
        $items = $this->all($viewer, $filters);

        return new Paginator(array_slice($items, ($page - 1) * $perPage, $perPage), count($items), $perPage, $page);
    }

    /** @param array{locale?: ?string, reason?: ?string, namespace?: ?string, group?: ?string, search?: ?string} $filters */
    public function count(Viewer $viewer, array $filters = []): int {
        return count($this->all($viewer, $filters));
    }

    /**
     * @param array{locale?: ?string, reason?: ?string, namespace?: ?string, group?: ?string, search?: ?string} $filters
     * @return list<QueueItem>
     */
    public function all(Viewer $viewer, array $filters = []): array {
        $locales = array_values(array_filter($viewer->locales(), fn (string $code) => ($filters['locale'] ?? null) === null || $code === $filters['locale']));
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');
        $keys = $keyModel::query()->with('file')->whereNull('obsolete_at')->orderBy('id')->get()
            ->filter(fn (TranslationKey $key) => (($filters['namespace'] ?? null) === null || $key->file->namespace === $filters['namespace'])
                && (($filters['group'] ?? null) === null || $key->file->group === $filters['group']))
            ->sortBy(fn (TranslationKey $key) => [$key->file->namespace, $key->file->group, $key->getKey()])
            ->values();
        $failures = $this->failures->all();
        $rejections = $this->rejections->all();
        $search = mb_strtolower((string) ($filters['search'] ?? ''));
        $items = [];

        foreach ($locales as $locale) {
            $translations = $translationModel::query()->where('locale', $locale)->whereIn('key_id', $keys->modelKeys())->get()->keyBy('key_id');
            $reports = $this->reports->counts($locale);

            foreach ($keys as $key) {
                $translation = $translations->get($key->getKey());
                $reason = Status::of($key, $translation, $locale, $failures, $rejections);

                if (! in_array($reason, Status::NEEDS_PERSON, true) || (($filters['reason'] ?? null) !== null && $reason !== $filters['reason'])) {
                    continue;
                }

                $item = QueueItem::from($key, $translation, $locale, $reason, $reports[$key->getKey().':'.$locale] ?? 0);

                if ($search !== '' && ! str_contains(mb_strtolower(implode("\n", [$item->keyRef, $item->source, (string) $item->candidate, (string) $item->approved])), $search)) {
                    continue;
                }

                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Case-insensitive "contains" that treats % and _ in the search as literal
     * characters. Works the same on Postgres, MySQL and SQLite.
     */
    private function contains(mixed $query, string $column, string $search): mixed {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));

        return $query->whereRaw("lower($column) like ? escape '!'", ['%'.$escaped.'%']);
    }
}
