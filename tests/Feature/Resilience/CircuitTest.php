<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Events\CircuitClosed;
use LonelyLights\Prosetta\Events\CircuitOpened;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;

beforeEach(function () {
    config([
        'prosetta.resilience.cache_store' => 'array',
        'prosetta.resilience.jitter' => 0,
        'prosetta.resilience.circuit' => ['failure_threshold' => 3, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 1000],
    ]);
    Event::fake([CircuitOpened::class, CircuitClosed::class]);
    $this->circuit = app(Circuits::class)->for('fake:model');
});

it('defaults to closed for an unknown circuit', function () {
    expect($this->circuit->state()['state'])->toBe('closed')
        ->and($this->circuit->decision()->kind)->toBe('call');
});

it('names a circuit after the driver and model', function () {
    expect(Circuits::nameFor(new FakeTranslationDriver, 'gpt-x'))->toBe('fake-translation-driver:gpt-x')
        ->and(Circuits::nameFor(new FakeTranslationDriver, null))->toBe('fake-translation-driver:default');
});

it('opens after the threshold of consecutive failures, once', function () {
    $this->circuit->recordFailure('boom');
    $this->circuit->recordFailure('boom');
    expect($this->circuit->decision()->kind)->toBe('call');

    $this->circuit->recordFailure('boom');
    $decision = $this->circuit->decision();

    expect($decision->kind)->toBe('wait')
        ->and($decision->seconds)->toBe(300);
    Event::assertDispatchedTimes(CircuitOpened::class, 1);
});

it('forgets failures after a success', function () {
    $this->circuit->recordFailure('boom');
    $this->circuit->recordFailure('boom');
    $this->circuit->recordSuccess();
    $this->circuit->recordFailure('boom');

    expect($this->circuit->decision()->kind)->toBe('call');
});

it('lets exactly one caller test once the cooldown is over', function () {
    foreach (range(1, 3) as $ignored) {
        $this->circuit->recordFailure('boom');
    }
    $this->travel(301)->seconds();

    $first = app(Circuits::class)->for('fake:model')->decision();
    $second = app(Circuits::class)->for('fake:model')->decision();

    expect($first->kind)->toBe('test')
        ->and($second->kind)->toBe('wait');
});

it('closes on a successful test and reports the downtime', function () {
    foreach (range(1, 3) as $ignored) {
        $this->circuit->recordFailure('boom');
    }
    $this->travel(301)->seconds();
    $test = $this->circuit->decision();
    $this->circuit->recordSuccess($test);

    expect($this->circuit->decision()->kind)->toBe('call');
    Event::assertDispatched(CircuitClosed::class, fn (CircuitClosed $event) => $event->circuit === 'fake:model' && $event->downtime >= 301);
});

it('re-opens on a failed test with a longer cooldown, capped at the maximum', function () {
    foreach (range(1, 3) as $ignored) {
        $this->circuit->recordFailure('boom');
    }

    foreach ([600, 1000, 1000] as $expected) {
        $this->travel($this->circuit->decision()->seconds + 1)->seconds();
        $test = $this->circuit->decision();
        expect($test->kind)->toBe('test');
        $this->circuit->recordFailure('still down', $test);
        expect($this->circuit->decision()->seconds)->toBe($expected);
    }

    Event::assertDispatchedTimes(CircuitOpened::class, 1);
});

it('trips on a halt, holding for halt_hold or until reset', function () {
    config(['prosetta.resilience.halt_hold' => null]);
    expect($this->circuit->trip('rejected', 'bad key'))->toBeTrue()
        ->and($this->circuit->trip('rejected', 'bad key'))->toBeFalse()
        ->and($this->circuit->decision()->kind)->toBe('held');

    $this->circuit->reset();
    config(['prosetta.resilience.halt_hold' => 600]);
    $this->circuit->trip('quota', 'no credits');

    expect($this->circuit->decision()->kind)->toBe('wait')
        ->and($this->circuit->decision()->seconds)->toBe(600)
        ->and($this->circuit->state()['halt'])->toBe('quota');

    $this->travel(601)->seconds();
    $test = $this->circuit->decision();
    $this->circuit->recordFailure('still no credits', $test);

    expect($this->circuit->decision()->seconds)->toBe(600);
});

it('knows when an outage has lasted longer than outage_timeout', function () {
    config(['prosetta.resilience.outage_timeout' => 3600]);
    foreach (range(1, 3) as $ignored) {
        $this->circuit->recordFailure('boom');
    }
    expect($this->circuit->outageExceeded())->toBeFalse();

    $this->travel(3601)->seconds();

    expect($this->circuit->outageExceeded())->toBeTrue();
});

it('remembers every circuit it has handed out', function () {
    app(Circuits::class)->for('other:model');

    expect(app(Circuits::class)->names())->toBe(['fake:model', 'other:model']);
});
