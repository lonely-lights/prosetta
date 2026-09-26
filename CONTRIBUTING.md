# Contributing

Thanks for helping. Bug reports, fixes and documentation improvements are all welcome.

Everyone taking part is expected to follow the [code of conduct](CODE_OF_CONDUCT.md).

## Reporting a bug

Open an issue with the Prosetta, PHP and Laravel versions, what you did, what you expected, and what happened instead. A failing test is the most useful report of all.

For a larger change, open an issue first so we can agree on the approach before you spend time on it.

## Working on the code

```bash
git clone https://github.com/lonely-lights/prosetta.git
cd prosetta
composer install
```

Before opening a pull request, run the same checks as CI:

```bash
composer test                 # Pest
vendor/bin/pint               # code style
vendor/bin/phpstan analyse    # static analysis, level 7
```

- **Add a test** for every fix and feature. Tests use Testbench and an in-memory SQLite database, so they need nothing else installed.
- **Update the docs** (`README.md` or the guide in `docs/`) when behaviour changes, and add a line under `Unreleased` in `CHANGELOG.md`.
- **Keep the package headless.** Prosetta ships services, not screens; UI belongs in the host app.
