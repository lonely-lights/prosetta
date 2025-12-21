# Prosetta Code Audit

> **Date**: December 2025
> **Version**: 0.2 → 0.3 refactor

## Summary

This audit documents the current state of Prosetta before the v0.3 refactor. The package has a working foundation but needs significant restructuring to support the new database-driven UI translation management system.

---

## Files Reviewed

| File | Lines | Purpose | Status |
|------|-------|---------|--------|
| `composer.json` | 57 | Package definition | OK |
| `config/prosetta.php` | 151 | Configuration | OK |
| `src/ProsettaServiceProvider.php` | 47 | Service registration | Minor issues |
| `src/Locale.php` | 199 | Locale model + file ops | Needs refactor |
| `src/Queue.php` | 67 | Queue model | OK |
| `src/Jobs/ProcessEntries.php` | 61 | Background job | Broken |
| `src/Services/LangKeyService.php` | 727 | Core service | Major refactor needed |
| `src/Services/MarkdownSanitizer.php` | 15 | Content sanitizer | OK |
| `src/Traits/ProsettaEntries.php` | 271 | Model trait | Needs simplification |
| `database/migrations/*.stub` | ~80 | Migration stubs | Needs expansion |
| `database/seeders/LocaleSeeder.php` | 51905 | Locale data | OK (large dataset) |

---

## Issues Found

### Critical Issues

#### 1. ChatGPT Links in Code Comments
External links that should be removed:

**Locale.php:70-71**
```php
* https://chat.openai.com/c/7c9f7eba-a97e-4a3e-ae2d-1dcb1b59e580
* https://chat.openai.com/c/9e1ad4bb-d34b-49c0-86db-2568178bb5fb
```

**LangKeyService.php:28-34**
```php
* https://chat.openai.com/c/7c9f7eba-a97e-4a3e-ae2d-1dcb1b59e580
* https://chat.openai.com/c/9e1ad4bb-d34b-49c0-86db-2568178bb5fb
* https://chat.openai.com/c/74697819-0a38-48d8-a5ef-b0d8941c37f3
* https://chat.openai.com/c/db59239a-e0ac-499c-b9ff-87cd3c16483d
* https://chat.openai.com/c/eafa9d44-7597-4b00-9f08-5a54ddc25d08
```

**LangKeyService.php:46**
```php
* https://chat.openai.com/c/b2fb7d12-5a0d-41a2-9f77-519fded0bb19
```

#### 2. ProcessEntries Job is Broken
The job references `$model->getProsettaPath()` which doesn't exist:
```php
// ProcessEntries.php:44
$path = $this->model->getProsettaPath();
```
Models don't have this method - the path is a closure stored in the trait's static property.

#### 3. Duplicate Code Between Locale.php and LangKeyService.php
Both files have `manageLanguageFileEntry()` and `processKeyValue()` methods with overlapping functionality. This creates confusion about which to use.

### Moderate Issues

#### 4. LangKeyService.php is Too Large (727 lines)
This single file handles:
- File operations (create, read, write, backup)
- Key management (create, update, remove)
- Affixation logic (prefix/suffix handling)
- Visibility checks (privacy filtering)
- Queue management (enqueue translation tasks)
- Multi-locale handling (sync across locales)

**Should be split into:**
- `FileManager` - File I/O operations
- `KeyManager` - Key CRUD operations
- `SyncService` - Multi-locale synchronization

#### 5. Static Properties on Trait
`ProsettaEntries` uses static properties which can cause issues with multiple models:
```php
protected static string|array $languageKeys;
protected static Closure|string $languagePath;
protected static string|array|null $languageAffixAttribute = null;
```
If two models use this trait, the last one to call `bootProsetta()` overwrites the settings.

#### 6. Hardcoded `users` Table Reference
Queue migration assumes `users` table exists:
```php
$table->foreign('creator_id')
    ->references('id')
    ->on('users')
    ->onDelete('cascade');
```
This should be configurable or nullable without foreign key.

#### 7. Config Key Typo
In `ProsettaServiceProvider.php`:
```php
$this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta.php');
// Should be:
$this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta');
```

### Minor Issues

#### 8. No Tests
Package has `phpunit.xml` but no actual tests in `tests/` directory.

