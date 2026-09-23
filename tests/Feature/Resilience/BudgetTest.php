<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Events\BudgetReached;
use LonelyLights\Prosetta\Resilience\Budget;

beforeEach(function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    Event::fake([BudgetReached::class]);
});

it('never stops a run when no budget is set', function () {
    app(Budget::class)->record('run-1', 10_000_000);

    expect(app(Budget::class)->exhausted('run-1'))->toBeNull();
});

it('stops the next call once a run has used its budget, and says so once', function () {
    config(['prosetta.budgets.per_run' => 1000]);
    $budget = app(Budget::class);

    $budget->record('run-1', 600);
    expect($budget->exhausted('run-1'))->toBeNull();

    $budget->record('run-1', 600);
    $budget->record('run-1', 10);

    expect($budget->exhausted('run-1'))->toBe('per_run')
        ->and($budget->exhausted('run-2'))->toBeNull();
    Event::assertDispatchedTimes(BudgetReached::class, 1);
    Event::assertDispatched(BudgetReached::class, fn (BudgetReached $event) => $event->period === 'per_run' && $event->used === 1200 && $event->limit === 1000);
});

it('counts daily spending across runs and starts again the next day', function () {
    config(['prosetta.budgets.daily' => 1000]);
    $budget = app(Budget::class);
    $budget->record('run-1', 700);
    $budget->record(null, 400);

    expect($budget->exhausted('run-3'))->toBe('daily');

    $this->travelTo(now()->addDay()->startOfDay()->addMinute());

    expect($budget->exhausted('run-3'))->toBeNull();
});

it('counts monthly spending until the 1st', function () {
    $this->travelTo(now()->startOfMonth()->addDays(3));
    config(['prosetta.budgets.monthly' => 500]);
    app(Budget::class)->record(null, 500);

    expect(app(Budget::class)->exhausted(null))->toBe('monthly');

    $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());

    expect(app(Budget::class)->exhausted(null))->toBeNull();
});

it('reports usage against each limit', function () {
    config(['prosetta.budgets.daily' => 1000, 'prosetta.budgets.per_run' => 50]);
    app(Budget::class)->record('run-1', 40);

    expect(app(Budget::class)->usage('run-1'))->toBe([
        'daily' => ['used' => 40, 'limit' => 1000],
        'monthly' => ['used' => 40, 'limit' => null],
        'per_run' => ['used' => 40, 'limit' => 50],
    ]);
});

it('names the daily or monthly budget before the per-run one, so a run over both is suspended', function () {
    config(['prosetta.budgets.per_run' => 100, 'prosetta.budgets.daily' => 100, 'prosetta.budgets.monthly' => 100]);
    app(Budget::class)->record('run-1', 150);

    expect(app(Budget::class)->exhausted('run-1'))->toBe('daily');

    config(['prosetta.budgets.daily' => null]);

    expect(app(Budget::class)->exhausted('run-1'))->toBe('monthly');

    config(['prosetta.budgets.monthly' => null]);

    expect(app(Budget::class)->exhausted('run-1'))->toBe('per_run');
});
