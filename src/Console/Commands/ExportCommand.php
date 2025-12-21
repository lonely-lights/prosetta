<?php

namespace LonelyLights\Prosetta\Console\Commands;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Facades\Prosetta;

/**
 * Prosetta Export Command
 *
 * Exports translations from the database to language files.
 *
 * @package LonelyLights\Prosetta\Console\Commands
 */
class ExportCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prosetta:export
        {locale? : The locale to export (e.g., "en", "es")}
        {--all : Export all locales}
        {--file= : Export only a specific file (e.g., "auth", "profile")}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export translations from the database to language files';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int {
        $locale = $this->argument('locale');
        $exportAll = $this->option('all');
        $file = $this->option('file');

        if (!$locale && !$exportAll) {
            $this->error('Please specify a locale or use --all to export all locales.');
            return self::FAILURE;
        }

        if ($exportAll) {
            return $this->exportAllLocales($file);
        }

        return $this->exportLocale($locale, $file);
    }

    /**
     * Export a specific locale.
     *
     * @param string $locale
     * @param string|null $file
     * @return int
     */
    protected function exportLocale(string $locale, ?string $file = null): int {
        if ($file) {
            return $this->exportSingleFile($locale, $file);
        }

        $this->info("Exporting all files for locale: $locale...");

        $results = Prosetta::exportAll($locale);
        $success = 0;
        $failed = 0;

        foreach ($results as $filePath => $result) {
            if ($result) {
                $this->line("  <info>+</info> $filePath");
                $success++;
            } else {
                $this->line("  <error>!</error> $filePath");
                $failed++;
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->error("Exported $success files, $failed failed.");
            return self::FAILURE;
        }

        $this->info("Successfully exported $success files.");

        return self::SUCCESS;
    }

    /**
     * Export a single file.
     *
     * @param string $locale
     * @param string $file
     * @return int
     */
    protected function exportSingleFile(string $locale, string $file): int {
        $this->info("Exporting $file for locale: $locale...");

        $result = Prosetta::export($locale, $file);

        if ($result) {
            $this->info("Successfully exported $file.");
            return self::SUCCESS;
        }

        $this->error("Failed to export $file. File may not exist in the database.");
        return self::FAILURE;
    }

    /**
     * Export all locales.
     *
     * @param string|null $file
     * @return int
     */
    protected function exportAllLocales(?string $file = null): int {
        $locales = Prosetta::locales();

        if ($locales->isEmpty()) {
            $this->error('No active locales found. Please configure locales first.');
            return self::FAILURE;
        }

        $this->info('Exporting translations for all active locales...');
        $this->newLine();

        $hasErrors = false;

        foreach ($locales as $locale) {
            $localeCode = $locale->locale_initials ?? $locale->code ?? $locale->id;
            $this->line("<comment>Locale: $localeCode</comment>");

            if ($file) {
                $result = Prosetta::export($localeCode, $file);
                if ($result) {
                    $this->line("  <info>+</info> $file");
                } else {
                    $this->line("  <error>!</error> $file");
                    $hasErrors = true;
                }
            } else {
                $results = Prosetta::exportAll($localeCode);
                foreach ($results as $filePath => $result) {
                    if ($result) {
                        $this->line("  <info>+</info> $filePath");
                    } else {
                        $this->line("  <error>!</error> $filePath");
                        $hasErrors = true;
                    }
                }
            }

            $this->newLine();
        }

        if ($hasErrors) {
            $this->error('Export completed with errors.');
            return self::FAILURE;
        }

        $this->info('All exports completed successfully!');

        return self::SUCCESS;
    }
}
