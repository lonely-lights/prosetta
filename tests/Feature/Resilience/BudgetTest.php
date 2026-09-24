<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Events\BudgetReached;
use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\UsageLedger;

beforeEach(function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    Event::fake([BudgetReached::class]);
});

/** Writes a ledger row and runs the gate's post-call check, as ProviderGate does for a real call. */
function spend(?string $runId, int $tokens, string $locale = 'es'): void {
    app(UsageLedger::class)->record($runId, 'c', $locale, $tokens, 0);
    app(Budget::class)->record($runId);
}

it('never stops a run when no budget is set', function () {
    spend('run-1', 10_000_000);

    expect(app(Budget::class)->exhausted('run-1'))->toBeNull();
});

it('stops the next call once a run has used its budget, and says so once', function () {
    config(['prosetta.budgets.per_run' => 1000]);

    spend('run-1', 600);
    expect(app(Budget::class)->exhausted('run-1'))->toBeNull();

    spend('run-1', 600);
    spend('run-1', 10);

    expect(app(Budget::class)->exhausted('run-1'))->toBe('per_run')
        ->and(app(Budget::class)->exhausted('run-2'))->toBeNull();
    Event::assertDispatchedTimes(BudgetReached::class, 1);
    Event::assertDispatched(BudgetReached::class, fn (BudgetReached $event) => $event->period === 'per_run' && $event->used === 1200 && $event->limit === 1000);
});

it('counts daily spending across runs and starts again the next day', function () {
    config(['prosetta.budgets.daily' => 1000]);
    spend('run-1', 700);
    spend(null, 400);

    expect(app(Budget::class)->exhausted('run-3'))->toBe('daily');

    $this->travelTo(now()->addDay()->startOfDay()->addMinute());

    expect(app(Budget::class)->exhausted('run-3'))->toBeNull();
});

it('counts monthly spending until the 1st', function () {
    $this->travelTo(now()->startOfMonth()->addDays(3));
    config(['prosetta.budgets.monthly' => 500]);
    spend(null, 500);

    expect(app(Budget::class)->exhausted(null))->toBe('monthly');

    $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());

    expect(app(Budget::class)->exhausted(null))->toBeNull();
});

it('reports usage against each limit', function () {
    config(['prosetta.budgets.daily' => 1000, 'prosetta.budgets.per_run' => 50]);
    spend('run-1', 40);

    expect(app(Budget::class)->usage('run-1'))->toBe([
        'daily' => ['used' => 40, 'limit' => 1000],
        'monthly' => ['used' => 40, 'limit' => null],
        'per_run' => ['used' => 40, 'limit' => 50],
    ]);
});

it('names the daily or monthly budget before the per-run one, so a run over both is suspended', function () {
    config(['prosetta.budgets.per_run' => 100, 'prosetta.budgets.daily' => 100, 'prosetta.budgets.monthly' => 100]);
    spend('run-1', 150);

    expect(app(Budget::class)->exhausted('run-1'))->toBe('daily');

    config(['prosetta.budgets.daily' => null]);

    expect(app(Budget::class)->exhausted('run-1'))->toBe('monthly');

    config(['prosetta.budgets.monthly' => null]);

    expect(app(Budget::class)->exhausted('run-1'))->toBe('per_run');
});

it('keeps counting spending after the cache is cleared', function () {
    config(['prosetta.budgets.daily' => 1000]);
    app(UsageLedger::class)->record('run-1', 'c', 'es', 600, 500);

    \Illuminate\Support\Facades\Cache::store('array')->flush();

    expect(app(Budget::class)->exhausted('run-2'))->toBe('daily');
});
