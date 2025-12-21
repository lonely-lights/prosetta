<?php

namespace LonelyLights\Prosetta;

use Illuminate\Support\ServiceProvider;
use LonelyLights\Prosetta\Console\Commands\ExportCommand;
use LonelyLights\Prosetta\Console\Commands\InstallCommand;
use LonelyLights\Prosetta\Console\Commands\ReviewCommand;
use LonelyLights\Prosetta\Console\Commands\StatsCommand;
use LonelyLights\Prosetta\Console\Commands\SyncCommand;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Services\FileExporter;
use LonelyLights\Prosetta\Services\FileScanner;
use LonelyLights\Prosetta\Services\FileSynchronizer;
use LonelyLights\Prosetta\Services\KeyManager;
use LonelyLights\Prosetta\Services\LangKeyService;
use LonelyLights\Prosetta\Services\TranslationService;

/**
 * Prosetta Service Provider
 *
 * Registers all Prosetta services, facades, and configurations.
 *
 * @package LonelyLights\Prosetta
 */
class ProsettaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/prosetta.php', 'prosetta');

        // Register services
        $this->registerServices();

        // Register facade
        $this->registerFacade();

        // Register legacy services (for backward compatibility)
        $this->registerLegacyServices();
    }

    /**
     * Register core services.
     *
     * @return void
     */
    protected function registerServices(): void
    {
        // FileScanner
        $this->app->singleton(FileScanner::class, function () {
            return new FileScanner();
        });

        // KeyManager
        $this->app->singleton(KeyManager::class, function () {
            return new KeyManager();
        });

        // FileExporter
        $this->app->singleton(FileExporter::class, function () {
            return new FileExporter();
        });

        // TranslationService
        $this->app->singleton(TranslationService::class, function () {
            return new TranslationService();
        });

        // FileSynchronizer
        $this->app->singleton(FileSynchronizer::class, function () {
            return new FileSynchronizer();
        });
    }

    /**
     * Register the Prosetta facade.
     *
     * @return void
     */
    protected function registerFacade(): void
    {
        $this->app->singleton('prosetta', function ($app) {
            return new ProsettaManager(
                $app->make(FileScanner::class)
            );
        });

        $this->app->alias('prosetta', ProsettaManager::class);
    }

    /**
     * Register legacy services for backward compatibility.
     *
     * @return void
     * @deprecated These will be removed in v0.4
     */
    protected function registerLegacyServices(): void
    {
        // Active locales singleton (legacy)
        $this->app->singleton('activeLocales', function () {
            // Try database first, fall back to config
            try {
                return Locale::getActiveCodes();
            } catch (\Exception $e) {
                return config('prosetta.locales', ['en']);
            }
        });

        // LangKeyService (deprecated)
        $this->app->singleton(LangKeyService::class, function () {
            return new LangKeyService();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishConfig();
            $this->publishMigrations();
            $this->registerCommands();
        }

        // Load routes if enabled
        if (config('prosetta.routes.enabled', true)) {
            $this->loadRoutes();
        }

        // Load views
        $this->loadViews();
    }

    /**
     * Publish configuration file.
     *
     * @return void
     */
    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../config/prosetta.php' => config_path('prosetta.php'),
        ], 'prosetta-config');
    }

    /**
     * Publish migrations.
     *
     * @return void
     */
    protected function publishMigrations(): void
    {
        $timestamp = date('Y_m_d_His');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_prosetta_locales_table.php.stub'
                => database_path("migrations/{$timestamp}_01_create_prosetta_locales_table.php"),
            __DIR__ . '/../database/migrations/create_prosetta_files_table.php.stub'
                => database_path("migrations/{$timestamp}_02_create_prosetta_files_table.php"),
            __DIR__ . '/../database/migrations/create_prosetta_keys_table.php.stub'
                => database_path("migrations/{$timestamp}_03_create_prosetta_keys_table.php"),
            __DIR__ . '/../database/migrations/create_prosetta_translations_table.php.stub'
                => database_path("migrations/{$timestamp}_04_create_prosetta_translations_table.php"),
            __DIR__ . '/../database/migrations/create_prosetta_reviews_table.php.stub'
                => database_path("migrations/{$timestamp}_05_create_prosetta_reviews_table.php"),
        ], 'prosetta-migrations');
    }

    /**
     * Register artisan commands.
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        $this->commands([
            InstallCommand::class,
            SyncCommand::class,
            ExportCommand::class,
            StatsCommand::class,
            ReviewCommand::class,
        ]);
    }

    /**
     * Load package routes.
     *
     * @return void
     */
    protected function loadRoutes(): void
    {
        $routesPath = __DIR__ . '/../routes/prosetta.php';

        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }

    /**
     * Load package views.
     *
     * @return void
     */
    protected function loadViews(): void
    {
        $viewsPath = __DIR__ . '/../resources/views';

        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'prosetta');

            $this->publishes([
                $viewsPath => resource_path('views/vendor/prosetta'),
            ], 'prosetta-views');
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            'prosetta',
            'activeLocales',
            FileScanner::class,
            FileExporter::class,
            FileSynchronizer::class,
            KeyManager::class,
            TranslationService::class,
            LangKeyService::class,
            ProsettaManager::class,
        ];
    }
}
