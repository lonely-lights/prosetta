<?php

use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\UsageLedger;
use LonelyLights\Prosetta\Support\State;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    config(['prosetta.resilience.cache_store' => 'array']);
});

it('is healthy when automation is off and nothing is wrong', function () {
    config(['prosetta.automation.every' => null]);

    $this->artisan('prosetta:health')->expectsOutputToContain('healthy')->assertExitCode(0);
});

it('is unhealthy when automation is on and last cycle is missing', function () {
    config(['prosetta.automation.every' => 30]);

    $this->artisan('prosetta:health')
        ->expectsOutputToContain('cycle')
        ->assertExitCode(1);
});

it('is unhealthy when automation is on and last cycle is older than 3 times every', function () {
    config(['prosetta.automation.every' => 30]);
    State::put('cycle.last_run', now()->subMinutes(91)->getTimestamp());

    $this->artisan('prosetta:health')
        ->expectsOutputToContain('cycle')
        ->assertExitCode(1);
});

it('is healthy when automation is on and last cycle is recent', function () {
    config(['prosetta.automation.every' => 30]);
    State::put('cycle.last_run', now()->subMinutes(80)->getTimestamp());

    $this->artisan('prosetta:health')->expectsOutputToContain('healthy')->assertExitCode(0);
});

it('is unhealthy when a circuit is halted', function () {
    config(['prosetta.automation.every' => null]);
    $circuit = app(Circuits::class)->for('test-circuit');
    $circuit->trip('rejected', 'bad key');

    $this->artisan('prosetta:health')
        ->expectsOutputToContain('halted')
        ->assertExitCode(1);
});

it('is unhealthy when the daily budget is exhausted', function () {
    config(['prosetta.automation.every' => null, 'prosetta.budgets.daily' => 10]);
    app(UsageLedger::class)->record(null, 'test', 'es', 10, 0);

    $this->artisan('prosetta:health')
        ->expectsOutputToContain('daily')
        ->assertExitCode(1);
});

it('is unhealthy when the monthly budget is exhausted', function () {
    config(['prosetta.automation.every' => null, 'prosetta.budgets.monthly' => 10]);
    app(UsageLedger::class)->record(null, 'test', 'es', 10, 0);

    $this->artisan('prosetta:health')
        ->expectsOutputToContain('monthly')
        ->assertExitCode(1);
});

it('shows the last cycle time in prosetta:circuit', function () {
    State::put('cycle.last_run', now()->subMinutes(5)->getTimestamp());

    $this->artisan('prosetta:circuit')->expectsOutputToContain('Last cycle:')->assertSuccessful();
});

it('starts each problem line with a stable code, so the output stays the same as the minutes go by', function () {
    config(['prosetta.automation.every' => 30, 'prosetta.budgets.daily' => 10]);
    State::put('cycle.last_run', now()->subMinutes(200)->getTimestamp());
    app(Circuits::class)->for('test-circuit')->trip('rejected', 'bad key');
    app(UsageLedger::class)->record(null, 'test', 'es', 10, 0);

    Illuminate\Support\Facades\Artisan::call('prosetta:health');
    $lines = array_values(array_filter(array_map('trim', explode("\n", Illuminate\Support\Facades\Artisan::output()))));

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toStartWith('[cycle_stale] ')->toContain('200 minutes')
        ->and($lines[1])->toStartWith('[circuit_halted:test-circuit] ')
        ->and($lines[2])->toStartWith('[budget:daily] ');
});

it('codes a missing first cycle as stale too', function () {
    config(['prosetta.automation.every' => 30]);

    Illuminate\Support\Facades\Artisan::call('prosetta:health');

    expect(trim(Illuminate\Support\Facades\Artisan::output()))->toStartWith('[cycle_stale] ');
});
