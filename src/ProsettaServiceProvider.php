<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Illuminate\Support\ServiceProvider;

final class ProsettaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta');
    }

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/prosetta.php' => config_path('prosetta.php')], 'prosetta-config');
        }
    }
}
