<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

function queueDraft(string $ref, string $locale, string $value, string $origin = 'ai', ?array $issues = null): Translation {
    $key = app(KeyFinder::class)->find($ref);

    return Translation::query()->updateOrCreate(['key_id' => $key->id, 'locale' => $locale], [
        'value' => $value, 'source_hash' => $key->source_hash, 'status' => TranslationStatus::Draft, 'origin' => $origin, 'issues' => $issues,
    ]);
}

function queueViewer(array $translate): Viewer {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $ability !== Ability::Manage && in_array($locale, $translate, true));

    return Viewer::for(new GenericUser(['id' => 'u1']));
}

it('gives each item that needs a person its reason', function () {
    queueDraft('auth.throttle', 'es', 'Demasiados intentos.');
    queueDraft('messages.welcome', 'es', '¡Hola, :nombre!', issues: [['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'Missing :name.']]);
    queueDraft('messages.apples', 'es', '{0} Ninguna|{1} Una|[2,*] :count manzanas', origin: 'manual');
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Those details do not match.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    app(Syncer::class)->sync();

    $reasons = collect(app(ReviewQueue::class)->all(queueViewer(['es'])))->pluck('reason', 'keyRef')->all();

    expect($reasons)->toMatchArray([
        'auth.throttle' => 'draft',
        'messages.welcome' => 'flagged',
        'messages.apples' => 'pending',
        'auth.failed' => 'stale',
    ]);
});

it('hides languages the viewer can\'t translate', function () {
    queueDraft('auth.throttle', 'es', 'Demasiados intentos.');
    queueDraft('auth.throttle', 'ar', 'محاولات كثيرة.');

    $locales = collect(app(ReviewQueue::class)->all(queueViewer(['es'])))->pluck('locale')->unique()->values()->all();

    expect($locales)->toBe(['es']);
});

it('filters by reason and search, and pages', function () {
    queueDraft('auth.throttle', 'es', 'Demasiados intentos.');
    queueDraft('messages.welcome', 'es', 'Hola', issues: [['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'x']]);
    $viewer = queueViewer(['es']);

    expect(app(ReviewQueue::class)->count($viewer, ['reason' => 'flagged']))->toBe(1)
        ->and(app(ReviewQueue::class)->count($viewer, ['search' => 'DEMASIADOS']))->toBe(1)
        ->and(app(ReviewQueue::class)->for($viewer, [], 1, 1)->total())->toBe(2)
        ->and(app(ReviewQueue::class)->for($viewer, [], 1, 1)->items())->toHaveCount(1);
});

it('shows the English an update was made from, with the word diff', function () {
    $failed = Translation::query()->where('locale', 'es')->whereHas('key', fn ($q) => $q->where('key', 'failed'))->first();
    $failed->update(['approved_source_value' => 'These credentials do not match our records.']);
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'These details do not match our records.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    app(Syncer::class)->sync();

    $item = collect(app(ReviewQueue::class)->all(queueViewer(['es'])))->firstWhere('keyRef', 'auth.failed');

    expect($item->previousSource)->toBe('These credentials do not match our records.')
        ->and($item->diff)->toContain('credentials')->toContain('details')
        ->and($item->toArray())->toHaveKeys(['keyRef', 'reason', 'fingerprint']);
});

function capQueueKey(string $ref, string $locale): \LonelyLights\Prosetta\Models\TranslationKey {
    $key = app(KeyFinder::class)->find($ref);

    foreach (range(1, \LonelyLights\Prosetta\Automation\CycleFailures::LIMIT) as $run) {
        app(\LonelyLights\Prosetta\Automation\CycleFailures::class)->settle($locale, [$key], [], "run-$run");
    }

    return $key;
}

it('stops calling a capped key held once a person writes and approves it', function () {
    capQueueKey('auth.throttle', 'es');
    $viewer = queueViewer(['es']);
    expect(collect(app(ReviewQueue::class)->all($viewer))->pluck('reason', 'keyRef')->get('auth.throttle'))->toBe('held');

    app(\LonelyLights\Prosetta\Review\ReviewService::class)->write('auth.throttle', 'es', 'Demasiados intentos. Espera :seconds segundos.', null, approve: true);

    $es = app(\LonelyLights\Prosetta\Queries\Coverage::class)->for($viewer)->languages[0];

    expect(collect(app(ReviewQueue::class)->all($viewer))->pluck('keyRef')->all())->not->toContain('auth.throttle')
        ->and($es['held'])->toBe(0)
        ->and(app(\LonelyLights\Prosetta\Automation\CycleFailures::class)->all())->toBe([]);
});

it('never calls a key approved when it has no approved value and only an outdated draft, as the cycle still works on it', function () {
    queueDraft('auth.throttle', 'es', 'Demasiados intentos. Espera :seconds segundos.');
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'These credentials do not match our records.', 'throttle' => 'Too many attempts. Wait :seconds seconds.'];");
    app(Syncer::class)->sync();
    $key = app(KeyFinder::class)->find('auth.throttle');
    $translation = Translation::query()->where('key_id', $key->id)->where('locale', 'es')->first();

    expect(\LonelyLights\Prosetta\Review\Status::of($key, $translation, 'es', [], []))->toBe('missing');

    capQueueKey('auth.throttle', 'es');
    $failures = app(\LonelyLights\Prosetta\Automation\CycleFailures::class)->all();

    expect(\LonelyLights\Prosetta\Review\Status::of($key, $translation, 'es', $failures, []))->toBe('held');
});

it('shows a person\'s pending value on a capped key as pending, not held', function () {
    capQueueKey('auth.throttle', 'es');
    queueDraft('auth.throttle', 'es', 'Demasiados intentos. Espera :seconds segundos.', origin: 'manual');

    $reasons = collect(app(ReviewQueue::class)->all(queueViewer(['es'])))->pluck('reason', 'keyRef')->all();

    expect($reasons['auth.throttle'])->toBe('pending');
});
