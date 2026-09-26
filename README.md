# Prosetta

[![Tests](https://github.com/lonely-lights/prosetta/actions/workflows/tests.yml/badge.svg)](https://github.com/lonely-lights/prosetta/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/lonely-lights/prosetta.svg)](https://packagist.org/packages/lonely-lights/prosetta)
[![License](https://img.shields.io/packagist/l/lonely-lights/prosetta.svg)](LICENSE)

Translate a Laravel app with AI, and keep people in charge of what goes live.

You write your app's text in one language, in the lang files you already have. Prosetta drafts every other language with an AI model you choose, lets reviewers approve, edit or reject each draft, and writes the approved text back into your lang files.

- **Your source files stay yours.** Prosetta reads your English (or whichever source language you use) and never writes to it.
- **Any AI provider.** You plug in a small driver class; Prosetta ships no AI client of its own.
- **Checked drafts.** Every draft is checked for broken placeholders, plurals and HTML, and gets one retry with the problems explained.
- **Per-language review.** Decide who may translate or review each language, with one callback.
- **Runs on its own.** An optional background cycle keeps every language current as your English changes, and stops cleanly when a provider is down or a budget runs out.
- **Headless.** There is no built-in admin screen. Your app calls Prosetta's services, so the review UI fits your app.

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- A queue worker, for translating in the background (optional for small runs)

## Install

```bash
composer require lonely-lights/prosetta
php artisan prosetta:install
php artisan migrate
```

`prosetta:install` publishes `config/prosetta.php` and Prosetta's migrations. See [Installation](docs/installation.md) for what they create and for apps that already have a locales table.

## Quick start

**1. Add a language to translate into.** Each target language is a row in `prosetta_locales`. Its code must match the lang folder name exactly (`es`, `zh-CN`, `en_GB`):

```php
use LonelyLights\Prosetta\Models\Locale;

Locale::create([
    'locale_initials' => 'es',
    'english_name' => 'Spanish',
    'native_name' => 'Español',
    'translated' => true,
]);
```

**2. Plug in an AI driver.** Write a class that implements `LonelyLights\Prosetta\Contracts\TranslationDriver` and bind it in a service provider. [AI drivers](docs/ai-drivers.md) has a complete example using [laravel/ai](https://github.com/laravel/ai).

```php
$this->app->bind(TranslationDriver::class, MyTranslationDriver::class);
```

**3. Say who may review.** Without this, Prosetta allows everything in your `local` environment and nothing anywhere else:

```php
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Facades\Prosetta;

Prosetta::authorizeUsing(fn ($user, Ability $ability, ?string $locale): bool => $user->isAdmin());
```

**4. Translate.**

```bash
php artisan prosetta:sync                   # read your source lang files
php artisan prosetta:translate --sync       # draft every missing string
php artisan prosetta:review es              # see what's waiting in Spanish
php artisan prosetta:review es --approve-clean
php artisan prosetta:export                 # write lang/es/*.php
```

Commit the new lang files like any other change. From here, [the workflow guide](docs/workflow.md) explains each step, and [background mode](docs/background-mode.md) shows how to let a scheduled cycle do it for you.

## Documentation

| Guide | Covers |
|---|---|
| [Installation](docs/installation.md) | Tables, languages, upgrading an existing locales table |
| [The workflow](docs/workflow.md) | Sync, translate, review, export; every command; access control |
| [AI drivers](docs/ai-drivers.md) | Writing a driver, reporting provider errors, testing with the fake driver |
| [Background mode](docs/background-mode.md) | The automatic cycle, language settings, glossaries, spelling variants like en_GB |
| [Resilience and costs](docs/resilience.md) | Outages, circuits, token budgets, prices and cost reports |
| [Building a review UI](docs/review-ui.md) | Queries and actions for your own admin screens |
| [Translating database content](docs/database-content.md) | Translating model fields, not just lang files |
| [Member reports](docs/member-reports.md) | Letting members flag a bad translation |
| [Reviewing in production](docs/production-review.md) | Approving on the live site and pulling it back into your lang files |

## Testing

```bash
composer test
```

To test your own integration without calling an AI, bind `LonelyLights\Prosetta\Testing\FakeTranslationDriver`. See [AI drivers](docs/ai-drivers.md#testing-without-an-ai).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md). To report a security problem, follow [SECURITY.md](SECURITY.md) rather than opening a public issue.

## License

MIT. See [LICENSE](LICENSE).
