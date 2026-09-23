<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

use Illuminate\Support\Facades\DB;

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

    public static function forget(string $key): void {
        DB::table(Settings::table('state'))->where('key', $key)->delete();
    }
}
