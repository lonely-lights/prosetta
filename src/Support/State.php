<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Small durable values (the cycle heartbeat, the running cycle's batch) that a cache clear can't lose. */
final readonly class State {
    public static function get(string $key, mixed $default = null): mixed {
        $value = DB::table(Settings::table('state'))->where('key', $key)->value('value');

        return $value === null ? $default : json_decode((string) $value, true);
    }

    public static function put(string $key, mixed $value): void {
        DB::table(Settings::table('state'))->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now()],
        );
    }

    /**
     * Reads, changes and writes one value under a row lock, so parallel queue
     * workers changing the same value never lose each other's writes. A null
     * result removes the value.
     *
     * @param Closure(mixed): mixed $change given the current value (or $default), returns the new one
     * @throws Throwable when the transaction fails
     */
    public static function update(string $key, Closure $change, mixed $default = null): void {
        $table = Settings::table('state');

        # Make Sure the Row Exists First, so There Is Something to Lock
        DB::table($table)->insertOrIgnore(['key' => $key, 'value' => null, 'updated_at' => now()]);

        DB::transaction(function () use ($table, $key, $change, $default): void {
            $stored = DB::table($table)->where('key', $key)->lockForUpdate()->value('value');
            $value = $change($stored === null ? $default : json_decode((string) $stored, true));

            if ($value === null) {
                DB::table($table)->where('key', $key)->delete();
            } else {
                DB::table($table)->where('key', $key)->update(['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        });
    }

    public static function forget(string $key): void {
        DB::table(Settings::table('state'))->where('key', $key)->delete();
    }
}
