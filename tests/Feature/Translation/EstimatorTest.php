<?php

use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Translation\Estimator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

it('estimates from the default rates before a locale has history', function () {
    $estimate = app(Estimator::class)->estimate(['ar']);

    expect($estimate['ar']['strings'])->toBeGreaterThan(0)
        ->and($estimate['ar']['from_history'])->toBeFalse()
        ->and($estimate['ar']['input'])->toBe((int) round(0.3 * $estimate['ar']['chars'] + 12 * $estimate['ar']['strings']))
        ->and($estimate['ar']['output'])->toBe((int) round(0.3 * $estimate['ar']['chars'] + 8 * $estimate['ar']['strings']));
});

it('estimates from a locale\'s own history once it has fifty AI drafts', function () {
    $file = \LonelyLights\Prosetta\Models\TranslationFile::query()->first();

    foreach (range(1, 50) as $i) {
        $key = \LonelyLights\Prosetta\Models\TranslationKey::query()->create([
            'file_id' => $file->id, 'kind' => \LonelyLights\Prosetta\Enums\KeyKind::File, 'key' => "history.$i",
            'source_value' => 'Ten chars.', 'source_hash' => 'h'.$i,
        ]);
        Translation::query()->create([
            'key_id' => $key->id, 'locale' => 'ar', 'value' => 'x', 'source_hash' => 'h'.$i,
            'status' => 'draft', 'origin' => 'ai', 'input_tokens' => 20, 'output_tokens' => 30,
        ]);
    }

    $estimate = app(Estimator::class)->estimate(['ar'])['ar'];

    // Every history row is a 10-char source ("Ten chars."), so inPerItem = 20, outPerItem = 30, L = 10:
    // defaultIn(L) = 0.3*10+12 = 15, defaultOut(L) = 0.3*10+8 = 11. Summed across the work list's strings,
    // sum(defaultIn(c_i)) = 0.3*chars + 12*strings (defaultIn is linear in c), so the ratio formula
    // collapses to (inPerItem / defaultIn(L)) * (0.3*chars + 12*strings) — no per-string loop needed here.
    expect($estimate['from_history'])->toBeTrue()
        ->and($estimate['input'])->toBe((int) round((20 / 15) * (0.3 * $estimate['chars'] + 12 * $estimate['strings'])))
        ->and($estimate['output'])->toBe((int) round((30 / 11) * (0.3 * $estimate['chars'] + 8 * $estimate['strings'])));
});

it('does not overestimate long strings from short-string history', function () {
    // Its own file/namespace, so the work list below holds exactly the five long, untranslated
    // keys: none of the fixture's other 'ar' work and none of these fifty translated shorts.
    $file = \LonelyLights\Prosetta\Models\TranslationFile::query()->create([
        'namespace' => 'longtest', 'group' => 'longtest', 'format' => \LonelyLights\Prosetta\Enums\FileFormat::Php,
    ]);

    foreach (range(1, 50) as $i) {
        $key = \LonelyLights\Prosetta\Models\TranslationKey::query()->create([
            'file_id' => $file->id, 'kind' => \LonelyLights\Prosetta\Enums\KeyKind::File, 'key' => "short.$i",
            'source_value' => 'Ten chars.', 'source_hash' => 'sh'.$i,
        ]);
        Translation::query()->create([
            'key_id' => $key->id, 'locale' => 'ar', 'value' => 'x', 'source_hash' => 'sh'.$i,
            'status' => 'draft', 'origin' => 'ai', 'input_tokens' => 40, 'output_tokens' => 20,
        ]);
    }

    foreach (range(1, 5) as $i) {
        \LonelyLights\Prosetta\Models\TranslationKey::query()->create([
            'file_id' => $file->id, 'kind' => \LonelyLights\Prosetta\Enums\KeyKind::File, 'key' => "long.$i",
            'source_value' => str_repeat('x', 300), 'source_hash' => 'lo'.$i,
        ]);
    }

    $estimate = app(Estimator::class)->estimate(['ar'], ['longtest'])['ar'];

    // The old, per-char-average formula would price these five 300-char strings at the short
    // strings' blended rate (2000 input tokens / 500 chars = 4.0/char) over 1500 chars = 6000:
    // wildly inflated, because the short history is overhead-heavy (40 tokens for 10 chars).
    // The fix scales inPerItem (40) by the default model's own shape between L=10 and c=300:
    // 40 * (0.3*300+12)/(0.3*10+12) = 40 * 102/15 = 272 per string, 1360 for the five together.
    expect($estimate['from_history'])->toBeTrue()
        ->and($estimate['strings'])->toBe(5)
        ->and($estimate['input'])->toBe((int) round(5 * 40 * (0.3 * 300 + 12) / (0.3 * 10 + 12)))
        ->and($estimate['input'])->toBeLessThan(6000);
});

it('prints an estimate without queueing anything', function () {
    \Illuminate\Support\Facades\Bus::fake();

    $this->artisan('prosetta:translate --locale=ar --estimate')
        ->expectsOutputToContain('ar')
        ->assertSuccessful();

    \Illuminate\Support\Facades\Bus::assertNothingBatched();
});

it('compares the estimate with the per-run limit as well as the daily and monthly budgets', function () {
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.budgets.per_run' => 10, 'prosetta.budgets.daily' => 1_000_000]);

    $this->artisan('prosetta:translate --locale=ar --estimate')
        ->expectsOutputToContain('per_run limit: 10 tokens; this estimate does not fit under it.')
        ->expectsOutputToContain('daily budget:')
        ->assertSuccessful();

    config(['prosetta.budgets.per_run' => 10_000_000]);

    $this->artisan('prosetta:translate --locale=ar --estimate')
        ->expectsOutputToContain('per_run limit: 10000000 tokens; this estimate fits under it.')
        ->assertSuccessful();
});