#### 9. Inconsistent Logging
Mix of log channels used:
- `Log::channel('init')` in Locale.php
- `Log::channel('prosetta')` elsewhere
- `config('prosetta.logChannel', 'default')` in most places

#### 10. TODO Comments Left in Code
Multiple TODO comments that should be addressed:
- LangKeyService.php:39-46 - Multi-language file updates
- LangKeyService.php:307-309 - Rank calculation
- LangKeyService.php:601 - localeOperation logic
- LangKeyService.php:712-713 - Cache logic review
- LangKeyService.php:720-721 - Additional error handling

---

## Architecture Assessment

### Current Flow
```
Model Event (created/updated/deleted)
    ↓
ProsettaEntries Trait (handleLanguageEntry)
    ↓
LangKeyService (manageLanguageFileEntry)
    ↓
File System (lang/{locale}/{path}.php)
    ↓
Queue Model (for non-base locales)
```

### Problems with Current Architecture

1. **File-first** - Everything writes directly to files, no database storage for UI translations
2. **No Facade** - No clean public API for host applications
3. **No Events** - No Laravel events fired for translation changes
4. **Tight Coupling** - Trait and service are tightly coupled through static properties
5. **No Installer** - Manual setup required, error-prone
6. **No UI** - No admin interface for managing translations

---

## Breaking Changes Required for v0.3

### Database Schema Changes
- Add `prosetta_files` table (file registry)
- Add `prosetta_keys` table (translation keys)
- Add `prosetta_translations` table (values per locale)
- Add `prosetta_reviews` table (audit trail)
- Rename/restructure `prosetta_locales` table
- Remove or repurpose `prosetta_queue` table

### API Changes
- Replace `bootProsetta()` with property-based configuration
- Introduce `Prosetta` facade for all operations
- Remove direct file manipulation in favor of database-first

### Trait Changes
- Rename `ProsettaEntries` to `HasTranslations`
- Remove static properties (use model instance properties)
- Simplify configuration

---

## Recommended Refactor Order

1. **P0.1** - Remove ChatGPT links, fix typos, clean up TODOs ✅ **COMPLETED**
2. **P0.2** - Set up Pest PHP testing with Orchestra Testbench ✅ **COMPLETED**
3. **P0.3** - Split LangKeyService into focused services ✅ **COMPLETED**
4. **P0.4** - Add basic tests for existing functionality ✅ **COMPLETED** (47 tests passing)
5. **P1.1** - Create new database models
6. **P1.2-P1.4** - Build scanner, synchronizer, exporter services
7. **P1.5** - Create Prosetta facade
8. **P1.8** - Build admin UI
9. **P1.9** - Create installer command
10. **P3.1** - Create new HasTranslations trait (deprecate old)

---

## Files to Keep vs. Replace

### Keep (with modifications)
- `composer.json` - Update dependencies
- `config/prosetta.php` - Simplify, add new options
- `src/ProsettaServiceProvider.php` - Expand significantly
- `database/seeders/LocaleSeeder.php` - Keep as-is

### Replace Entirely
- `src/Locale.php` → `src/Models/Locale.php` (new structure)
- `src/Queue.php` → Remove (replaced by new models)
- `src/Services/LangKeyService.php` → Split into multiple services ✅ **DONE**
- `src/Traits/ProsettaEntries.php` → `src/Traits/HasTranslations.php`
- `src/Jobs/ProcessEntries.php` → New job structure

### Add New
- `src/Facades/Prosetta.php`
- `src/Models/TranslationFile.php`
- `src/Models/TranslationKey.php`
- `src/Models/Translation.php`
- `src/Models/TranslationReview.php`
- `src/Services/FileScanner.php`
- `src/Services/FileSynchronizer.php` ✅ **CREATED**
- `src/Services/FileExporter.php` ✅ **CREATED**
- `src/Services/KeyManager.php` ✅ **CREATED**
- `src/Services/TranslationService.php` ✅ **CREATED**
- `src/Http/Controllers/*.php`
- `src/Commands/*.php`
- `resources/views/**/*.blade.php`
- `routes/prosetta.php`
- `tests/**/*.php` ✅ **CREATED** (47 tests)

---

*Audit completed December 2025*
