# Prosetta

Translation workflow for Laravel. Your source-language lang files are the canonical keys. Prosetta drafts every other locale through an AI driver you supply, routes drafts through per-language human review, and writes approved text back to root and module lang folders. It's headless: your admin calls its services.

## How it works

1. **You edit the source locale's files** (`lang/en/*.php`, `lang/en.json`, `app/Modules/*/Lang/en/*.php`) by hand. Prosetta never writes them.
2. **`prosetta:sync`** reads them into keys. New keys become *missing* in every target locale. A changed English value makes existing translations *stale*. Removed keys become *obsolete*. Existing target files are imported as approved work; later hand edits to them are imported for review, never silently accepted.
3. **`prosetta:translate`** drafts missing and stale keys through your `TranslationDriver`. Every result is checked for placeholders (exact case), plural segments and HTML, gets one retry with feedback, and is saved as a draft with model, provider and tokens.
4. **Reviewers approve, edit or reject** per locale (`Prosetta::approve()`, `edit()`, `reject()`, or `prosetta:review`).
5. **`prosetta:export`** writes approved values (or drafts, if you choose) into each locale's files, in the source file's key order. A target file holding anything Prosetta didn't write (a hand edit not yet synced, a file never synced, a key the source doesn't have) is left alone and reported as a conflict, and the command exits 1. Run `prosetta:sync` to import it, or pass `--force` to overwrite.

## Install

```bash
composer require lonely-lights/prosetta
php artisan prosetta:install   # publishes config/prosetta.php and the migrations
php artisan migrate
```

`prosetta:install` publishes the four workflow tables (`prosetta_files`, `prosetta_keys`, `prosetta_translations`, `prosetta_reviews`). It publishes the `prosetta_locales` migration only if that table doesn't exist yet. Hosts upgrading from an earlier Prosetta keep their locales table; add a boolean `translated` column (default `false`) and widen `locale_initials` to 35 characters. Queued translation uses Laravel job batches, so the host needs the `job_batches` table (`php artisan make:queue-batches-table`).

Target locales are rows in `prosetta_locales` where `active` (offered to members) or `translated` (maintained, even if not offered) is true. Codes must match your lang folder names exactly (`zh-CN`, `en_GB`) and can't change once created.

## Bind an AI driver

Prosetta ships no AI client. Implement `LonelyLights\Prosetta\Contracts\TranslationDriver`. With [laravel/ai](https://github.com/laravel/ai) it looks like this:

```php
namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class Translator implements Agent, HasStructuredOutput {
    use Promptable;

    public function instructions(): string {
        return <<<'TEXT'
        You translate user-interface strings for a web application.
        Keep every placeholder that starts with a colon (:name, :Name, :NAME) exactly as written, including its case.
        Keep plural segments separated by | and any {0} or [2,*] range markers. Keep HTML tags unchanged.
        Respect max_length when given. When variant_of is set, only adapt spelling and usage for that region,
        and return the text unchanged when nothing differs. Fix every listed problem on a retry.
        Return exactly one translation per id.
        TEXT;
    }

    public function schema(JsonSchema $schema): array {
        return [
            'translations' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'value' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
```

```php
namespace App\Services\Translation;

use App\Ai\Agents\Translator;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Data\TranslationItem;

final readonly class LaravelAiTranslationDriver implements TranslationDriver {
    public function __construct(private Translator $agent) {}

    public function translate(TranslationBatch $batch): TranslationBatchResult {
        $response = $this->agent->prompt(json_encode([
            'from' => $batch->sourceLocale,
            'to' => ['code' => $batch->target->code, 'language' => $batch->target->englishName, 'script' => $batch->target->script],
            'variant_of' => $batch->variantOf,
            'items' => array_map(fn (TranslationItem $item) => [
                'id' => $item->id, 'text' => $item->source, 'context' => $item->context,
                'max_length' => $item->maxLength, 'previous_translation' => $item->previous,
                'problems_to_fix' => $batch->feedback[$item->id] ?? [],
            ], $batch->items),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), model: $batch->model);

        $rows = $response instanceof StructuredAgentResponse ? ($response->structured['translations'] ?? []) : [];

        return new TranslationBatchResult(
            values: array_column($rows, 'value', 'id'),
            provider: (string) ($response->meta->provider ?? 'unknown'),
            model: (string) ($response->meta->model ?? $batch->model ?? 'unknown'),
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
            invocationId: $response->invocationId,
        );
    }
}
```

Bind it in a service provider (or set `prosetta.ai.driver` to the class):

```php
$this->app->bind(\LonelyLights\Prosetta\Contracts\TranslationDriver::class, \App\Services\Translation\LaravelAiTranslationDriver::class);
```

Each translation stores its share of the call's tokens and the call's `ai_invocation_id`, so exact totals are one join away in any ledger keyed by laravel/ai's invocation id.

## Authorization

Decide access in one place. The callback receives the ability and, except for `Manage`, the locale:

```php
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Facades\Prosetta;

Prosetta::authorizeUsing(fn ($user, Ability $ability, ?string $locale): bool => match ($ability) {
    Ability::Manage => $user->can('translations.manage'),
    default => $user->can("translations.{$ability->value}.$locale"),
});
```

`Review` implies `Translate` for the same locale. With no callback, only the `local` environment is allowed. Prosetta also registers the gates `prosetta.translate`, `prosetta.review` and `prosetta.manage`, so `$user->can('prosetta.review', 'ar')` works in policies.

## Commands

| Command | What it does |
|---|---|
| `prosetta:sync [--namespace=*] [--check]` | Read source files, import target files. `--check` changes nothing and exits 1 when work is outstanding (use it in CI). |
| `prosetta:translate [--locale=*] [--namespace=*] [--key=*] [--force] [--sync]` | Draft missing and stale keys (queued on `prosetta.queue.name` unless `--sync`). |
| `prosetta:review {locale} [--approve-clean] [--namespace=]` | List the review queue, or approve every clean current candidate. |
| `prosetta:export [--locale=*] [--namespace=*] [--include-drafts] [--dry-run] [--force]` | Write target-locale files; exits 1 on conflicts unless `--force`. Incomplete lists are left out, and files whose source group is gone are left untouched and reported. |
| `prosetta:rename {from} {to}` | Move translations to a key you renamed in the source file (sync first). |
| `prosetta:stats [--locale=]` | Progress per locale and namespace. |

Key references use Laravel's notation: `identity::onboarding.toast.accessCode.inUse`, `auth.failed`, `json:Save changes`.

## Services for your own admin

`Prosetta::reviewQueue($locale, $filters)` returns a paginator of `ReviewItem` (key, source, candidate, approved value, status, stale flag, issues, provenance), ready for Inertia props. `Prosetta::missing($locale, $filters)` lists keys with nothing yet in that locale, and `Prosetta::write($keyRef, $locale, $value, $by, approve: false)` translates any of them by hand through the same review trail. `edit(..., approve: true)` saves and approves together, or changes nothing. `edit()`, `approve()`, `approveClean()`, `reject()`, `export()`, `rename()`, `stats()` and `lookup()` complete the surface. Every method that acts on behalf of a user takes `?Authenticatable $by`; `null` means the system.

## Testing your integration

Bind `LonelyLights\Prosetta\Testing\FakeTranslationDriver` in tests. It echoes the source with ` [locale]` appended, and can be told to drop or recase placeholders, fix them on retry, or return nothing.
