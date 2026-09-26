# Installation

```bash
composer require lonely-lights/prosetta
php artisan prosetta:install
php artisan migrate
```

## What `prosetta:install` publishes

- `config/prosetta.php`, with a comment on every setting.
- Seven migrations for Prosetta's own tables:

  | Table | Holds |
  |---|---|
  | `prosetta_files` | Each lang file Prosetta knows (namespace and group) |
  | `prosetta_keys` | Each string in your source language |
  | `prosetta_translations` | Each string's translation per language, with its status |
  | `prosetta_reviews` | The review trail: who approved, edited or rejected what |
  | `prosetta_usage` | Tokens each AI call spent, and on which model |
  | `prosetta_state` | Small bits of state, such as the last background cycle |
  | `prosetta_reports` | Members' reports of bad translations |

  They are dated to the moment you publish, so they run after your own migrations.

- A migration for `prosetta_locales`, the list of languages, **only if that table doesn't exist yet**.

Queued translation uses Laravel's job batches, so your app also needs the `job_batches` table. If you don't have it:

```bash
php artisan make:queue-batches-table
php artisan migrate
```

## Adding languages

Each language you translate into is a row in `prosetta_locales`:

```php
use LonelyLights\Prosetta\Models\Locale;

Locale::create([
    'locale_initials' => 'ar',
    'english_name' => 'Arabic',
    'native_name' => 'العربية',
    'rtl' => true,
    'translated' => true,
]);
```

A few rules:

- **The code must match the lang folder name exactly**: `ar` for `lang/ar`, `zh-CN` for `lang/zh-CN`, `en_GB` for `lang/en_GB`. Codes can be up to 35 characters and can't be changed once created.
- **Prosetta maintains a language when `translated` or `active` is true.** Use `translated` for a language you're preparing but don't offer yet, and `active` for one your members can pick.
- **Your source language needs no row** (it's set by `prosetta.source_locale`, `en` by default). If it has one, Prosetta skips it: it never translates into the source.

The table has more columns for background mode (`auto_translate`, `style_note`, `glossary`, `replacements`). They're explained in [background mode](background-mode.md#language-settings).

### Without a locales table

If you'd rather list languages in config, set `prosetta.locales.source` to `LonelyLights\Prosetta\Locales\ConfigLocaleSource` and list the codes under `prosetta.locales.fallback`. Each language's name is then its code, and the per-language settings of background mode aren't available.

## If you already have a `prosetta_locales` table

Apps that used an earlier Prosetta keep their locales table; `prosetta:install` won't publish a second one. Add these columns yourself:

| Column | Type |
|---|---|
| `translated` | boolean, default `false` |
| `auto_translate` | boolean, default `false` |
| `style_note` | text, nullable |
| `glossary` | json, nullable |
| `replacements` | json, nullable |

and widen `locale_initials` to 35 characters.

## Where Prosetta looks for lang files

By default Prosetta reads:

- your app's `lang/` folder: PHP files per group (`lang/en/auth.php`) and JSON files (`lang/en.json`);
- every folder registered with `loadTranslationsFrom()`, such as a module's `Lang` folder, found automatically.

It never reads or writes `lang/vendor` or `vendor`. You can narrow which namespaces it reads, add paths by hand, or exclude more paths under `namespaces`, `paths` and `exclude_paths` in the config.

## Next

[The workflow](workflow.md) walks through translating your first language.
