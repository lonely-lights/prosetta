<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use LonelyLights\Prosetta\Events\BudgetReached;
use LonelyLights\Prosetta\Events\CircuitClosed;
use LonelyLights\Prosetta\Events\CircuitOpened;
use LonelyLights\Prosetta\Events\TranslationHalted;
use LonelyLights\Prosetta\Events\TranslationResumed;
use LonelyLights\Prosetta\Events\TranslationSuspended;

/**
 * One log line per resilience event, on prosetta.log_channel (null = the
 * default channel). Circuit transitions dispatch CircuitOpened/CircuitClosed
 * from inside a write lock, so this only logs: it never calls back into a
 * circuit.
 */
final class LogResilienceEvents {
    public function subscribe(Dispatcher $events): void {
        $events->listen(CircuitOpened::class, fn (CircuitOpened $e) => $this->log('warning', "Circuit [$e->circuit] opened after $e->failures failures; next test in $e->cooldown s. Last error: $e->message"));
        $events->listen(CircuitClosed::class, fn (CircuitClosed $e) => $this->log('info', "Circuit [$e->circuit] closed after $e->downtime s of downtime."));
        $events->listen(TranslationHalted::class, fn (TranslationHalted $e) => $this->log('warning', "Translation halted on [$e->circuit] ($e->reason): $e->message"));
        $events->listen(TranslationSuspended::class, fn (TranslationSuspended $e) => $this->log('warning', "Translation suspended on [$e->circuit] ($e->reason) for ".implode(', ', $e->scope->locales ?: ['every locale']).'.'));
        $events->listen(TranslationResumed::class, fn (TranslationResumed $e) => $this->log('info', "Resumed $e->scopes suspended run(s) on [$e->circuit]."));
        $events->listen(BudgetReached::class, fn (BudgetReached $e) => $this->log('warning', "The $e->period token budget is used up ($e->used / $e->limit)."));
    }

    private function log(string $level, string $message): void {
        Log::channel(config('prosetta.log_channel'))->{$level}("[prosetta] $message");
    }
}
