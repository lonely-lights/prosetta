<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationItem;
use LonelyLights\Prosetta\Events\TranslationHalted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderQuotaExhausted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use LonelyLights\Prosetta\Resilience\BudgetExhausted;
use LonelyLights\Prosetta\Resilience\CallDeferred;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\ProviderGate;
use LonelyLights\Prosetta\Testing\HealthCheckedScriptedDriver;
use LonelyLights\Prosetta\Testing\ScriptedDriver;

beforeEach(function () {
    config([
        'prosetta.resilience.cache_store' => 'array',
        'prosetta.resilience.jitter' => 0,
        'prosetta.resilience.circuit' => ['failure_threshold' => 2, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 3600],
    ]);
    Event::fake([TranslationHalted::class]);
});

function gateBatch(): TranslationBatch {
    return new TranslationBatch('en', new LocaleDescriptor('es', 'Spanish', 'Español'), null, null, [new TranslationItem('1', 'auth.failed', 'Hello')]);
}

function callThrough(ScriptedDriver $driver, ?string $runId = null) {
    return app(ProviderGate::class)->call($driver, 'scripted:m', $runId, fn () => $driver->translate(gateBatch()));
}

it('passes a successful call through and counts its tokens against the budget', function () {
    config(['prosetta.budgets.per_run' => 5]);
    $result = callThrough(new ScriptedDriver, 'run-1');

    expect($result->values)->toBe(['1' => 'Hello [es]'])
        ->and(fn () => callThrough(new ScriptedDriver, 'run-1'))->toThrow(BudgetExhausted::class);
});

it('names the circuit on the exception and opens the circuit after repeated outages', function () {
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));

    try {
        callThrough($driver);
    } catch (ProviderUnavailable $e) {
        expect($e->circuit)->toBe('scripted:m');
    }

    expect(fn () => callThrough($driver))->toThrow(ProviderUnavailable::class)
        ->and(fn () => callThrough($driver))->toThrow(CallDeferred::class)
        ->and($driver->calls)->toHaveCount(2);
});

it('treats an unknown error as an outage by default, and as a halt when configured', function () {
    $driver = (new ScriptedDriver)->fail(new RuntimeException('weird'));
    expect(fn () => callThrough($driver))->toThrow(ProviderUnavailable::class, 'weird');

    config(['prosetta.resilience.unknown_errors' => 'halt']);
    $driver = (new ScriptedDriver)->fail(new RuntimeException('weird'));
    expect(fn () => callThrough($driver))->toThrow(ProviderRejected::class, 'weird');
    Event::assertDispatched(TranslationHalted::class, fn (TranslationHalted $event) => $event->reason === 'unknown');
});

it('halts once on a rejected key or exhausted quota and holds every later call', function (Throwable $error, string $reason) {
    $driver = (new ScriptedDriver)->fail($error);

    expect(fn () => callThrough($driver))->toThrow($error::class);

    try {
        callThrough($driver);
    } catch (CallDeferred $deferred) {
        expect($deferred->reason)->toBe('held');
    }

    expect($driver->calls)->toHaveCount(1);
    Event::assertDispatchedTimes(TranslationHalted::class, 1);
    Event::assertDispatched(TranslationHalted::class, fn (TranslationHalted $event) => $event->reason === $reason);
})->with([
    'rejected' => [new ProviderRejected('bad key'), 'rejected'],
    'quota' => [new ProviderQuotaExhausted('no credits'), 'quota'],
]);

it('tests an open circuit with the health check before spending a real call', function () {
    $driver = (new HealthCheckedScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    rescue(fn () => callThrough($driver), report: false);
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(301)->seconds();

    $driver->failHealth(new ProviderUnavailable('still down'));
    expect(fn () => callThrough($driver))->toThrow(CallDeferred::class)
        ->and($driver->healthChecks)->toBe(1)
        ->and($driver->calls)->toHaveCount(2);

    $this->travel(601)->seconds();
    $result = callThrough($driver);

    expect($result->values)->toBe(['1' => 'Hello [es]'])
        ->and($driver->healthChecks)->toBe(2)
        ->and(app(Circuits::class)->for('scripted:m')->decision()->kind)->toBe('call');
});

it('uses the real call as the test when the driver has no health check', function () {
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    rescue(fn () => callThrough($driver), report: false);
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(301)->seconds();

    expect(callThrough($driver)->values)->toBe(['1' => 'Hello [es]'])
        ->and(app(Circuits::class)->for('scripted:m')->decision()->kind)->toBe('call');
});

it('flags a deferral once the outage has lasted longer than outage_timeout', function () {
    config(['prosetta.resilience.outage_timeout' => 600]);
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    rescue(fn () => callThrough($driver), report: false);
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(601)->seconds();
    app(Circuits::class)->for('scripted:m')->recordFailure('still down', app(Circuits::class)->for('scripted:m')->decision());

    try {
        callThrough($driver);
    } catch (CallDeferred $deferred) {
        expect($deferred->outage)->toBeTrue();
    }
});
