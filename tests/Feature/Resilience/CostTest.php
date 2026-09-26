<?php

use LonelyLights\Prosetta\Contracts\PriceCatalogue;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\ModelPrice;
use LonelyLights\Prosetta\Queries\Coverage;
use LonelyLights\Prosetta\Resilience\UsageLedger;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    config([
        'prosetta.resilience.cache_store' => 'array',
        'prosetta.ai.prices' => ['claude-sonnet-5' => ['input' => 3.0, 'output' => 15.0]],
        'prosetta.ai.currency' => 'USD',
    ]);
});

it('records which model spent the tokens', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);

    $this->artisan('prosetta:translate', ['--locale' => ['es'], '--sync' => true])->assertExitCode(0);

    expect(DB::table(Settings::table('usage'))->pluck('model')->unique()->values()->all())->toBe(['fake-model']);
});

it('prices tokens by model, and counts what it could not price', function () {
    $ledger = app(UsageLedger::class);
    $ledger->record(null, 'default', 'es', 1_000_000, 500_000, 'claude-sonnet-5');
    $ledger->record(null, 'default', 'es', 2_000, 1_000, 'mystery-model');
    $ledger->record(null, 'default', 'ar', 100_000, 0, 'claude-sonnet-5');

    $es = $ledger->cost(locale: 'es');

    expect($es->amount)->toBe(10.5)
        ->and($es->unpricedTokens)->toBe(3_000)
        ->and($es->currency)->toBe('USD')
        ->and($ledger->cost()->amount)->toBe(10.8);
});

it('takes prices from a catalogue the host binds', function () {
    app()->instance(PriceCatalogue::class, new class implements PriceCatalogue {
        public function price(string $model): ?ModelPrice {
            return $model === 'house-model' ? new ModelPrice(1.0, 2.0) : null;
        }

        public function currency(): string {
            return 'EUR';
        }
    });
    app(UsageLedger::class)->record(null, 'default', 'es', 1_000_000, 1_000_000, 'house-model');

    $cost = app(UsageLedger::class)->cost();

    expect($cost->amount)->toBe(3.0)->and($cost->currency)->toBe('EUR');
});

it('shows each language\'s cost this month on the overview', function () {
    app(Authorizer::class)->using(fn () => true);
    app(UsageLedger::class)->record(null, 'default', 'es', 1_000_000, 0, 'claude-sonnet-5');

    $report = app(Coverage::class)->for(Viewer::for(new GenericUser(['id' => 'u1'])))->toArray();
    $spanish = collect($report['languages'])->firstWhere('code', 'es');

    expect($spanish['costThisMonth'])->toBe(3.0)
        ->and($spanish['unpricedTokensThisMonth'])->toBe(0)
        ->and($report['currency'])->toBe('USD');
});
