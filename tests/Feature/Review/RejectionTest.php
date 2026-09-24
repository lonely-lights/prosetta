<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Automation\CycleWork;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;
use LonelyLights\Prosetta\Translation\TranslationRunner;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
    app(Syncer::class)->sync();
    Locale::query()->where('locale_initials', 'ar')->update(['auto_translate' => true]);
    app(Authorizer::class)->using(fn () => true);
    $this->key = app(KeyFinder::class)->find('auth.throttle');
    $this->draft = Translation::query()->create([
        'key_id' => $this->key->id, 'locale' => 'ar', 'value' => 'محاولات كثيرة.', 'source_hash' => $this->key->source_hash,
        'status' => TranslationStatus::Draft, 'origin' => 'ai',
    ]);
});

it('sends a rejection note to the model with the next draft of that key', function () {
    app(ReviewService::class)->reject($this->draft->id, new GenericUser(['id' => 'u1']), 'Too stiff; keep the :seconds placeholder and sound friendly.');
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    app(TranslationRunner::class)->run('ar', [$this->key->id]);

    /** @var TranslationBatch $batch */
    $batch = $driver->calls[0];

    expect($batch->feedback[(string) $this->key->id][0])->toContain('Too stiff; keep the :seconds placeholder and sound friendly.');
});

it('holds a key the cycle would draft once it has been rejected twice from the same English', function () {
    $user = new GenericUser(['id' => 'u1']);
    app(ReviewService::class)->reject($this->draft->id, $user, 'No.');
    $this->draft->update(['status' => TranslationStatus::Draft, 'value' => 'محاولة ثانية.']);
    app(ReviewService::class)->reject($this->draft->id, $user, 'Still no.');

    $plan = app(CycleWork::class)->plan();

    expect($plan['work']['ar'] ?? [])->not->toContain([$this->key->id])
        ->and(collect($plan['work']['ar'] ?? [])->flatten()->all())->not->toContain($this->key->id)
        ->and($plan['held'])->toContain('ar auth.throttle (held: rejected twice by a reviewer; waiting for a person)');
});

it('releases the hold once a person writes or approves a value', function () {
    $user = new GenericUser(['id' => 'u1']);
    app(ReviewService::class)->reject($this->draft->id, $user, 'No.');
    app(ReviewService::class)->reject($this->draft->id, $user, 'Still no.');

    app(ReviewService::class)->edit($this->draft->id, 'حاولت كثيرًا. انتظر :seconds ثانية.', $user, approve: true);

    expect(app(Rejections::class)->all())->toBe([]);
});
