# AI drivers

Prosetta ships no AI client. You write one small class, a *driver*, that sends a batch of strings to the provider of your choice and returns the translations. Prosetta does everything else: choosing what to translate, checking the results, retrying, saving and counting tokens.

## The contract

```php
namespace LonelyLights\Prosetta\Contracts;

interface TranslationDriver {
    public function translate(TranslationBatch $batch): TranslationBatchResult;
}
```

**What you receive**, a `TranslationBatch`:

| Property | Holds |
|---|---|
| `sourceLocale` | The source language code, such as `en` |
| `target` | A `LocaleDescriptor`: `code`, `englishName`, `nativeName`, `script`, `rtl`, `styleNote`, `glossary` |
| `variantOf` | The source code when the target is a regional variant of it (`en_GB` of `en`), else `null` |
| `model` | The model configured for this language (`prosetta.ai.model` or `prosetta.ai.models`), or `null` for your default |
| `items` | A list of `TranslationItem` |
| `feedback` | Problems to fix on a retry, keyed by item id |

Each `TranslationItem` has an `id`, the `source` text, and optional `context`, `maxLength`, `placeholders`, `previous` (the current translation, when updating one) and `previousSource` (the English that translation was made from, so you can show the model what changed).

**What you return**, a `TranslationBatchResult`:

```php
new TranslationBatchResult(
    values: ['item-id' => 'translated text', /* ... */],
    provider: 'anthropic',
    model: 'claude-sonnet-5',
    inputTokens: 1200,
    outputTokens: 900,
    invocationId: 'abc123',    // optional: your provider's id for the call
    refused: ['item-id' => 'reason'],  // optional: strings the provider declined
);
```

Return one value per item id. A missing id counts as a failure for that string.

## A complete example with laravel/ai

The agent describes the job and the shape of the answer:

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
        Respect max_length when given. Follow the style note and glossary when given.
        When variant_of is set, only adapt spelling and usage for that region,
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

The driver turns a batch into a prompt and the answer into a result:

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
            'style_note' => $batch->target->styleNote,
            'glossary' => $batch->target->glossary,
            'items' => array_map(fn (TranslationItem $item) => [
                'id' => $item->id,
                'text' => $item->source,
                'context' => $item->context,
                'max_length' => $item->maxLength,
                'previous_translation' => $item->previous,
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

Bind it in a service provider:

```php
use LonelyLights\Prosetta\Contracts\TranslationDriver;

$this->app->bind(TranslationDriver::class, \App\Services\Translation\LaravelAiTranslationDriver::class);
```

or set `prosetta.ai.driver` to the class name.

Each translation stores its share of the call's tokens and the call's `invocationId`, so exact totals are one join away in any ledger keyed by your provider's call id.

## Telling Prosetta what went wrong

Only your driver knows what a provider's errors mean, so it translates them into five exceptions Prosetta understands. All of them extend `LonelyLights\Prosetta\Exceptions\Provider\ProviderException`:

| Throw | When | What Prosetta does |
|---|---|---|
| `ProviderUnavailable` | The provider is down or overloaded, a connection failed or timed out, a 5xx | Waits and retries, and counts it towards opening the [circuit](resilience.md) |
| `ProviderRateLimited(?int $retryAfter)` | A 429 | Waits `retryAfter` seconds (or the usual backoff) and retries |
| `ProviderRejected` | A bad API key, an unknown or retired model, no access (401, 403, 404) | Stops everything until someone fixes it |
| `ProviderQuotaExhausted` | Out of credits or quota | Stops everything until someone fixes it |
| `ProviderBatchRejected` | The provider refused this one request (too long, invalid input: 400, 422) but is otherwise fine | Fails just that batch, with its error, and carries on |

Any other exception is treated as `ProviderUnavailable` by default. Set `prosetta.resilience.unknown_errors` to `'halt'` to treat unknown errors as `ProviderRejected` instead.

When a provider's safety filter declines only some strings, don't throw: return them in `refused` (item id => reason). They're saved as failed with a `refused` issue and aren't retried.

## An optional health check

If your provider has a cheap call that proves it's answering, implement `ChecksHealth` too:

```php
use LonelyLights\Prosetta\Contracts\ChecksHealth;

public function checkHealth(): void {
    // A near-free call. Throw a ProviderException when it fails.
}
```

After an outage, Prosetta then tests the provider with this call instead of spending a real batch.

## Testing without an AI

Bind the fake driver in your tests:

```php
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;

$this->app->instance(TranslationDriver::class, new FakeTranslationDriver);
```

It returns each source string with ` [es]` (the target code) appended, and records every batch it received in `$calls`. To test how your app handles bad drafts, call one of these first:

| Method | Makes the fake |
|---|---|
| `dropPlaceholders()` | Leave placeholders out |
| `recasePlaceholders()` | Change placeholders' case (`:name` to `:Name`) |
| `fixOnRetry()` | Leave placeholders out, then get them right on the retry |
| `omitValues()` | Return nothing |
