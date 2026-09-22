<?php

namespace LonelyLights\Prosetta\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\ProsettaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra {
    use RefreshDatabase;

    protected function getPackageProviders($app): array {
        return [ProsettaServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /** en is the source; es, ar and en_GB are targets; fr is neither offered nor translated. */
    protected function seedLocales(): void {
        foreach ([
            ['en', 'English', 'English', 'Latin', false, true, false, true, 1],
            ['es', 'Spanish', 'Español', 'Latin', false, true, false, false, 2],
            ['ar', 'Arabic', 'العربية', 'Arabic', true, true, false, false, 3],
            ['fr', 'French', 'Français', 'Latin', false, false, false, false, 6],
            ['en_GB', 'British English', 'English (United Kingdom)', 'Latin', false, false, true, false, 101],
        ] as [$code, $english, $native, $script, $rtl, $active, $translated, $default, $sort]) {
            Locale::query()->create([
                'locale_initials' => $code, 'english_name' => $english, 'native_name' => $native, 'script' => $script,
                'rtl' => $rtl, 'active' => $active, 'translated' => $translated, 'is_default' => $default, 'sort_order' => $sort,
            ]);
        }
    }

    protected function user(string $id = 'user-1'): GenericUser {
        return new GenericUser(['id' => $id]);
    }
}
