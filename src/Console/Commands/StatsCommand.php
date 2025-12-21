<?php

namespace LonelyLights\Prosetta\Console\Commands;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Facades\Prosetta;

/**
 * Prosetta Stats Command
 *
 * Displays translation statistics for a locale.
 *
 * @package LonelyLights\Prosetta\Console\Commands
 */
class StatsCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prosetta:stats
        {locale? : The locale to show statistics for}
        {--by-file : Show statistics grouped by file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display translation statistics';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int {
        $locale = $this->argument('locale');
        $byFile = $this->option('by-file');

        if (!$locale) {
            return $this->showAllLocalesStats();
        }

        return $this->showLocaleStats($locale, $byFile);
    }

    /**
     * Show statistics for all locales.
     *
     * @return int
     */
    protected function showAllLocalesStats(): int {
        $locales = Prosetta::locales();

        if ($locales->isEmpty()) {
            $this->warn('No active locales found.');
            return self::SUCCESS;
        }

        $this->info('Translation Statistics');
        $this->newLine();

        $rows = [];
        foreach ($locales as $locale) {
            $localeCode = $locale->locale_initials ?? $locale->code ?? $locale->id;
            $stats = Prosetta::statistics($localeCode);

            $rows[] = [
                $localeCode,
                $locale->english_name ?? $locale->name ?? '-',
                $stats['total'],
                $stats['translated'],
                $stats['missing'],
                $stats['needs_review'],
                $stats['approved'],
                $this->formatPercentage($stats['completion_percentage']),
            ];
        }

        $this->table(
            ['Locale', 'Name', 'Total', 'Translated', 'Missing', 'Needs Review', 'Approved', 'Completion'],
            $rows
        );

        return self::SUCCESS;
    }

    /**
     * Show statistics for a specific locale.
     *
     * @param string $locale
     * @param bool $byFile
     * @return int
     */
    protected function showLocaleStats(string $locale, bool $byFile = false): int {
        $stats = Prosetta::statistics($locale);

        $this->info("Translation Statistics for: $locale");
        $this->newLine();

        // Summary statistics
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Keys', $stats['total']],
                ['Translated', $stats['translated']],
                ['Missing', $stats['missing']],
                ['Needs Review', $stats['needs_review']],
                ['Approved', $stats['approved']],
                ['Completion', $this->formatPercentage($stats['completion_percentage'])],
            ]
        );

        if ($byFile) {
            $this->newLine();
            $this->showFileStats($locale);
        }

        return self::SUCCESS;
    }

    /**
     * Show statistics grouped by file.
     *
     * @param string $locale
     * @return void
     */
    protected function showFileStats(string $locale): void {
        $this->line('<comment>Statistics by File:</comment>');
        $this->newLine();

        $files = Prosetta::files();

        if ($files->isEmpty()) {
            $this->line('  No translation files found.');
            return;
        }

        $rows = [];
        foreach ($files as $file) {
            $fileStats = $file->getStatistics($locale);
            $percentage = $fileStats['total_keys'] > 0
                ? round(($fileStats['approved'] / $fileStats['total_keys']) * 100, 1)
                : 0;

            $rows[] = [
                $file->path,
                $file->name,
                $fileStats['total_keys'],
                $fileStats['approved'],
                $fileStats['needs_review'],
                $fileStats['draft'],
                $this->formatPercentage($percentage),
            ];
        }

        $this->table(
            ['Path', 'Name', 'Keys', 'Approved', 'Needs Review', 'Draft', 'Progress'],
            $rows
        );
    }

    /**
     * Format a percentage for display.
     *
     * @param float $percentage
     * @return string
     */
    protected function formatPercentage(float $percentage): string {
        if ($percentage >= 100) {
            return "<info>$percentage%</info>";
        } elseif ($percentage >= 75) {
            return "<comment>$percentage%</comment>";
        } else {
            return "$percentage%";
        }
    }
}
