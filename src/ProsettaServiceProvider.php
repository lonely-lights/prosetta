<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Console\ExportCommand;
use LonelyLights\Prosetta\Console\InstallCommand;
use LonelyLights\Prosetta\Console\RenameCommand;
use LonelyLights\Prosetta\Console\ReviewCommand;
use LonelyLights\Prosetta\Console\StatsCommand;
use LonelyLights\Prosetta\Console\SyncCommand;
use LonelyLights\Prosetta\Console\TranslateCommand;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;

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
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'prosetta-migrations');
            $this->commands([
                InstallCommand::class, SyncCommand::class, TranslateCommand::class, ReviewCommand::class,
                ExportCommand::class, RenameCommand::class, StatsCommand::class,
            ]);
        }

        foreach (Ability::cases() as $ability) {
            Gate::define($ability->gate(), fn (Authenticatable $user, ?string $locale = null): bool => $this->app->make(Authorizer::class)->allows($user, $ability, $locale));
        }

        RateLimiter::for('prosetta-ai', fn (): Limit => Limit::perMinute(max(1, (int) config('prosetta.queue.rate_per_minute', 60))));
    }
}
