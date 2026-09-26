<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use LonelyLights\Prosetta\Console\Concerns\ReadsInput;
use LonelyLights\Prosetta\Content\ContentKeys;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Review\ReviewService;

/** Carries a lang file's translations into content keys, e.g. when a catalogue moves from lang files to a table. */
final class ContentImportCommand extends Command {
    use ReadsInput;

    protected $signature = 'prosetta:content:import {folder : The content folder, e.g. pillars} {locale} {path : A PHP lang file returning an array}';

    protected $description = 'Import a lang file as approved translations of matching content keys';

    public function handle(ContentKeys $content, ReviewService $review): int {
        $path = $this->text('path');
        $values = is_file($path) ? include $path : null;

        if (! is_array($values)) {
            $this->error("[$path] is not a PHP file returning an array.");

            return self::FAILURE;
        }

        $folder = $this->text('folder');
        $locale = $this->text('locale');
        $keys = $content->file($folder)->keys()->whereNull('obsolete_at')->get()->keyBy(fn (TranslationKey $key) => $key->key);
        [$imported, $unmatched, $flagged] = [0, 0, 0];

        foreach (Arr::dot($values) as $name => $value) {
            if (! $keys->has((string) $name) || ! is_string($value)) {
                $unmatched++;

                continue;
            }

            try {
                $review->write(ContentKeys::NAMESPACE."::$folder.$name", $locale, $value, null, 'Imported from '.basename($path).'.', approve: true);
                $imported++;
            } catch (ProsettaException) {
                $review->write(ContentKeys::NAMESPACE."::$folder.$name", $locale, $value, null, 'Imported from '.basename($path).'; needs review.');
                $flagged++;
            }
        }

        $this->line("Imported $imported, unmatched $unmatched, flagged $flagged.");

        return self::SUCCESS;
    }
}
