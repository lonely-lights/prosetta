<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command {
    protected $signature = 'prosetta:install';

    protected $description = "Publish Prosetta's config and migrations.";

    public function handle(): int {
        $this->call('vendor:publish', ['--tag' => 'prosetta-config']);
        $this->call('vendor:publish', ['--tag' => 'prosetta-migrations']);
        $this->components->info('Next: migrate, bind a TranslationDriver, register Prosetta::authorizeUsing(), then run prosetta:sync.');

        return self::SUCCESS;
    }
}
