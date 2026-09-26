# The workflow

Prosetta moves every string through four steps: **sync**, **translate**, **review** and **export**. You can run each one by hand, as below, or let [background mode](background-mode.md) run them for you.

## 1. Sync: read your source files

```bash
php artisan prosetta:sync
```

You edit your source language's files by hand (`lang/en/*.php`, `lang/en.json`, a module's `Lang/en/*.php`). Prosetta reads them and never writes them. Sync records what changed since last time:

- **A new string** becomes *missing* in every language.
- **A changed string** makes its existing translations *stale*: they still show, but need updating.
- **A removed string** becomes *obsolete*.

Sync also reads your existing translated files. The first time, what it finds there is imported as approved. After that, a hand edit to a translated file is imported *for review*, never accepted silently.

`prosetta:sync --check` changes nothing and exits 1 when there's work outstanding. Use it in CI to fail a build whose lang files are out of step.

## 2. Translate: draft with AI

```bash
php artisan prosetta:translate            # queue drafts for every missing or stale string
php artisan prosetta:translate --sync     # or draft right away, without a queue worker
```

Prosetta sends strings to your [AI driver](ai-drivers.md) in batches, then checks each result:

- every placeholder (`:name`, `:Name`) is still there, with the same case;
- plural segments (`one|many`, `{0}`, `[2,*]`) are intact;
- HTML tags are unchanged.

A result that fails a check gets **one retry**, with the problems explained to the model. The result is saved as a *draft*, with the model, provider and tokens that produced it.

Narrow a run with `--locale=es`, `--namespace=auth` or `--key=auth.failed` (each can repeat). `--force` redrafts strings that already have a translation. `--estimate` prints how many strings and tokens a run would take, without calling anything.

## 3. Review: approve, edit or reject

```bash
php artisan prosetta:review es                  # list what's waiting in Spanish
php artisan prosetta:review es --approve-clean  # approve every draft with no problems
```

Most apps review in their own admin instead; [Building a review UI](review-ui.md) covers the services for that. The simple ones are on the facade:

```php
use LonelyLights\Prosetta\Facades\Prosetta;

Prosetta::reviewQueue('es');                          // what's waiting, paginated
Prosetta::approve($translationId, $user);
Prosetta::edit($translationId, 'Guardar', $user, approve: true);
Prosetta::reject($translationId, $user, notes: 'Too formal.');
```

A rejection note goes to the AI with that string's next draft, so it knows why the last one was refused.

## 4. Export: write the lang files

```bash
php artisan prosetta:export
```

Export writes the approved text for each language into its lang files, in the same key order as your source file. Add `--include-drafts` to write drafts too (handy on a development machine), or `--dry-run` to see what would change.

Export won't overwrite work it doesn't know about. If a translated file holds something Prosetta didn't write (a hand edit not synced yet, a file never synced, or a key your source doesn't have), it leaves the file alone, reports a conflict and exits 1. Run `prosetta:sync` to import the edit, or pass `--force` to overwrite it.

Comments in your source PHP lang files are copied too: the heading comment above `return`, and any comment directly above a key, appear in every translated file above the same keys.

Commit the exported files like any other change.

## Key references

Commands and services name a string the way Laravel does:

| Reference | Means |
|---|---|
| `auth.failed` | `lang/en/auth.php`, key `failed` |
| `identity::onboarding.welcome` | The `identity` namespace's `onboarding.php`, key `welcome` |
| `json:Save changes` | `lang/en.json`, key `Save changes` |

## Renaming a key

When you rename a key in your source file, run `prosetta:sync`, then:

```bash
php artisan prosetta:rename auth.old_key auth.new_key
```

The translations move to the new key instead of being drafted again.

## Who may do what

There are three abilities:

| Ability | Allows |
|---|---|
| `Translate` | Editing drafts in a language |
| `Review` | Approving and rejecting in a language (includes `Translate`) |
| `Manage` | Everything, in every language, plus operations like running a cycle |

Decide them in one callback, usually in a service provider's `boot()`:

```php
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Facades\Prosetta;

Prosetta::authorizeUsing(fn ($user, Ability $ability, ?string $locale): bool => match ($ability) {
    Ability::Manage => $user->can('translations.manage'),
    default => $user->can("translations.{$ability->value}.$locale"),
});
```

`$locale` is the language being acted on, or `null` for `Manage`. With no callback, everything is allowed in the `local` environment and nothing elsewhere.

Prosetta also registers the gates `prosetta.translate`, `prosetta.review` and `prosetta.manage`, so you can write `$user->can('prosetta.review', 'ar')` in policies and Blade.

By default a reviewer may approve their own edits. On a team with more than one reviewer per language, set `prosetta.review.allow_self_approval` to `false` so every edit needs a second person.

Every service that acts for a person takes `?Authenticatable $by`. Passing `null` means the system itself (a command or the background cycle).

## All commands

| Command | Does |
|---|---|
| `prosetta:install` | Publish the config and migrations |
| `prosetta:sync [--namespace=*] [--check]` | Read your source files and import translated files |
| `prosetta:translate [--locale=*] [--namespace=*] [--key=*] [--force] [--sync] [--estimate]` | Draft missing and stale strings |
| `prosetta:review {locale} [--approve-clean] [--namespace=]` | List the review queue, or approve every clean draft |
| `prosetta:export [--locale=*] [--namespace=*] [--include-drafts] [--dry-run] [--force]` | Write translated lang files |
| `prosetta:rename {from} {to}` | Move translations to a renamed key |
| `prosetta:stats [--locale=]` | Progress per language and namespace |
| `prosetta:cycle [--sync]` | Run one [background cycle](background-mode.md) |
| `prosetta:health` | Exit 1 when the background cycle needs attention |
| `prosetta:circuit status` / `reset [circuit]` | See or reset [provider circuits](resilience.md) |
| `prosetta:resume` | Retry work paused by an outage or budget |
| `prosetta:content:sync [model*]` | Give existing [database rows](database-content.md) their keys |
| `prosetta:content:import {folder} {locale} {path}` | Import a lang file as approved database content |
| `prosetta:pull [--after=] [--no-sync] [--export]` | Bring [production approvals](production-review.md) into development |
