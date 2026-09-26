<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationReport;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\Reports;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Auth\Authorizer;
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
