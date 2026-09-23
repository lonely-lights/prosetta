<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Settings;

final readonly class ReviewQueue {
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
            $query->where(fn ($where) => $where->where("$k.source_value", 'like', "%$search%")->orWhere("$t.value", 'like', "%$search%"));
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
            $query->where("$k.source_value", 'like', "%$search%");
        }

        return $query->orderBy("$f.namespace")->orderBy("$f.group")->orderBy("$k.id")
            ->paginate($perPage)
            ->through(fn (TranslationKey $key) => MissingItem::fromKey($key, $locale));
    }
}
