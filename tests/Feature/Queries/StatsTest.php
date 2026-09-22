<?php

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Queries\Stats;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;
use LonelyLights\Prosetta\Translation\Translator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

it('counts per locale and namespace', function () {
    expect(app(Stats::class)->summary('es'))->toBe([
        'es' => [
            '*' => ['keys' => 10, 'approved' => 2, 'drafts' => 0, 'needs_review' => 0, 'stale' => 0, 'missing' => 8, 'issues' => 0, 'tokens' => 0],
            'identity' => ['keys' => 3, 'approved' => 1, 'drafts' => 0, 'needs_review' => 0, 'stale' => 0, 'missing' => 2, 'issues' => 0, 'tokens' => 0],
        ],
    ]);
});

it('counts drafts and their tokens', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);
    app(Translator::class)->translate(['es'], ['identity'], queue: false);

    $identity = app(Stats::class)->summary('es')['es']['identity'];

    expect($identity['drafts'])->toBe(2)
        ->and($identity['missing'])->toBe(0)
        ->and($identity['tokens'])->toBeGreaterThan(0);
});

it('counts stale translations', function () {
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Wrong details.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    app(Syncer::class)->sync();

    expect(app(Stats::class)->summary('es')['es']['*']['stale'])->toBe(1)
        ->and(app(Stats::class)->summary('es')['es']['*']['approved'])->toBe(1);
});

it('adds up everything outstanding across targets', function () {
    # es: 8 + 2 missing; ar: 13 missing; en_GB: 12 missing
    expect(app(Stats::class)->outstanding())->toBe(35);
});
