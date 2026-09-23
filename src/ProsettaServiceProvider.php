<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Console\CircuitCommand;
use LonelyLights\Prosetta\Console\ExportCommand;
use LonelyLights\Prosetta\Console\InstallCommand;
use LonelyLights\Prosetta\Console\RenameCommand;
use LonelyLights\Prosetta\Console\ResumeCommand;
use LonelyLights\Prosetta\Console\ReviewCommand;
use LonelyLights\Prosetta\Console\StatsCommand;
use LonelyLights\Prosetta\Console\SyncCommand;
use LonelyLights\Prosetta\Console\TranslateCommand;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;
use LonelyLights\Prosetta\Resilience\LogResilienceEvents;

final class ProsettaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta');

        $this->app->singleton(Authorizer::class);
        $this->app->singleton(ProsettaManager::class);
        $this->app->bind(LocaleSource::class, fn (Application $app) => $app->make((string) config('prosetta.locales.source', DatabaseLocaleSource::class)));

        $driver = config('prosetta.ai.driver');

        if (is_string($driver) && $driver !== '') {
            $this->app->bindIf(TranslationDriver::class, $driver);
        }
    }

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/prosetta.php' => config_path('prosetta.php')], 'prosetta-config');

            // publishesMigrations() on a directory publishes it recursively (see
            // VendorPublishCommand::moveManagedFiles(), listContents(..., deep: true)),
            // so the workflow tag lists its four files individually. That way the
            // locales migration below (its own subdirectory) is never pulled in.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations/2026_09_22_000200_create_prosetta_files_table.php' => database_path('migrations/2026_09_22_000200_create_prosetta_files_table.php'),
                __DIR__.'/../database/migrations/2026_09_22_000300_create_prosetta_keys_table.php' => database_path('migrations/2026_09_22_000300_create_prosetta_keys_table.php'),
                __DIR__.'/../database/migrations/2026_09_22_000400_create_prosetta_translations_table.php' => database_path('migrations/2026_09_22_000400_create_prosetta_translations_table.php'),
                __DIR__.'/../database/migrations/2026_09_22_000500_create_prosetta_reviews_table.php' => database_path('migrations/2026_09_22_000500_create_prosetta_reviews_table.php'),
                __DIR__.'/../database/migrations/2026_09_23_000100_add_automation_columns.php' => database_path('migrations/2026_09_23_000100_add_automation_columns.php'),
            ], 'prosetta-migrations');

            $this->publishesMigrations([__DIR__.'/../database/migrations/locales' => database_path('migrations')], 'prosetta-locales-migration');
            $this->commands([
                InstallCommand::class, SyncCommand::class, TranslateCommand::class, ReviewCommand::class,
                ExportCommand::class, RenameCommand::class, StatsCommand::class,
                ResumeCommand::class, CircuitCommand::class,
            ]);
        }

        foreach (Ability::cases() as $ability) {
            Gate::define($ability->gate(), fn (Authenticatable $user, ?string $locale = null): bool => $this->app->make(Authorizer::class)->allows($user, $ability, $locale));
        }

        RateLimiter::for('prosetta-ai', fn (): Limit => Limit::perMinute(max(1, (int) config('prosetta.queue.rate_per_minute', 60))));

        Event::subscribe(LogResilienceEvents::class);

        $every = config('prosetta.resilience.resume_every');

        if ($every !== null && (int) $every > 0) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($every): void {
                $schedule->command('prosetta:resume')->cron('*/'.max(1, min(59, (int) $every)).' * * * *')->withoutOverlapping();
            });
        }
    }
}
