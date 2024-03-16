<?php

namespace LonelyLights\Prosetta;

use Illuminate\Support\ServiceProvider;
use LonelyLights\Prosetta\Locale as ProsettaLocale;
use LonelyLights\Prosetta\Services\LangKeyService;

class ProsettaServiceProvider extends ServiceProvider {
    /**
     * Register services.
     */
    public function register(): void {

        # Installation Configuration
        $this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta.php');

        # Set Active Locales
        $this->app->singleton('activeLocales', function() {
            return ProsettaLocale::getActiveLocales();
        });

        # LangKey Service
        $this->app->singleton(LangKeyService::class, function () {
            return new LangKeyService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {
        if ($this->app->runningInConsole()) {

            # Config
            $this->publishes([
                __DIR__.'/../config/prosetta.php' => config_path('prosetta.php'),
            ], 'config');

            # Migrations
            $this->publishes([
                __DIR__ . '/../database/migrations/create_locales_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_locales_table.php'),
                __DIR__ . '/../database/migrations/create_prosetta_queue_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_prosetta_queue_table.php')
            ], 'migrations');
        }
    }
}
