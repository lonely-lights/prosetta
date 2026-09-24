<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Automation\Cycle;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Queries\Coverage;
use LonelyLights\Prosetta\Resilience\UsageLedger;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Support\State;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
    app(Syncer::class)->sync();
});

function coverageViewer(array $translate): Viewer {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $ability !== Ability::Manage && in_array($locale, $translate, true));

    return Viewer::for(new GenericUser(['id' => 'u1']));
}

it('counts only the viewer\'s languages, by the same rules as the queue', function () {
    $report = app(Coverage::class)->for(coverageViewer(['es']));
    $es = $report->languages[0];

    expect(collect($report->languages)->pluck('code')->all())->toBe(['es'])
        ->and($es['keys'])->toBe($es['approved'] + $es['draft'] + $es['flagged'] + $es['pending'] + $es['stale'] + $es['missing'] + $es['held'])
        ->and($es['approved'])->toBeGreaterThan(0)
        ->and($es['mode'])->toBe('ai');
});

it('reports a derived language, this month\'s tokens per language, and the last cycle', function () {
    \LonelyLights\Prosetta\Models\Locale::query()->where('locale_initials', 'en_GB')->update(['replacements' => [['from' => 'color', 'to' => 'colour']]]);
    app(UsageLedger::class)->record(null, 'c', 'es', 100, 50);
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    app(Cycle::class)->run(sync: true);

    $report = app(Coverage::class)->for(coverageViewer(['es', 'en_GB']));

    expect(collect($report->languages)->firstWhere('code', 'en_GB')['mode'])->toBe('derived')
        ->and(collect($report->languages)->firstWhere('code', 'es')['tokensThisMonth'])->toBeGreaterThanOrEqual(150)
        ->and($report->lastCycleAt)->not->toBeNull()
        ->and($report->lastReport)->toHaveKeys(['drafted', 'flagged', 'at'])
        ->and(State::get('cycle.last_report'))->toBeArray()
        ->and($report->toArray())->toHaveKeys(['languages', 'circuits', 'budget', 'problems', 'editable']);
});

it('shares its health problems with prosetta:health', function () {
    config(['prosetta.automation.every' => 30]);

    $problems = app(Coverage::class)->for(coverageViewer(['es']))->problems;

    expect($problems)->toBe(['[cycle_stale] automation is on but no cycle has run yet']);
    $this->artisan('prosetta:health')->expectsOutput('[cycle_stale] automation is on but no cycle has run yet')->assertFailed();
});

it('shows only the viewer\'s languages in the last cycle report', function () {
    State::put('cycle.last_report', [
        'drafted' => 4, 'flagged' => ['ar auth.throttle (placeholder)', 'es messages.welcome (glossary)'],
        'files' => ['C:\app\lang\ar\auth.php', '/app/lang/ar.json', '/app/lang/es/auth.php', '/app/lang/es.json'],
        'at' => now()->getTimestamp(),
    ]);

    $report = app(Coverage::class)->for(coverageViewer(['es']))->lastReport;

    expect($report['flagged'])->toBe(['es messages.welcome (glossary)'])
        ->and($report['files'])->toBe(['/app/lang/es/auth.php', '/app/lang/es.json'])
        ->and($report['drafted'])->toBe(4);
});
