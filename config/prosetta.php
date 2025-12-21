<?php

return [

    # TODO: Integrate Settings into Prosetta

    /*
    |--------------------------------------------------------------------------
    | Active Locales
    |--------------------------------------------------------------------------
    |
    | This section defines the locales that are currently active in the
    | application. These locales will be used to generate language files
    | and to determine which language files are loaded by the application.
    |
    | Note: Default locale is managed in config/app.php
    |
    | If a locale array is managed elsewhere, please direct it using the
    | following format. In this example, an array named 'all_locales' is
    | managed in config/app.php:
    |
    | 'locales' => config('app.all_locales'),
    |
    | This will allow the locale array to be managed in one place, while
    | still allowing Prosetta to access it. Configuration files are loaded
    | in the order they are listed in config/app.php, so the locale array
    | must be listed before the Prosetta configuration file.
    |
    */

    'locales' => [
        'en',
        'es',
        'fr',
    ],


    /*
    |--------------------------------------------------------------------------
    | Visibility Control
    |--------------------------------------------------------------------------
    |
    | This section defines the columns and their corresponding values that control
    | the visibility of language file entries. 'columns' should list the attribute
    | names of the model that determine visibility. 'ignore_values' lists the values
    | of these attributes that indicate an entry should be ignored.
    |
    */

    'visibility' => [
        'columns' => [
            'visibility',
        ],
        'ignore_values' => [
            'private'
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Affixation Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control the affixation process in Prosetta's language
    | file management. 'affixationType' specifies the character used to
    | separate a key from its prefix or suffix. 'affixationDefault' determines
    | whether the affix is added as a prefix or suffix by default.
    |
    */

    'affixationType' => '.',
    'affixationDefault' => 'prefix',


    /*
    |--------------------------------------------------------------------------
    | Key Mapping
    |--------------------------------------------------------------------------
    |
    | These settings control the mapping process in Prosetta's language file
    | management. Changing these will enable you to change how the keys display
    | in the language files. 'only' will only display the key. 'prefix' will
    | display the key as a prefix to the value. 'suffix' will display the key
    | as a suffix to the value. 'column' will map to the model's column with
    | the same name as the key. 'string' will map to the string value.
    |
    */

    'only' => 'only',
    'prefix' => 'prefix',
    'suffix' => 'suffix',
    'column' => 'column',
    'string' => 'string',

    /*
    |--------------------------------------------------------------------------
    | Prosetta Table Names
    |--------------------------------------------------------------------------
    |
    | This section defines the table names used by Prosetta. These names are
    | used to determine the table names for the Prosetta queue and the Prosetta
    | locale tables.
    |
    */

    'tableNames' => [
        'queue' => 'prosetta_queue',
        'locales' => 'prosetta_locales',
        'files' => 'prosetta_files',
        'keys' => 'prosetta_keys',
        'translations' => 'prosetta_translations',
        'reviews' => 'prosetta_reviews',
    ],


    /*
    |--------------------------------------------------------------------------
    | Prosetta Model Classes
    |--------------------------------------------------------------------------
    |
    | These settings allow you to specify custom model classes for Prosetta.
    | This is useful if you want to extend the default models or use your
    | own models that implement the required functionality.
    |
    | Set to null to use Prosetta's default models.
    |
    */

    'models' => [
        'locale' => null, // e.g., App\Models\Prosetta\Locale::class
        'file' => null,
        'key' => null,
        'translation' => null,
        'review' => null,
    ],


    /*
    |--------------------------------------------------------------------------
    | Prosetta Key Pattern
    |--------------------------------------------------------------------------
    |
    | This value defines the regular expression pattern used for validating
    | keys in Prosetta's language file management. This pattern ensures that
    | keys conform to a specific format, thereby ensuring consistency and
    | preventing potential issues with invalid key characters.
    |
    */

    'keyPattern' => '^[a-zA-Z0-9-._]+$',


    /*
    |--------------------------------------------------------------------------
    | Handling of Other Languages
    |--------------------------------------------------------------------------
    |
    | This section defines how Prosetta handles languages other than the
    | current language when updating language files. 'defaultBehavior'
    | determines the default behavior when updating language files.
    |
    | 'all-keep' will keep all entries in the language file.
    | 'all-clear' will clear all entries in the language file.
    | 'current-only' will only keep entries for the origin language.
    |
    */

    'defaultBehavior' => 'all-keep',


    /*
    |--------------------------------------------------------------------------
    | Default Logging Channel
    |--------------------------------------------------------------------------
    |
    | This value defines the default logging channel used by Prosetta. This
    | channel will be used when logging Prosetta's actions. Please ensure that
    | this channel is defined in config/logging.php.
    |
    */

    'logChannel' => 'prosetta',


    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control the routes for Prosetta's admin UI. You can
    | customize the prefix, middleware, and enable/disable the routes.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'prosetta',
        'middleware' => ['web', 'auth'],
    ],


    /*
    |--------------------------------------------------------------------------
    | UI Stack
    |--------------------------------------------------------------------------
    |
    | This setting defines the UI stack used by Prosetta. Options are:
    | - 'blade': Standard Blade + Alpine.js (works everywhere)
    | - 'livewire': Livewire components (requires livewire/livewire)
    | - 'filament': Filament resources (requires Filament admin panel)
    |
    | This is set automatically during installation but can be changed here.
    |
    */

    'stack' => 'blade',

];
