<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationItem;
use LonelyLights\Prosetta\Events\CircuitClosed;
use LonelyLights\Prosetta\Events\TranslationHalted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderBatchRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderQuotaExhausted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\BudgetExhausted;
use LonelyLights\Prosetta\Resilience\CallDeferred;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\ProviderGate;
use LonelyLights\Prosetta\Support\Settings;
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

it('records a usage row for a successful call', function () {
    callThrough(new ScriptedDriver, 'run-1');

    $row = \Illuminate\Support\Facades\DB::table(Settings::table('usage'))->first();

    expect($row)->not->toBeNull()
        ->and($row->run_id)->toBe('run-1')
        ->and($row->circuit)->toBe('scripted:m')
        ->and((int) $row->input_tokens)->toBe(5)
        ->and((int) $row->output_tokens)->toBe(5);
});

it('names the circuit on the exception and opens the circuit after repeated outages', function () {
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));

    expect(fn () => callThrough($driver))->toThrow(function (ProviderUnavailable $e) {
        expect($e->circuit)->toBe('scripted:m');
    });

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

    expect(fn () => callThrough($driver))->toThrow(function (CallDeferred $deferred) {
        expect($deferred->reason)->toBe('held');
    });

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

    expect(fn () => callThrough($driver))->toThrow(function (CallDeferred $deferred) {
        expect($deferred->outage)->toBeTrue();
    });
});

it('releases the test lock and keeps counted tokens when a circuit-closed listener throws', function () {
    config(['prosetta.resilience.circuit' => ['failure_threshold' => 1, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 3600]]);
    Event::listen(CircuitClosed::class, function () {
        throw new RuntimeException('listener boom');
    });

    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'));
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(301)->seconds();

    expect(fn () => callThrough($driver))->toThrow(RuntimeException::class, 'listener boom');

    expect(Settings::cacheStore()->lock('prosetta:circuit:scripted:m:test', 1)->get())->toBeTrue()
        ->and(app(Budget::class)->usage()['daily']['used'])->toBeGreaterThan(0);
});

it('does not count waiting on another caller\'s test call as an outage', function () {
    config(['prosetta.resilience.outage_timeout' => 600]);
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    rescue(fn () => callThrough($driver), report: false);
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(601)->seconds();

    $test = app(Circuits::class)->for('scripted:m')->decision();
    expect($test->kind)->toBe('test')
        ->and(app(Circuits::class)->for('scripted:m')->outageExceeded())->toBeTrue();

    expect(fn () => callThrough($driver))->toThrow(function (CallDeferred $deferred) {
        expect($deferred->reason)->toBe('open')
            ->and($deferred->outage)->toBeFalse();
    });

    $test->release();
});

it('rethrows a batch rejection without touching the circuit or halting', function () {
    $driver = (new ScriptedDriver)->fail(new ProviderBatchRejected('context too long'), new ProviderBatchRejected('context too long'), new ProviderBatchRejected('context too long'));

    foreach (range(1, 3) as $ignored) {
        expect(fn () => callThrough($driver))->toThrow(function (ProviderBatchRejected $e) {
            expect($e->circuit)->toBe('scripted:m');
        });
    }

    expect(app(Circuits::class)->for('scripted:m')->state())->toMatchArray(['state' => 'closed', 'failures' => 0])
        ->and(callThrough($driver)->values)->toBe(['1' => 'Hello [es]']);
    Event::assertNotDispatched(TranslationHalted::class);
});

it('leaves an open circuit as it was when its test call is a rejected batch, and frees the test turn', function () {
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'), new ProviderBatchRejected('invalid input'));
    rescue(fn () => callThrough($driver), report: false);
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(301)->seconds();
    $before = app(Circuits::class)->for('scripted:m')->state();

    expect(fn () => callThrough($driver))->toThrow(ProviderBatchRejected::class);

    expect(app(Circuits::class)->for('scripted:m')->state())->toBe($before)
        ->and(app(Circuits::class)->for('scripted:m')->decision()->kind)->toBe('test');
    Event::assertNotDispatched(TranslationHalted::class);
});

it('counts a rejected health check as a failed test', function () {
    $driver = (new HealthCheckedScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    rescue(fn () => callThrough($driver), report: false);
    rescue(fn () => callThrough($driver), report: false);
    $this->travel(301)->seconds();
    $driver->failHealth(new ProviderBatchRejected('bad request'));

    expect(fn () => callThrough($driver))->toThrow(CallDeferred::class);

    expect(app(Circuits::class)->for('scripted:m')->state()['cooldown'])->toBe(600)
        ->and($driver->calls)->toHaveCount(2);
});
