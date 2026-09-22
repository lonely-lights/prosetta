## Unreleased: rebuild

- Rebuilt from a blank slate as a headless package. Source-language lang files are the canonical keys; Prosetta reads them and writes only other locales.
- AI translation through a host-supplied `TranslationDriver`, with placeholder, plural and HTML checks, one guided retry, and per-translation provenance (provider, model, tokens, invocation id).
- Per-language review (`Translate`, `Review`, `Manage`) decided by one `Prosetta::authorizeUsing()` hook, plus Laravel gates.
- Discovers module lang folders from `loadTranslationsFrom()` hints; supports root PHP, root JSON, nested folders and regional variants.
- Export writes approved (optionally draft) values in source key order, atomically, never to the source locale or excluded paths.
- `Locale` keeps only translation fields, gains `translated`, widens codes to 35 characters, and makes codes immutable.
- Requires PHP 8.3 and Laravel 11, 12 or 13. The Blade UI, legacy file-writing services, queue table and statistics seeder are removed.
