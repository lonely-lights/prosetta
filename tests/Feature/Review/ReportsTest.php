<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationReport;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\Reports;
use LonelyLights\Prosetta\Review\ReviewDesk;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    app(Authorizer::class)->using(fn () => true);
    $this->member = new GenericUser(['id' => 'member-1']);
    $this->failed = fn () => Translation::query()->where('locale', 'es')->where('key_id', app(KeyFinder::class)->find('auth.failed')->id)->firstOrFail();
});

it('turns a report with a suggestion into a hand edit awaiting review, leaving the live wording alone', function () {
    $report = app(Reports::class)->report('es', 'no coinciden con nuestros', $this->member, suggestion: 'Estas credenciales no son correctas.', notes: 'Sounds stiff.', url: '/login');

    $translation = ($this->failed)();

    expect($report->key_id)->toBe($translation->key_id)
        ->and($report->status)->toBe('open')
        ->and($translation->value)->toBe('Estas credenciales no son correctas.')
        ->and($translation->status)->toBe(TranslationStatus::NeedsReview)
        ->and($translation->origin)->toBe(TranslationOrigin::Manual)
        ->and($translation->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.');

    $item = collect(app(ReviewQueue::class)->all(Viewer::for(new GenericUser(['id' => 'reviewer'])), ['locale' => 'es']))
        ->firstWhere('keyRef', 'auth.failed');

    expect($item->reason)->toBe('pending')
        ->and($item->reports)->toBe(1);
});

it('flags the current wording with the member\'s note when there is no suggestion', function () {
    app(Reports::class)->report('es', 'nuestros registros', $this->member, notes: 'Wrong register for our tone.');

    $translation = ($this->failed)();

    expect($translation->value)->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and($translation->status)->toBe(TranslationStatus::NeedsReview)
        ->and(collect($translation->issues)->firstWhere('code', 'reported')['message'])->toContain('Wrong register for our tone.');
});

it('keeps a report it cannot trace to one string, for staff to look at', function () {
    $report = app(Reports::class)->report('es', 'texto que no existe', $this->member, notes: 'Odd.');

    expect($report->key_id)->toBeNull()
        ->and(app(Reports::class)->open(Viewer::for(new GenericUser(['id' => 'reviewer']))))->toHaveCount(1);

    app(Reports::class)->dismiss($report->id, new GenericUser(['id' => 'reviewer']));

    expect($report->refresh()->status)->toBe('dismissed')
        ->and(app(Reports::class)->open(Viewer::for(new GenericUser(['id' => 'reviewer']))))->toHaveCount(0);
});

it('attaches straight to a key the host names', function () {
    $report = app(Reports::class)->report('es', 'anything at all', $this->member, suggestion: 'Otra cosa.', keyRef: 'auth.failed');

    expect($report->key_id)->toBe(($this->failed)()->key_id);
});

it('refuses a second open report from the same member on the same string, and one in the source language', function () {
    app(Reports::class)->report('es', 'nuestros registros', $this->member);

    expect(fn () => app(Reports::class)->report('es', 'nuestros registros', $this->member))->toThrow(ProsettaException::class)
        ->and(fn () => app(Reports::class)->report('en', 'our records', $this->member))->toThrow(ProsettaException::class)
        ->and(fn () => app(Reports::class)->report('es', 'no', $this->member))->toThrow(ProsettaException::class);
});

it('closes a report as accepted when its translation is approved, and as dismissed when rejected', function () {
    $report = app(Reports::class)->report('es', 'nuestros registros', $this->member, suggestion: 'Estas credenciales no son correctas.');
    $reviewer = new GenericUser(['id' => 'reviewer']);

    app(ReviewService::class)->approve([($this->failed)()->id], $reviewer);
    expect($report->refresh()->status)->toBe('accepted')
        ->and($report->resolved_by)->toBe('reviewer');

    $second = app(Reports::class)->report('es', 'no son correctas', new GenericUser(['id' => 'member-2']), suggestion: 'Credenciales incorrectas.');
    app(ReviewService::class)->reject(($this->failed)()->id, $reviewer, 'Keep the approved wording.');

    expect($second->refresh()->status)->toBe('dismissed')
        ->and(TranslationReport::query()->where('status', 'open')->count())->toBe(0);
});

it('never overwrites work already waiting for review; it adds the report to it', function () {
    $translation = ($this->failed)();
    $translation->update(['value' => 'Un borrador de la IA.', 'status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Ai]);

    $report = app(Reports::class)->report('es', 'nuestros registros', $this->member, suggestion: 'Credenciales incorrectas.', notes: 'Stiff.');
    $translation->refresh();

    expect($translation->value)->toBe('Un borrador de la IA.')
        ->and($translation->origin)->toBe(TranslationOrigin::Ai)
        ->and(collect($translation->issues)->firstWhere('code', 'reported')['message'])->toContain('Credenciales incorrectas.')
        ->and($report->queued)->toBeTrue();
});

it('lists for staff, rather than queueing, a report on a string that is stale or locked here', function () {
    $key = app(KeyFinder::class)->find('auth.failed');
    $key->update(['source_value' => 'These credentials are wrong.', 'source_hash' => sha1('These credentials are wrong.')]);

    $stale = app(Reports::class)->report('es', 'nuestros registros', $this->member, suggestion: 'Credenciales incorrectas.');

    expect($stale->queued)->toBeFalse()
        ->and(($this->failed)()->status)->toBe(TranslationStatus::Approved);

    config(['prosetta.review.editable' => false]);
    $locked = app(Reports::class)->report('es', 'credenciales', new GenericUser(['id' => 'member-2']), keyRef: 'auth.failed', suggestion: 'Otra.');

    expect($locked->queued)->toBeFalse()
        ->and(collect(app(Reports::class)->unqueued(Viewer::for(new GenericUser(['id' => 'reviewer']))))->pluck('id')->all())
        ->toEqualCanonicalizing([$stale->id, $locked->id]);
});

it('leaves reports open when the system, not a person, approves the string', function () {
    $report = app(Reports::class)->report('es', 'nuestros registros', $this->member, suggestion: 'Estas credenciales no son correctas.');

    app(ReviewService::class)->approve([($this->failed)()->id], null);

    expect($report->refresh()->status)->toBe('open');
});

it('lets no one approve their own reported wording where self-approval is off', function () {
    config(['prosetta.review.allow_self_approval' => false]);
    app(Reports::class)->report('es', 'nuestros registros', $this->member, suggestion: 'Estas credenciales no son correctas.');

    $result = app(ReviewService::class)->approve([($this->failed)()->id], $this->member);

    expect($result->skipped[($this->failed)()->id] ?? null)->toBe('self_approval');
});

it('leaves reported strings out of approve-matching, even with warnings included', function () {
    app(Reports::class)->report('es', 'nuestros registros', $this->member, suggestion: 'Estas credenciales no son correctas.');

    $report = app(ReviewDesk::class)->approveMatching(Viewer::for(new GenericUser(['id' => 'reviewer'])), ['locale' => 'es'], includeWarnings: true);

    expect($report->approved)->toBe(0)
        ->and(($this->failed)()->status)->toBe(TranslationStatus::NeedsReview);
});

it('drops the reported warning once a person approves', function () {
    app(Reports::class)->report('es', 'nuestros registros', $this->member, suggestion: 'Estas credenciales no son correctas.');

    app(ReviewService::class)->approve([($this->failed)()->id], new GenericUser(['id' => 'reviewer']));

    expect(collect(($this->failed)()->issues)->firstWhere('code', 'reported'))->toBeNull();
});
