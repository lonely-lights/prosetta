<?php

namespace LonelyLights\Prosetta\Console\Commands;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Facades\Prosetta;

/**
 * Prosetta Sync Command
 *
 * Syncs language files from disk to the database.
 *
 * @package LonelyLights\Prosetta\Console\Commands
 */
class SyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prosetta:sync
        {locale? : The locale to sync (e.g., "en", "es")}
        {--all : Sync all available locales}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync language files from disk to the database';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $locale = $this->argument('locale');
        $syncAll = $this->option('all');

        if (!$locale && !$syncAll) {
            $this->error('Please specify a locale or use --all to sync all locales.');
            return Command::FAILURE;
        }

        if ($syncAll) {
            return $this->syncAll();
        }

        return $this->syncLocale($locale);
    }

    /**
     * Sync a specific locale.
     *
     * @param string $locale
     * @return int
     */
    protected function syncLocale(string $locale): int
    {
        $this->info("Syncing locale: {$locale}...");

        $report = Prosetta::sync($locale);

        $this->displayReport($report);

        if (!empty($report['errors'])) {
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info("Sync completed for locale: {$locale}");

        return Command::SUCCESS;
    }

    /**
     * Sync all available locales.
     *
     * @return int
     */
    protected function syncAll(): int
    {
        $this->info('Syncing all locales...');
        $this->newLine();

        $reports = Prosetta::syncAll();
        $hasErrors = false;

        foreach ($reports as $locale => $report) {
            $this->line("<comment>Locale: {$locale}</comment>");
            $this->displayReport($report);
            $this->newLine();

            if (!empty($report['errors'])) {
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            $this->error('Sync completed with errors. Check the output above.');
            return Command::FAILURE;
        }

        $this->info('All locales synced successfully!');

        return Command::SUCCESS;
    }

    /**
     * Display the sync report.
     *
     * @param array $report
     * @return void
     */
    protected function displayReport(array $report): void
    {
        // New files
        if (!empty($report['new_files'])) {
            $this->line('  <info>New files:</info>');
            foreach ($report['new_files'] as $file) {
                $this->line("    + {$file}");
            }
        }

        // New keys
        if (!empty($report['new_keys'])) {
            $this->line('  <info>New keys:</info> ' . count($report['new_keys']));
            if ($this->getOutput()->isVerbose()) {
                foreach ($report['new_keys'] as $key) {
                    $this->line("    + {$key}");
                }
            }
        }

        // Updated keys
        if (!empty($report['updated_keys'])) {
            $this->line('  <info>Updated keys:</info> ' . count($report['updated_keys']));
            if ($this->getOutput()->isVerbose()) {
                foreach ($report['updated_keys'] as $key) {
                    $this->line("    ~ {$key}");
                }
            }
        }

        // Errors
        if (!empty($report['errors'])) {
            $this->line('  <error>Errors:</error>');
            foreach ($report['errors'] as $error) {
                $this->line("    ! {$error}");
            }
        }

        // Summary
        if (empty($report['new_files']) && empty($report['new_keys']) && empty($report['updated_keys']) && empty($report['errors'])) {
            $this->line('  <comment>No changes detected.</comment>');
        }
    }
}
