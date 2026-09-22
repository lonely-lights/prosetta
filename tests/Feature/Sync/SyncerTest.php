<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\KeyAdded;
use LonelyLights\Prosetta\Events\KeyChanged;
use LonelyLights\Prosetta\Events\SyncCompleted;
use LonelyLights\Prosetta\Exceptions\LangFileException;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\WorkState;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
});

function translationFor(string $ref, string $locale): ?Translation {
    return Translation::query()->where('locale', $locale)->get()->first(fn (Translation $t) => $t->key->ref()->toString() === $ref);
}

it('reads the source locale into files and keys', function () {
    $report = app(Syncer::class)->sync();

    expect(TranslationFile::query()->count())->toBe(5)
        ->and(TranslationKey::query()->count())->toBe(13)
        ->and($report->added)->toContain('identity::onboarding.toast.accessCode.capReached', 'json:Version 2.0 is ready.', 'admin/settings.steps.1')
        ->and($report->added)->not->toContain('messages.limit')
        ->and(TranslationKey::query()->withKey('toast.accessCode.capReached')->first()->placeholders)->toBe([':minutes']);
});

it('imports existing target values as approved work', function () {
    $report = app(Syncer::class)->sync();
    $inUse = translationFor('identity::onboarding.toast.accessCode.inUse', 'es');

    expect($report->imported)->toBe(4)
        ->and($inUse->status)->toBe(TranslationStatus::Approved)
        ->and($inUse->origin)->toBe(TranslationOrigin::Imported)
        ->and($inUse->approved_value)->toBe($inUse->value)
        ->and($inUse->approved_source_hash)->toBe($inUse->key->source_hash)
        ->and($inUse->exported_hash)->toBe(Fingerprint::of($inUse->value))
        ->and(translationFor('messages.welcome', 'en_GB'))->not->toBeNull()
        ->and(translationFor('auth.failed', 'fr'))->toBeNull();
});

it('changes nothing when run again', function () {
    app(Syncer::class)->sync();
    $report = app(Syncer::class)->sync();

    expect($report->hasChanges())->toBeFalse()
        ->and($report->imported)->toBe(0)
        ->and($report->handEdits)->toBe([]);
});

it('marks translations stale when the English changes', function () {
    app(Syncer::class)->sync();
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Those details do not match.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");

    $report = app(Syncer::class)->sync();
    $failed = translationFor('auth.failed', 'es');

    expect($report->changed)->toBe(['auth.failed'])
        ->and(WorkState::isStale($failed->key, $failed))->toBeTrue()
        ->and($failed->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.');
});

it('obsoletes removed keys and files, and restores keys that come back', function () {
    app(Syncer::class)->sync();
    $original = file_get_contents($this->fixture.'/lang/en/auth.php');
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'These credentials do not match our records.'];");
    unlink($this->fixture.'/lang/en/admin/settings.php');

    $report = app(Syncer::class)->sync();

    expect($report->obsoleted)->toEqualCanonicalizing(['auth.throttle', 'admin/settings.title', 'admin/settings.steps.0', 'admin/settings.steps.1']);

    file_put_contents($this->fixture.'/lang/en/auth.php', $original);

    expect(app(Syncer::class)->sync()->restored)->toBe(['auth.throttle'])
        ->and(TranslationKey::query()->withKey('throttle')->first()->obsolete_at)->toBeNull();
});

it('imports a hand edit to a target file for review without replacing the approved value', function () {
    app(Syncer::class)->sync();
    file_put_contents($this->fixture.'/lang/es/auth.php', "<?php return ['failed' => 'Credenciales incorrectas.'];");

    $report = app(Syncer::class)->sync();
    $failed = translationFor('auth.failed', 'es');
    $review = $failed->reviews()->latest('id')->first();

    expect($report->handEdits)->toBe(['es auth.failed'])
        ->and($failed->value)->toBe('Credenciales incorrectas.')
        ->and($failed->status)->toBe(TranslationStatus::NeedsReview)
        ->and($failed->origin)->toBe(TranslationOrigin::Manual)
        ->and($failed->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and($review->action)->toBe(ReviewAction::Imported)
        ->and($review->previous_value)->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and(app(Syncer::class)->sync()->handEdits)->toBe([]);
});

it('imports a broken target value as needing review, never as approved', function () {
    file_put_contents($this->fixture.'/lang/es/messages.php', "<?php return ['welcome' => '¡Bienvenido, :nombre!'];");

    app(Syncer::class)->sync();
    $welcome = translationFor('messages.welcome', 'es');

    expect($welcome->status)->toBe(TranslationStatus::NeedsReview)
        ->and($welcome->approved_value)->toBeNull()
        ->and($welcome->hasBlockingIssues())->toBeTrue();
});

it('a broken source file aborts the sync without obsoleting anything', function () {
    app(Syncer::class)->sync();
    file_put_contents($this->fixture.'/lang/en/auth.php', '<?php return [');

    expect(fn () => app(Syncer::class)->sync())->toThrow(LangFileException::class, 'auth.php')
        ->and(TranslationKey::query()->whereNotNull('obsolete_at')->count())->toBe(0);
});

it('syncs only the namespaces asked for', function () {
    app(Syncer::class)->sync(['identity']);

    expect(TranslationFile::query()->pluck('namespace')->unique()->values()->all())->toBe(['identity']);
});

it('dispatches events after the transaction, unless quiet', function () {
    Event::fake([KeyAdded::class, KeyChanged::class, SyncCompleted::class]);

    app(Syncer::class)->sync(quiet: true);
    Event::assertNothingDispatched();

    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Changed.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    app(Syncer::class)->sync();

    Event::assertDispatched(KeyChanged::class, fn (KeyChanged $event) => $event->previousValue === 'These credentials do not match our records.');
    Event::assertDispatched(SyncCompleted::class);
});
