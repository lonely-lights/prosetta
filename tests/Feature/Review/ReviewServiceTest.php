<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\TranslationApproved;
use LonelyLights\Prosetta\Events\TranslationRejected;
use LonelyLights\Prosetta\Events\TranslationSubmitted;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $locale === 'es');
});

function draftFor(string $ref, string $locale, string $value): Translation {
    $key = app(KeyFinder::class)->find($ref);

    return Translation::query()->create([
        'key_id' => $key->id, 'locale' => $locale, 'value' => $value, 'source_hash' => $key->source_hash,
        'status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Ai,
    ]);
}

it('edits a candidate into needs-review and records who did it', function () {
    Event::fake([TranslationSubmitted::class]);
    $draft = draftFor('identity::onboarding.toast.accessCode.capReached', 'es', 'Borrador');

    $edited = app(ReviewService::class)->edit($draft->id, 'El registro tiene un límite de :minutes minutos.', $this->user('ana'), 'Tightened');
    $review = $edited->reviews()->latest('id')->first();

    expect($edited->status)->toBe(TranslationStatus::NeedsReview)
        ->and($edited->origin)->toBe(TranslationOrigin::Manual)
        ->and($edited->issues)->toBeNull()
        ->and($review->action)->toBe(ReviewAction::Edited)
        ->and($review->reviewer_id)->toBe('ana')
        ->and($review->previous_value)->toBe('Borrador');
    Event::assertDispatched(TranslationSubmitted::class);
});

it('approves a clean candidate into the live value', function () {
    Event::fake([TranslationApproved::class]);
    $draft = draftFor('identity::onboarding.toast.accessCode.capReached', 'es', 'El registro tiene un límite de :minutes minutos.');

    $report = app(ReviewService::class)->approve($draft->id, $this->user('ana'));
    $draft->refresh();

    expect($report->approved)->toBe([$draft->id])
        ->and($draft->approved_value)->toBe('El registro tiene un límite de :minutes minutos.')
        ->and($draft->approved_source_hash)->toBe($draft->source_hash)
        ->and($draft->status)->toBe(TranslationStatus::Approved)
        ->and($draft->reviewed_by)->toBe('ana');
    Event::assertDispatched(TranslationApproved::class);
});

it('refuses to approve candidates with blocking issues, rejected ones and empty ones', function () {
    $broken = draftFor('identity::onboarding.toast.accessCode.capReached', 'es', 'Sin marcador.');
    $broken->update(['issues' => [['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'x']]]);
    $rejected = draftFor('identity::onboarding.toast.accessCode.timedOut', 'es', 'x');
    $rejected->update(['status' => TranslationStatus::Rejected]);

    $report = app(ReviewService::class)->approve([$broken->id, $rejected->id], $this->user());

    expect($report->approved)->toBe([])
        ->and($report->skipped)->toBe([$broken->id => 'issues', $rejected->id => 'rejected']);
});

it('rejects a candidate without touching the live value', function () {
    Event::fake([TranslationRejected::class]);
    $failed = app(KeyFinder::class)->find('auth.failed')->translations->firstWhere('locale', 'es');
    $failed->update(['value' => 'Mal', 'status' => TranslationStatus::NeedsReview]);

    $rejected = app(ReviewService::class)->reject($failed->id, $this->user(), 'Too curt');

    expect($rejected->status)->toBe(TranslationStatus::Rejected)
        ->and($rejected->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.');
    Event::assertDispatched(TranslationRejected::class);
});

it('edits and approves in one step', function () {
    $draft = draftFor('identity::onboarding.toast.accessCode.timedOut', 'es', 'x');

    $done = app(ReviewService::class)->edit($draft->id, 'Tu registro estuvo en pausa.', $this->user(), approve: true);

    expect($done->status)->toBe(TranslationStatus::Approved)
        ->and($done->approved_value)->toBe('Tu registro estuvo en pausa.');
});

it('can require a second person', function () {
    config()->set('prosetta.review.allow_self_approval', false);
    $draft = draftFor('identity::onboarding.toast.accessCode.timedOut', 'es', 'x');
    app(ReviewService::class)->edit($draft->id, 'Tu registro estuvo en pausa.', $this->user('ana'));

    expect(app(ReviewService::class)->approve($draft->id, $this->user('ana'))->skipped)->toBe([$draft->id => 'self_approval'])
        ->and(app(ReviewService::class)->approve($draft->id, $this->user('ben'))->approved)->toBe([$draft->id]);
});

it('checks the locale on every action', function () {
    $arabic = draftFor('identity::onboarding.toast.accessCode.timedOut', 'ar', 'x');

    expect(fn () => app(ReviewService::class)->approve($arabic->id, $this->user()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReviewService::class)->edit($arabic->id, 'y', $this->user()))->toThrow(AuthorizationException::class);
});

it('bulk-approves every clean current candidate for a locale', function () {
    $good = draftFor('identity::onboarding.toast.accessCode.capReached', 'es', 'Límite de :minutes minutos.');
    $bad = draftFor('identity::onboarding.toast.accessCode.timedOut', 'es', 'x');
    $bad->update(['issues' => [['code' => 'empty_value', 'severity' => 'error', 'message' => 'x']]]);

    $report = app(ReviewService::class)->approveClean('es', 'identity', by: $this->user());

    expect($report->approved)->toBe([$good->id])
        ->and($report->skipped)->toBe([$bad->id => 'issues']);
});

it('authorizes every locale in a bulk approval before approving any', function () {
    $spanish = draftFor('identity::onboarding.toast.accessCode.capReached', 'es', 'Límite de :minutes minutos.');
    $arabic = draftFor('identity::onboarding.toast.accessCode.timedOut', 'ar', 'x');

    expect(fn () => app(ReviewService::class)->approve([$spanish->id, $arabic->id], $this->user()))
        ->toThrow(AuthorizationException::class)
        ->and($spanish->fresh()->status)->toBe(TranslationStatus::Draft);
});
