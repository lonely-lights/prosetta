<?php

return [

    /*
    | The locale whose lang files are canonical. Prosetta reads these files and
    | never writes them; every other locale follows them.
    */
    'source_locale' => env('PROSETTA_SOURCE_LOCALE', 'en'),

    /*
    | Lang roots. '*' is lang_path() (PHP groups plus {locale}.json). Other
    | namespaces are discovered from every loadTranslationsFrom() hint the
    | translator knows about; include/exclude filter them by name.
    */
    'namespaces' => [
        'discover' => true,
        'include' => ['*'],
        'exclude' => [],
    ],

    /*
    | Explicit namespace => path overrides, e.g.
    | 'identity' => app_path('Modules/Identity/Lang'). '*' overrides lang_path().
    */
    'paths' => [],

    /*
    | Paths Prosetta skips when reading and refuses when writing. Relative
    | entries resolve against base_path(); globs are allowed.
    */
    'exclude_paths' => ['lang/vendor', 'vendor'],

    'export' => [
        'include_drafts' => env('PROSETTA_EXPORT_DRAFTS', false),
    ],

    'review' => [
        'allow_self_approval' => true,
    ],

    'ai' => [
        'driver' => null,
        'model' => env('PROSETTA_AI_MODEL'),
        'models' => [],
        'batch' => 25,
        'retries_on_issues' => 1,
    ],

    'queue' => [
        'connection' => env('PROSETTA_QUEUE_CONNECTION'),
        'name' => env('PROSETTA_QUEUE', 'translations'),
        'rate_per_minute' => env('PROSETTA_AI_RATE', 60),
    ],

    'locales' => [
        'source' => \LonelyLights\Prosetta\Locales\DatabaseLocaleSource::class,
        'fallback' => ['en'],
    ],

    'models' => [
        'locale' => \LonelyLights\Prosetta\Models\Locale::class,
        'file' => \LonelyLights\Prosetta\Models\TranslationFile::class,
        'key' => \LonelyLights\Prosetta\Models\TranslationKey::class,
        'translation' => \LonelyLights\Prosetta\Models\Translation::class,
        'review' => \LonelyLights\Prosetta\Models\TranslationReview::class,
    ],

    'table_names' => [
        'locales' => 'prosetta_locales',
        'files' => 'prosetta_files',
        'keys' => 'prosetta_keys',
        'translations' => 'prosetta_translations',
        'reviews' => 'prosetta_reviews',
    ],

    'log_channel' => null,

];
