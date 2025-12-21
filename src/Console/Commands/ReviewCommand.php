<?php

namespace LonelyLights\Prosetta\Console\Commands;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;

/**
 * Prosetta Review Command
 *
 * Lists translations that need attention.
 *
 * @package LonelyLights\Prosetta\Console\Commands
 */
class ReviewCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prosetta:review
        {locale : The locale to review (e.g., "en", "es")}
        {--needs-review : Show only translations needing review}
        {--missing : Show only missing translations}
        {--rejected : Show only rejected translations}
        {--file= : Filter by file path}
        {--limit=50 : Maximum number of items to show}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List translations that need attention';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $locale = $this->argument('locale');
        $needsReview = $this->option('needs-review');
        $missing = $this->option('missing');
        $rejected = $this->option('rejected');
        $file = $this->option('file');
        $limit = (int) $this->option('limit');

        // Default to showing all if no filter specified
        if (!$needsReview && !$missing && !$rejected) {
            $needsReview = true;
            $missing = true;
            $rejected = true;
        }

        $this->info("Translation Review for: $locale");
        $this->newLine();

        $hasItems = false;

        if ($needsReview && $this->showNeedsReview($locale, $file, $limit)) {
            $hasItems = true;
        }

        if ($missing && $this->showMissing($locale, $file, $limit)) {
            $hasItems = true;
        }

        if ($rejected && $this->showRejected($locale, $file, $limit)) {
            $hasItems = true;
        }

        if (!$hasItems) {
            $this->info('No items need attention!');
        }

        return self::SUCCESS;
    }

    /**
     * Show translations that need review.
     *
     * @param string $locale
     * @param string|null $file
     * @param int $limit
     * @return bool
     */
    protected function showNeedsReview(string $locale, ?string $file, int $limit): bool
    {
        $query = Translation::query()
            ->needsReview()
            ->where('locale', $locale)
            ->with('key.file');

        if ($file) {
            $query->whereHas('key.file', fn($q) => $q->where('path', $file));
        }

        $translations = $query->limit($limit)->get();

        if ($translations->isEmpty()) {
            return false;
        }

        $this->line('<comment>Needs Review:</comment>');

        $rows = [];
        foreach ($translations as $translation) {
            $fullKey = $translation->key->file->path . '.' . $translation->key->key;
            $rows[] = [
                $fullKey,
                $this->truncate($translation->value, 40),
                $translation->source,
                $translation->confidence ? round($translation->confidence * 100) . '%' : '-',
            ];
        }

        $this->table(['Key', 'Value', 'Source', 'Confidence'], $rows);
        $this->newLine();

        return true;
    }

    /**
     * Show missing translations.
     *
     * @param string $locale
     * @param string|null $file
     * @param int $limit
     * @return bool
     */
    protected function showMissing(string $locale, ?string $file, int $limit): bool
    {
        $query = TranslationKey::query()
            ->missingTranslation($locale)
            ->with('file');

        if ($file) {
            $query->whereHas('file', fn($q) => $q->where('path', $file));
        }

        $keys = $query->limit($limit)->get();

        if ($keys->isEmpty()) {
            return false;
        }

        $this->line('<comment>Missing Translations:</comment>');

        $rows = [];
        foreach ($keys as $key) {
            $fullKey = $key->file->path . '.' . $key->key;
            $rows[] = [
                $fullKey,
                $this->truncate($key->description ?? '-', 40),
                $key->file->category ?? '-',
            ];
        }

        $this->table(['Key', 'Description', 'Category'], $rows);
        $this->newLine();

        return true;
    }

    /**
     * Show rejected translations.
     *
     * @param string $locale
     * @param string|null $file
     * @param int $limit
     * @return bool
     */
    protected function showRejected(string $locale, ?string $file, int $limit): bool
    {
        $query = Translation::query()
            ->status(Translation::STATUS_REJECTED)
            ->where('locale', $locale)
            ->with(['key.file', 'reviews' => fn($q) => $q->latest()->limit(1)]);

        if ($file) {
            $query->whereHas('key.file', fn($q) => $q->where('path', $file));
        }

        $translations = $query->limit($limit)->get();

        if ($translations->isEmpty()) {
            return false;
        }

        $this->line('<comment>Rejected Translations:</comment>');

        $rows = [];
        foreach ($translations as $translation) {
            $fullKey = $translation->key->file->path . '.' . $translation->key->key;
            $latestReview = $translation->reviews->first();
            $rows[] = [
                $fullKey,
                $this->truncate($translation->value, 30),
                $this->truncate($latestReview?->notes ?? '-', 30),
            ];
        }

        $this->table(['Key', 'Value', 'Rejection Note'], $rows);
        $this->newLine();

        return true;
    }

    /**
     * Truncate a string for display.
     *
     * @param string $string
     * @param int $length
     * @return string
     */
    protected function truncate(string $string, int $length): string
    {
        if (strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length - 3) . '...';
    }
}
