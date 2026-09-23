<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use LonelyLights\Prosetta\Contracts\ChecksHealth;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Events\TranslationHalted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderException;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderQuotaExhausted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use Throwable;

/**
 * Every driver call goes through here: the budget first, then the circuit
 * (waiting, held, or this caller's turn to test, using the driver's health
 * check when it has one), then the call. Failures come out classified, with
 * the circuit opened or tripped; successes close the circuit and count tokens.
 */
final readonly class ProviderGate {
    public function __construct(private Circuits $circuits, private Budget $budget, private Dispatcher $events) {}

    /**
     * @param Closure(): TranslationBatchResult $call
     * @throws ProviderException|CallDeferred|BudgetExhausted
     */
    public function call(TranslationDriver $driver, string $circuit, ?string $runId, Closure $call): TranslationBatchResult {
        if (($period = $this->budget->exhausted($runId)) !== null) {
            throw new BudgetExhausted($period);
        }

        $breaker = $this->circuits->for($circuit);
        $decision = $breaker->decision();
        $this->defer($breaker, $decision);

        if ($decision->kind === 'test' && $driver instanceof ChecksHealth) {
            if (! $this->test($driver, $breaker, $decision)) {
                $this->defer($breaker, $breaker->decision());
            }

            $decision = Decision::call();
        }

        try {
            try {
                $result = $call();
            } catch (Throwable $e) {
                throw $this->fail($breaker, $e, $decision);
            }

            $this->budget->record($runId, $result->inputTokens + $result->outputTokens);
            $breaker->recordSuccess($decision);

            return $result;
        } finally {
            // recordSuccess/fail already release the decision's lock when they complete, but
            // both can throw before doing so (a write-lock timeout, a listener that throws);
            // release() is a harmless no-op on an already-released lock, so this always frees
            // the test turn for the next caller even when a circuit transition blows up.
            $decision->release();
        }
    }

    /** Runs the health check for a circuit's test turn; true when it passed and the circuit closed. */
    public function test(TranslationDriver&ChecksHealth $driver, Circuit $breaker, Decision $decision): bool {
        try {
            try {
                $driver->checkHealth();
            } catch (Throwable $e) {
                $this->fail($breaker, $e, $decision);

                return false;
            }

            $breaker->recordSuccess($decision);

            return true;
        } finally {
            $decision->release();
        }
    }

    public function classify(Throwable $e): ProviderException {
        if ($e instanceof ProviderException) {
            return $e;
        }

        return config('prosetta.resilience.unknown_errors', 'transient') === 'halt'
            ? new ProviderRejected($e->getMessage(), 0, $e)
            : new ProviderUnavailable($e->getMessage(), 0, $e);
    }

    /** Records the failure on the circuit and returns the classified exception, tagged with the circuit. */
    private function fail(Circuit $breaker, Throwable $e, Decision $decision): ProviderException {
        $classified = $this->classify($e);
        $classified->circuit = $breaker->name;

        if ($classified instanceof ProviderRejected || $classified instanceof ProviderQuotaExhausted) {
            $reason = match (true) {
                $classified instanceof ProviderQuotaExhausted => 'quota',
                $classified !== $e => 'unknown',
                default => 'rejected',
            };

            if ($breaker->trip($reason, $classified->getMessage(), $decision)) {
                $this->events->dispatch(new TranslationHalted($breaker->name, $reason, $classified->getMessage()));
            }
        } else {
            $breaker->recordFailure($classified->getMessage(), $decision);
        }

        return $classified;
    }

    /** @throws CallDeferred when the decision is to wait or stay held */
    private function defer(Circuit $breaker, Decision $decision): void {
        if ($decision->kind === 'held') {
            throw new CallDeferred($breaker->name, 0, 'held');
        }

        if ($decision->kind === 'wait') {
            throw new CallDeferred($breaker->name, Backoff::jitter($decision->seconds), 'open', $breaker->outageExceeded());
        }
    }
}
