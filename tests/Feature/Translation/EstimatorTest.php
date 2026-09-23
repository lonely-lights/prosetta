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

    expect($estimate['from_history'])->toBeTrue()
        ->and($estimate['input'])->toBe((int) round(2.0 * $estimate['chars']))
        ->and($estimate['output'])->toBe((int) round(3.0 * $estimate['chars']));
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
