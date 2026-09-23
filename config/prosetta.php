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

    /*
    | How Prosetta treats a provider that fails. Backoff spaces out retries of
    | one job; the circuit stops every job from calling a provider that keeps
    | failing, tests it with one call after a cooldown, and suspends work after
    | outage_timeout seconds of downtime. A halt (bad key, no credits) trips the
    | circuit for halt_hold seconds (null = until prosetta:circuit reset).
    | Suspended work is requeued by prosetta:resume, scheduled every
    | resume_every minutes when set (an every-N-minutes cron, so clamped to
    | 1-59; null = the host schedules prosetta:resume itself). Use a shared
    | cache store (Redis) with more than one worker, so every worker sees the
    | same circuit.
    */
    'resilience' => [
        'cache_store' => env('PROSETTA_CACHE_STORE'),
        'backoff' => [30, 60, 120, 300, 600, 900],
        'jitter' => 0.2,
        'circuit' => [
            'failure_threshold' => 5,
            'cooldown' => 300,
            'cooldown_multiplier' => 2,
            'max_cooldown' => 3600,
        ],
        'outage_timeout' => 21600,
        'halt_hold' => null,
        'unknown_errors' => 'transient',
        'resume_every' => null,
    ],

    /*
    | Token budgets (input + output, as drivers report them); null = no limit.
    | per_run stops only that run; daily and monthly stop every run and
    | suspend it until the period changes. estimate holds the rates
    | prosetta:translate --estimate uses before a locale has 50 AI drafts.
    */
    'budgets' => [
        'per_run' => null,
        'daily' => null,
        'monthly' => null,
        'estimate' => ['input_per_char' => 0.3, 'output_per_char' => 0.3, 'input_per_item' => 12, 'output_per_item' => 8],
    ],

    'log_channel' => null,

];
