<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;

final class ProsettaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta');

        $this->app->bind(LocaleSource::class, fn (Application $app) => $app->make((string) config('prosetta.locales.source', DatabaseLocaleSource::class)));
    }

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/prosetta.php' => config_path('prosetta.php')], 'prosetta-config');
        }
    }
}
