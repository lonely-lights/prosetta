<?php

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\MissingDriverException;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;
use LonelyLights\Prosetta\Testing\ScriptedDriver;
use LonelyLights\Prosetta\Translation\TranslationRunner;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

function fakeDriver(): FakeTranslationDriver {
    $fake = new FakeTranslationDriver;
    app()->instance(TranslationDriver::class, $fake);

    return $fake;
}

function keyIds(string ...$refs): array {
    return array_map(fn (string $ref) => app(KeyFinder::class)->find($ref)->id, $refs);
}

it('drafts missing keys with provenance and a review row', function () {
    fakeDriver();
    config()->set('prosetta.ai.model', 'gpt-test');
    $ids = keyIds('identity::onboarding.toast.accessCode.capReached', 'identity::onboarding.toast.accessCode.timedOut');

    $report = app(TranslationRunner::class)->run('es', $ids);
    $draft = Translation::query()->where('key_id', $ids[0])->where('locale', 'es')->first();

    expect($report->drafted)->toBe(['es identity::onboarding.toast.accessCode.capReached', 'es identity::onboarding.toast.accessCode.timedOut'])
        ->and($draft->value)->toBe('Registration has a :minutes-minute limit, so the access code was released. Enter it again to start over. [es]')
        ->and($draft->status)->toBe(TranslationStatus::Draft)
        ->and($draft->origin)->toBe(TranslationOrigin::Ai)
        ->and($draft->ai_provider)->toBe('fake')
        ->and($draft->ai_model)->toBe('gpt-test')
        ->and($draft->ai_invocation_id)->toBe('fake-1')
        ->and($draft->approved_value)->toBeNull()
        ->and($draft->reviews()->first()->action)->toBe(ReviewAction::Submitted)
        ->and($report->inputTokens)->toBe((int) Translation::query()->where('locale', 'es')->sum('input_tokens'));
});

it('apportions tokens exactly across a call', function () {
    expect(TranslationRunner::apportion(10, ['a' => 1, 'b' => 1, 'c' => 1]))->toBe(['a' => 4, 'b' => 3, 'c' => 3])
        ->and(array_sum(TranslationRunner::apportion(1234, ['x' => 17, 'y' => 250])))->toBe(1234);
});

it('skips keys that do not need work unless forced', function () {
    $fake = fakeDriver();
    $ids = keyIds('identity::onboarding.toast.accessCode.inUse');

    expect(app(TranslationRunner::class)->run('es', $ids)->skipped)->toBe(1)
        ->and($fake->calls)->toBe([])
        ->and(app(TranslationRunner::class)->run('es', $ids, force: true)->drafted)->toHaveCount(1);
});

it('retries once with feedback and keeps the fix', function () {
    $fake = fakeDriver()->fixOnRetry();

    $report = app(TranslationRunner::class)->run('es', keyIds('identity::onboarding.toast.accessCode.capReached'));

    expect($fake->calls)->toHaveCount(2)
        ->and(array_values($fake->calls[1]->feedback)[0][0])->toContain(':minutes')
        ->and($report->withIssues)->toBe([])
        ->and(Translation::query()->where('locale', 'es')->latest('id')->first()->value)->toContain(':minutes');
});

it('saves a still-broken result as a draft with its issues', function () {
    fakeDriver()->dropPlaceholders();

    $report = app(TranslationRunner::class)->run('es', keyIds('identity::onboarding.toast.accessCode.capReached'));
    $draft = Translation::query()->where('locale', 'es')->latest('id')->first();

    expect($report->withIssues)->toBe(['es identity::onboarding.toast.accessCode.capReached'])
        ->and($draft->hasBlockingIssues())->toBeTrue()
        ->and($draft->status)->toBe(TranslationStatus::Draft);
});

it('reports keys the driver returned nothing for', function () {
    fakeDriver()->omitValues();

    expect(app(TranslationRunner::class)->run('es', keyIds('identity::onboarding.toast.accessCode.capReached'))->failed)
        ->toBe(['es identity::onboarding.toast.accessCode.capReached']);
});

it('tells the driver about variants and per-locale models', function () {
    $fake = fakeDriver();
    config()->set('prosetta.ai.models', ['en_GB' => 'small-model']);

    app(TranslationRunner::class)->run('en_GB', keyIds('auth.failed'));

    expect($fake->calls[0]->variantOf)->toBe('en')
        ->and($fake->calls[0]->model)->toBe('small-model')
        ->and($fake->calls[0]->target->englishName)->toBe('British English');
});

it('explains a missing driver', function () {
    expect(fn () => app(TranslationRunner::class)->run('es', keyIds('auth.throttle')))->toThrow(MissingDriverException::class);
});

it('records strings the provider refused as failed, without retrying them', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    $driver = (new ScriptedDriver)->refuse('These credentials do not match our records.');
    app()->instance(TranslationDriver::class, $driver);

    // auth.failed already has an approved es translation imported from the fixture's es/auth.php,
    // so it needs force: true here to actually reach the driver instead of being skipped as up to date.
    $report = app(TranslationRunner::class)->run('es', keyIds('auth.failed', 'auth.throttle'), force: true);

    expect($report->refused)->toBe(['es auth.failed'])
        ->and($report->failed)->toBe(['es auth.failed'])
        ->and($report->drafted)->toBe(['es auth.throttle'])
        ->and($driver->calls)->toHaveCount(1);
});

it('keeps the first attempt\'s drafts when the retry call fails', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    $driver = new class extends ScriptedDriver {
        public function translate(\LonelyLights\Prosetta\Data\TranslationBatch $batch): \LonelyLights\Prosetta\Data\TranslationBatchResult {
            if ($batch->feedback !== []) {
                $this->calls[] = $batch;

                throw new ProviderUnavailable('down during the retry');
            }

            $result = parent::translate($batch);

            return new \LonelyLights\Prosetta\Data\TranslationBatchResult(
                array_map(fn (string $value) => str_replace(':name', '', $value), $result->values),
                $result->provider, $result->model, $result->inputTokens, $result->outputTokens,
            );
        }
    };
    app()->instance(TranslationDriver::class, $driver);
    $ids = keyIds('messages.welcome');

    expect(fn () => app(TranslationRunner::class)->run('es', $ids))->toThrow(ProviderUnavailable::class);
    expect(Translation::query()->where('key_id', $ids[0])->where('locale', 'es')->value('status'))->toBe(TranslationStatus::Draft);
});

it('names the run it belongs to when counting tokens', function () {
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.budgets.per_run' => 1]);
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    // auth.failed already has an approved es translation imported from the fixture's es/auth.php,
    // so it needs force: true here to actually reach the driver and record tokens against run-9.
    app(TranslationRunner::class)->run('es', keyIds('auth.failed'), force: true, runId: 'run-9');

    expect(fn () => app(TranslationRunner::class)->run('es', keyIds('auth.throttle'), runId: 'run-9'))
        ->toThrow(\LonelyLights\Prosetta\Resilience\BudgetExhausted::class);
});
