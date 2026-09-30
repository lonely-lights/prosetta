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

it('fills in the English an older approval was made from, only while the key still has that English', function () {
    app(Syncer::class)->sync();
    # Approvals Made Before approved_source_value Existed
    Translation::query()->update(['approved_source_value' => null]);
    $inUse = translationFor('identity::onboarding.toast.accessCode.inUse', 'es');
    $inUse->forceFill(['approved_source_hash' => Fingerprint::of('Older English.')])->save();

    app(Syncer::class)->sync();
    $failed = translationFor('auth.failed', 'es');

    expect($failed->approved_source_value)->toBe($failed->key->source_value)
        ->and(translationFor('identity::onboarding.toast.accessCode.inUse', 'es')->approved_source_value)->toBeNull();
});

it('fills in the approved English before the sync that edits it, so that edit can still be an update', function () {
    app(Syncer::class)->sync();
    Translation::query()->update(['approved_source_value' => null]);
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Those details do not match.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");

    app(Syncer::class)->sync();

    expect(translationFor('auth.failed', 'es')->approved_source_value)->toBe('These credentials do not match our records.');
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

it('obsoletes every key of a namespace that stops being discovered on a full sync', function () {
    app(Syncer::class)->sync();

    config()->set('prosetta.namespaces.exclude', ['identity']);
    $report = app(Syncer::class)->sync();

    expect($report->obsoleted)->toHaveCount(3)
        ->and($report->obsoleted)->toContain(
            'identity::onboarding.toast.accessCode.capReached',
            'identity::onboarding.toast.accessCode.inUse',
            'identity::onboarding.toast.accessCode.timedOut',
        )
        ->and(TranslationKey::query()->whereHas('file', fn ($q) => $q->where('namespace', 'identity'))->get()->pluck('obsolete_at'))
        ->each(fn ($value) => $value->not->toBeNull());
});

it('does not obsolete an excluded namespace during a namespace-limited sync', function () {
    app(Syncer::class)->sync();

    config()->set('prosetta.namespaces.exclude', ['identity']);
    $report = app(Syncer::class)->sync(['*']);

    expect($report->obsoleted)->toBe([])
        ->and(TranslationKey::query()->whereHas('file', fn ($q) => $q->where('namespace', 'identity'))->get()->pluck('obsolete_at'))
        ->each(fn ($value) => $value->toBeNull());
});

it('restores a namespace that reappears after a full sync', function () {
    app(Syncer::class)->sync();

    config()->set('prosetta.namespaces.exclude', ['identity']);
    app(Syncer::class)->sync();

    config()->set('prosetta.namespaces.exclude', []);
    $report = app(Syncer::class)->sync();

    expect($report->restored)->toContain(
        'identity::onboarding.toast.accessCode.capReached',
        'identity::onboarding.toast.accessCode.inUse',
        'identity::onboarding.toast.accessCode.timedOut',
    );
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

it('gives each key the comment written above it, as context for translators', function () {
    file_put_contents($this->fixture.'/lang/en/auth.php', <<<'PHP'
        <?php

        // Sign-in messages.

        return [
            // Shown under the form when the email or password is wrong.
            'failed' => 'These credentials do not match our records.',
            /* The copy-to-clipboard button
             * beside the recovery code. */
            'copy' => 'Copy',
            'throttle' => 'Too many login attempts. Please try again in :seconds seconds.', // not this: it trails a line
        ];
        PHP);

    app(Syncer::class)->sync();
    $context = fn (string $key) => TranslationKey::query()->withKey($key)->whereHas('file', fn ($q) => $q->where('group', 'auth'))->first()->context;

    expect($context('failed'))->toBe('Shown under the form when the email or password is wrong.')
        ->and($context('copy'))->toBe("The copy-to-clipboard button\nbeside the recovery code.")
        ->and($context('throttle'))->toBeNull();
});

it('updates a key\'s context and kept tokens when only the comment or the config changes, without calling it changed', function () {
    app(Syncer::class)->sync();
    $key = fn () => TranslationKey::query()->withKey('failed')->whereHas('file', fn ($q) => $q->where('group', 'auth'))->first();
    expect($key()->placeholders)->toBe([]);

    config(['prosetta.placeholders.terms' => ['credentials']]);
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return [\n    // On the sign-in form.\n    'failed' => 'These credentials do not match our records.',\n    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',\n];");
    $report = app(Syncer::class)->sync();

    expect($key()->placeholders)->toBe(['credentials'])
        ->and($key()->context)->toBe('On the sign-in form.')
        ->and($report->changed)->toBe([])
        ->and(WorkState::isStale($key(), translationFor('auth.failed', 'es')))->toBeFalse();
});
