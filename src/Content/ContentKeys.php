<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Content;

use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Keeps translatable model fields as content keys: one folder per model
 * under the "content" namespace, one key per record and field.
 */
final readonly class ContentKeys {
    public const string NAMESPACE = 'content';

    public function file(string $folder): TranslationFile {
        $model = Settings::model('file');

        /** @var TranslationFile */
        return $model::query()->firstOrCreate(
            ['namespace' => self::NAMESPACE, 'group' => $folder],
            ['format' => FileFormat::Database],
        );
    }
}
