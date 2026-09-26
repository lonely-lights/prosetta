<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\State;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->directory = $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    app(Authorizer::class)->using(fn () => true);
});

function esTranslation(string $ref): Translation {
    return Translation::query()->where('locale', 'es')->where('key_id', app(KeyFinder::class)->find($ref)->id)->firstOrFail();
}

function pulled(array $approvals): void {
    config(['prosetta.pull.url' => 'https://undaunted.space/prosetta/approvals', 'prosetta.pull.token' => 'secret-token']);
    Http::fake(['undaunted.space/*' => Http::response(['approvals' => $approvals, 'next' => null])]);
}

it('writes no lang file anywhere export is turned off, and says so', function () {
    config(['prosetta.export.enabled' => false]);
    app(ReviewService::class)->write('auth.throttle', 'es', 'Demasiados intentos. Espera :seconds segundos.', null, approve: true);

    $report = app(Exporter::class)->export(['es']);

    expect($report->disabled)->toBeTrue()
        ->and($report->toArray()['disabled'])->toBeTrue()
        ->and($report->written)->toBe([])
        ->and(file_get_contents($this->directory.'/lang/es/auth.php'))->not->toContain('Demasiados');

    $this->artisan('prosetta:export')->expectsOutputToContain('turned off')->assertExitCode(0);
});

it('hides the approvals endpoint unless a token is set and given', function () {
    $this->getJson('/prosetta/approvals')->assertNotFound();

    config(['prosetta.pull.token' => 'secret-token']);

    $this->getJson('/prosetta/approvals')->assertNotFound();
    $this->getJson('/prosetta/approvals', ['Authorization' => 'Bearer wrong'])->assertNotFound();
    $this->getJson('/prosetta/approvals', ['Authorization' => 'Bearer secret-token'])->assertOk();
});

it('serves each approval that is still the live one, after the given point, even if the row has moved on since', function () {
    config(['prosetta.pull.token' => 'secret-token']);
    $reviewer = new GenericUser(['id' => 'r1']);
    app(ReviewService::class)->write('auth.throttle', 'es', 'Primero :seconds', $reviewer, approve: true);
    $first = esTranslation('auth.throttle')->reviews()->where('action', 'approved')->max('id');
    app(ReviewService::class)->edit(esTranslation('auth.throttle')->id, 'Demasiados intentos. Espera :seconds segundos.', new GenericUser(['id' => 'r2']), approve: true);
    # Someone Edits Without Approving, Then It's Rejected: the Row's Status Moves On, the Approval Stands
    app(ReviewService::class)->edit(esTranslation('auth.throttle')->id, 'Borrador :seconds', $reviewer);
    app(ReviewService::class)->reject(esTranslation('auth.throttle')->id, new GenericUser(['id' => 'r3']), 'No.');

    $response = $this->getJson('/prosetta/approvals?after='.$first, ['Authorization' => 'Bearer secret-token'])->assertOk();

    expect($response->json('approvals'))->toHaveCount(1)
        ->and($response->json('approvals.0'))->toMatchArray([
            'ref' => 'auth.throttle', 'locale' => 'es',
            'value' => 'Demasiados intentos. Espera :seconds segundos.',
            'source_hash' => app(KeyFinder::class)->find('auth.throttle')->source_hash,
            'reviewed_by' => 'r2',
        ]);
});

it('pulls where the English matches, crediting production rather than a local user, and names what it skipped', function () {
    $hash = app(KeyFinder::class)->find('auth.throttle')->source_hash;
    pulled([
        ['id' => 7, 'ref' => 'auth.throttle', 'locale' => 'es', 'value' => 'Demasiados intentos. Espera :seconds segundos.', 'source_hash' => $hash, 'reviewed_by' => 'r2', 'reviewed_at' => '2026-09-26T09:00:00+00:00'],
        ['id' => 8, 'ref' => 'auth.failed', 'locale' => 'es', 'value' => 'Otra cosa.', 'source_hash' => 'an-older-english', 'reviewed_by' => 'r2', 'reviewed_at' => '2026-09-26T09:01:00+00:00'],
    ]);

    $this->artisan('prosetta:pull')
        ->expectsOutputToContain('Pulled 1')
        ->expectsOutputToContain('auth.failed (es): its English differs here')
        ->assertExitCode(0);

    $translation = esTranslation('auth.throttle');

    expect($translation->approved_value)->toBe('Demasiados intentos. Espera :seconds segundos.')
        ->and($translation->status)->toBe(TranslationStatus::Approved)
        ->and($translation->reviewed_by)->toBeNull()
        ->and($translation->reviews()->latest('id')->value('notes'))->toContain('Pulled from production (reviewer r2)')
        ->and(esTranslation('auth.failed')->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and(State::get('pull.after'))->toBe(8);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('syncs the lang files first, so a key added in the same deploy is not skipped', function () {
    file_put_contents($this->directory.'/lang/en/auth.php', "<?php\n\nreturn [\n    'failed' => 'These credentials do not match our records.',\n    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',\n    'locked' => 'Your account is locked.',\n];\n");
    pulled([
        ['id' => 3, 'ref' => 'auth.locked', 'locale' => 'es', 'value' => 'Tu cuenta está bloqueada.', 'source_hash' => Fingerprint::of('Your account is locked.'), 'reviewed_by' => 'r2', 'reviewed_at' => '2026-09-26T09:00:00+00:00'],
    ]);

    $this->artisan('prosetta:pull')->expectsOutputToContain('Pulled 1')->assertExitCode(0);

    expect(esTranslation('auth.locked')->approved_value)->toBe('Tu cuenta está bloqueada.');
});

it('keeps a developer\'s edit that is waiting for review, updating only what is approved', function () {
    $translation = esTranslation('auth.failed');
    $translation->update(['value' => 'Mi borrador.', 'status' => TranslationStatus::NeedsReview, 'origin' => TranslationOrigin::Manual]);
    pulled([
        ['id' => 4, 'ref' => 'auth.failed', 'locale' => 'es', 'value' => 'Credenciales incorrectas.', 'source_hash' => app(KeyFinder::class)->find('auth.failed')->source_hash, 'reviewed_by' => 'r2', 'reviewed_at' => '2026-09-26T09:00:00+00:00'],
    ]);

    $this->artisan('prosetta:pull')->assertExitCode(0);
    $translation->refresh();

    expect($translation->approved_value)->toBe('Credenciales incorrectas.')
        ->and($translation->value)->toBe('Mi borrador.')
        ->and($translation->status)->toBe(TranslationStatus::NeedsReview);
});

it('asks only for what is new on the next pull', function () {
    State::put('pull.after', 9);
    pulled([]);

    $this->artisan('prosetta:pull')->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'after=9'));
});

it('refuses to send the token over plain http, and to pull without somewhere to pull from', function () {
    $this->artisan('prosetta:pull')->assertExitCode(1);

    config(['prosetta.pull.url' => 'http://undaunted.space/prosetta/approvals', 'prosetta.pull.token' => 'secret-token']);
    Http::fake();

    $this->artisan('prosetta:pull')->expectsOutputToContain('https')->assertExitCode(1);
    Http::assertNothingSent();
});
