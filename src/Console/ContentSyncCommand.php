<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use LonelyLights\Prosetta\Content\ContentKeys;
use LonelyLights\Prosetta\Contracts\TranslatableContent;

/**
 * Brings every row of the given TranslatesContent models in line with its
 * keys, the same way a save does: for a table that had rows before its model
 * adopted the trait, or after writes that bypassed Eloquent. Safe to repeat.
 */
final class ContentSyncCommand extends Command {
    protected $signature = 'prosetta:content:sync {model?* : Model classes; defaults to prosetta.content.models}';

    protected $description = 'Give existing rows of translatable models their content keys';

    public function handle(ContentKeys $content): int {
        /** @var list<string> $classes */
        $classes = (array) $this->argument('model') ?: (array) config('prosetta.content.models', []);

        if ($classes === []) {
            $this->warn('No models given, and prosetta.content.models is empty.');

            return self::SUCCESS;
        }

        foreach ($classes as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, TranslatableContent::class)) {
                $this->error('['.(is_string($class) ? $class : get_debug_type($class)).'] is not a model implementing TranslatableContent.');

                return self::FAILURE;
            }
        }

        foreach ($classes as $class) {
            $count = 0;

            /** @var class-string<Model&TranslatableContent> $class */
            $class::query()->lazyById()->each(function (Model&TranslatableContent $model) use ($content, &$count): void {
                $content->sync($model);
                $count++;
            });

            $this->line("Synced $count $class records.");
        }

        return self::SUCCESS;
    }
}
