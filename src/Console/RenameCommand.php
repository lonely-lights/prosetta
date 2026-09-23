<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\ProsettaManager;
use Throwable;

final class RenameCommand extends Command {
    protected $signature = 'prosetta:rename {from : The old key reference} {to : The new key reference}';

    protected $description = 'Move translations from a renamed key to its new name (rename it in the source file and sync first).';

    /** @throws Throwable when a database transaction fails */
    public function handle(ProsettaManager $prosetta): int {
        try {
            $key = $prosetta->rename((string) $this->argument('from'), (string) $this->argument('to'));
        } catch (ProsettaException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Moved translations to {$key->ref()}.");

        return self::SUCCESS;
    }
}
