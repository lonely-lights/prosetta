<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use Illuminate\Support\Facades\Http;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
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

it('writes no lang file anywhere export is turned off, and says so', function () {
    config(['prosetta.export.enabled' => false]);
    app(ReviewService::class)->write('auth.throttle', 'es', 'Demasiados intentos. Espera :seconds segundos.', null, approve: true);

    $report = app(Exporter::class)->export(['es']);

    expect($report->disabled)->toBeTrue()
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

it('serves interface-text approvals newer than the given time, with the English they were approved against', function () {
    config(['prosetta.pull.token' => 'secret-token']);
    $this->travelTo(now()->subHour());
    app(ReviewService::class)->write('auth.throttle', 'es', 'Viejo :seconds', new GenericUser(['id' => 'r1']), approve: true);
    $this->travelBack();
    $since = now()->subMinute()->toIso8601String();
    app(ReviewService::class)->edit(esTranslation('auth.throttle')->id, 'Demasiados intentos. Espera :seconds segundos.', new GenericUser(['id' => 'r2']), approve: true);

    $response = $this->getJson('/prosetta/approvals?since='.urlencode($since), ['Authorization' => 'Bearer secret-token'])->assertOk();

    expect($response->json('approvals'))->toHaveCount(1)
        ->and($response->json('approvals.0'))->toMatchArray([
            'ref' => 'auth.throttle', 'locale' => 'es',
            'value' => 'Demasiados intentos. Espera :seconds segundos.',
            'source_hash' => app(KeyFinder::class)->find('auth.throttle')->source_hash,
            'reviewed_by' => 'r2',
        ]);
});

it('pulls production\'s approvals into development where the English matches, and nowhere else', function () {
    config(['prosetta.pull.url' => 'https://undaunted.space/prosetta/approvals', 'prosetta.pull.token' => 'secret-token']);
    $hash = app(KeyFinder::class)->find('auth.throttle')->source_hash;
    Http::fake(['undaunted.space/*' => Http::response(['approvals' => [
        ['ref' => 'auth.throttle', 'locale' => 'es', 'value' => 'Demasiados intentos. Espera :seconds segundos.', 'source_hash' => $hash, 'reviewed_by' => 'r2', 'reviewed_at' => '2026-09-26T09:00:00+00:00', 'id' => 7],
        ['ref' => 'auth.failed', 'locale' => 'es', 'value' => 'Otra cosa.', 'source_hash' => 'an-older-english', 'reviewed_by' => 'r2', 'reviewed_at' => '2026-09-26T09:01:00+00:00', 'id' => 8],
        ['ref' => 'nope.gone', 'locale' => 'es', 'value' => 'x', 'source_hash' => 'x', 'reviewed_by' => 'r2', 'reviewed_at' => '2026-09-26T09:02:00+00:00', 'id' => 9],
    ], 'next' => null])]);

    $this->artisan('prosetta:pull')
        ->expectsOutputToContain('Pulled 1')
        ->assertExitCode(0);

    $pulled = esTranslation('auth.throttle');

    expect($pulled->approved_value)->toBe('Demasiados intentos. Espera :seconds segundos.')
        ->and($pulled->status)->toBe(TranslationStatus::Approved)
        ->and($pulled->reviewed_by)->toBe('r2')
        ->and($pulled->reviews()->latest('id')->value('notes'))->toContain('Pulled from production')
        ->and(esTranslation('auth.failed')->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and(State::get('pull.since'))->toBe('2026-09-26T09:02:00+00:00');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('asks only for what is new on the next pull', function () {
    config(['prosetta.pull.url' => 'https://undaunted.space/prosetta/approvals', 'prosetta.pull.token' => 'secret-token']);
    State::put('pull.since', '2026-09-26T09:02:00+00:00');
    State::put('pull.after', 9);
    Http::fake(['undaunted.space/*' => Http::response(['approvals' => [], 'next' => null])]);

    $this->artisan('prosetta:pull')->assertExitCode(0);

    # The Id Too: Another Approval in the Same Second Must Not Be Skipped
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'since=2026-09-26T09:02:00+00:00') && str_contains($request->url(), 'after=9'));
});

it('refuses to pull without somewhere to pull from', function () {
    $this->artisan('prosetta:pull')->assertExitCode(1);
});
