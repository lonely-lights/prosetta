<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

final class InstallCommand extends Command {
    protected $signature = 'prosetta:install';

    protected $description = "Publish Prosetta's config and migrations.";

    public function handle(): int {
        $this->call('vendor:publish', ['--tag' => 'prosetta-config']);
        $this->call('vendor:publish', ['--tag' => 'prosetta-migrations']);

        if (Schema::hasTable(Settings::table('locales'))) {
            $this->components->info('The prosetta_locales table already exists, so its migration was left alone.');
        } else {
            $this->call('vendor:publish', ['--tag' => 'prosetta-locales-migration']);
        }

        $this->components->info('Next: migrate, bind a TranslationDriver, register Prosetta::authorizeUsing(), then run prosetta:sync.');

        return self::SUCCESS;
    }
}
