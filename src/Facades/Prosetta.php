<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Facades;

use Illuminate\Support\Facades\Facade;
use LonelyLights\Prosetta\ProsettaManager;

/**
 * @method static \LonelyLights\Prosetta\Sync\SyncReport sync(list<string>|null $namespaces = null, bool $check = false, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static \Illuminate\Bus\Batch|\LonelyLights\Prosetta\Translation\TranslateReport translate(list<string> $locales = [], list<string> $namespaces = [], list<string> $keys = [], bool $force = false, bool $queue = true, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \LonelyLights\Prosetta\Review\ReviewItem> reviewQueue(string $locale, array<string, mixed> $filters = [], int $perPage = 50)
 * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \LonelyLights\Prosetta\Review\MissingItem> missing(string $locale, array<string, mixed> $filters = [], int $perPage = 50)
 * @method static \LonelyLights\Prosetta\Models\Translation write(string $keyRef, string $locale, string $value, ?\Illuminate\Contracts\Auth\Authenticatable $by, ?string $notes = null, bool $approve = false)
 * @method static \LonelyLights\Prosetta\Models\Translation edit(int $translationId, string $value, ?\Illuminate\Contracts\Auth\Authenticatable $by, ?string $notes = null, bool $approve = false)
 * @method static \LonelyLights\Prosetta\Review\ApproveReport approve(int|list<int> $translationIds, ?\Illuminate\Contracts\Auth\Authenticatable $by, ?string $notes = null)
 * @method static \LonelyLights\Prosetta\Review\ApproveReport approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static \LonelyLights\Prosetta\Models\Translation reject(int $translationId, ?\Illuminate\Contracts\Auth\Authenticatable $by, ?string $notes = null)
 * @method static \LonelyLights\Prosetta\Export\ExportReport export(list<string> $locales = [], list<string> $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false, ?\Illuminate\Contracts\Auth\Authenticatable $by = null, bool $force = false)
 * @method static \LonelyLights\Prosetta\Models\TranslationKey rename(string $from, string $to, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static array<string, array<string, array<string, int>>> stats(?string $locale = null)
 * @method static \LonelyLights\Prosetta\Models\TranslationKey|null lookup(string $keyRef)
 * @method static void authorizeUsing(\Closure $callback)
 *
 * @see ProsettaManager
 */
final class Prosetta extends Facade {
    protected static function getFacadeAccessor(): string {
        return ProsettaManager::class;
    }
}
