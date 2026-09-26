# Translating database content

Lang files hold your interface text. Some text lives in the database instead: category names, badge titles, a list of plans. Prosetta can translate model fields too, through the same drafting and review as your lang files.

## Making a model translatable

Implement `TranslatableContent`, use the `TranslatesContent` trait, and list the fields to translate, each with a short note for the AI:

```php
use Illuminate\Database\Eloquent\Model;
use LonelyLights\Prosetta\Content\TranslatesContent;
use LonelyLights\Prosetta\Contracts\TranslatableContent;

final class Badge extends Model implements TranslatableContent {
    use TranslatesContent;

    public function translatableFields(): array {
        return [
            'name' => 'The name of a badge members earn.',
            'description' => 'One sentence saying how to earn it.',
        ];
    }
}
```

The trait does the work; the interface tells Prosetta (and your IDE) the model's shape.

## What happens on save

Each time a save commits, the model's fields are kept as keys under `content/<table>`, for example `content::badges.first-steps.name`. The record part is the model's route key, so a slug where there is one.

- **Editing the English** makes the translations stale, like a lang file edit.
- **Changing the slug** renames the keys and keeps their translations.
- **Deleting the record** marks its keys obsolete.

The [background cycle](background-mode.md) drafts new and changed fields, and they're reviewed like any other string.

## Reading translations

```php
$badge->translated('name');          // in the current locale, falling back to English
$badge->translated('name', 'es');    // in a given locale
$badge->translations();              // every translatable field, translated
```

`$badge->name` always stays English, so saving a model never writes another language into it. Approved values are cached and refreshed when something is approved.

## How content differs from lang files

- **It's never exported** to lang files: it's served straight from the database.
- **It can be approved anywhere**, even where [review is read-only](review-ui.md#read-only-environments), since it never has to reach git. `QueueItem` and `KeyRow` carry an `editable` flag per row so your UI can tell.

## Adjusting the defaults

Override these on the model:

| Method | Default |
|---|---|
| `translationFolder()` | The table name |
| `translationKey()` | The route key |
| `translationMaxLength($field)` | No limit |
| `shouldTranslate()` | `true`; return `false` to keep a record's keys out, e.g. for a draft |

Call `$model->queueContent()` to draft a record now instead of at the next cycle.

## Existing rows

A table that already has rows when its model becomes translatable needs its keys created once:

```bash
php artisan prosetta:content:sync "App\Models\Badge"
```

Or list your models under `prosetta.content.models` and run `prosetta:content:sync` with no arguments. It does the same as a save, so it's safe to repeat.

If the translations used to live in a lang file, import them as approved content:

```bash
php artisan prosetta:content:import badges es lang/es/badges.php
```

## Your own placeholders

If your app swaps its own tokens into text (say `[@]` becomes a member's name), add a pattern so the placeholder check protects them like Laravel's `:name`:

```php
'placeholders' => [
    'patterns' => ['/\[@\]/'],
],
```

A translation that drops or changes one is then flagged.
