<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use LonelyLights\Prosetta\Enums\SuspensionReason;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderBatchRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderException;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderQuotaExhausted;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRateLimited;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Resilience\Backoff;
use LonelyLights\Prosetta\Resilience\BudgetExhausted;
use LonelyLights\Prosetta\Resilience\CallDeferred;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Translation\TranslationRunner;
use Throwable;

/**
 * One chunk of one file for one locale. Idempotent: the runner re-checks
 * before calling the AI. Provider trouble never fails the job: it is released
 * with backoff, waits out an open circuit, or ends quietly with its run
 * suspended for prosetta:resume. Only a batch the provider refuses
 * (ProviderBatchRejected) fails, into failed_jobs, while the batch goes on.
 */
final class TranslateBatch implements ShouldQueue {
    use Batchable, InteractsWithQueue, Queueable;

    /** Seconds a worker may spend running this job before it is treated as timed out. */
    public int $timeout = 300;

    /**
     * Exceptions Prosetta doesn't handle (genuine bugs) fail the job after this many, via Laravel's own counter.
     * Provider trouble never counts: it is caught and released, waited out or suspended.
     */
    public int $maxExceptions = 3;

    /**
     * @param list<int> $keyIds
     * @param array<string, mixed>|null $scope the run's RunScope::toArray(), to suspend and resume it whole
     */
    public function __construct(
        public string $locale,
        public int $fileId,
        public array $keyIds,
        public bool $force = false,
        public ?array $scope = null,
    ) {}

    /**
     * Releases for rate limits, overlap and open circuits all consume
     * attempts, so retries are bounded by time. Laravel stores this deadline
     * in the payload at dispatch and release() keeps it, so it must outlast
     * any run: the circuit and outage_timeout decide when to suspend, not it.
     */
    public function retryUntil(): DateTimeInterface {
        return now()->addDays(7);
    }

    /**
     * Seconds before retrying after an unhandled exception (a genuine bug), bounded by $maxExceptions.
     *
     * @return list<int>
     */
    public function backoff(): array {
        return [30, 120, 600];
    }

    /** @return list<object> */
    public function middleware(): array {
        return [
            new RateLimited('prosetta-ai'),
            (new WithoutOverlapping("prosetta:$this->locale:$this->fileId"))->releaseAfter(30)->expireAfter(600),
        ];
    }

    /** @throws Throwable when a database transaction fails */
    public function handle(TranslationRunner $runner, Suspensions $suspensions, Circuits $circuits): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $runner->run($this->locale, $this->keyIds, $this->force, $this->batchId, (bool) ($this->scope['cycle'] ?? false));
        } catch (CallDeferred $deferred) {
            if ($deferred->reason === 'held' || $deferred->outage) {
                $this->stop($suspensions, $deferred->circuit, ($deferred->reason === 'held' ? SuspensionReason::Halted : SuspensionReason::Outage)->value);

                return;
            }

            $this->release($deferred->seconds);
        } catch (ProviderBatchRejected $rejected) {
            # This Batch's Own Problem, Not the Provider's: a Genuine Failure, and the Batch Carries On
            $this->fail($rejected);
        } catch (ProviderRejected|ProviderQuotaExhausted $halt) {
            $this->stop($suspensions, (string) $halt->circuit, SuspensionReason::forHalt($halt)->value);
        } catch (ProviderException $transient) {
            if ($transient->circuit !== null && $circuits->for($transient->circuit)->outageExceeded()) {
                $this->stop($suspensions, $transient->circuit, SuspensionReason::Outage->value);

                return;
            }

            $this->release($transient instanceof ProviderRateLimited && $transient->retryAfter !== null
                ? $transient->retryAfter
                : Backoff::delay($this->attempts()));
        } catch (BudgetExhausted $budget) {
            $this->stop($suspensions, 'budget', $budget->period, suspend: $budget->period !== 'per_run');
        }
    }

    /** Ends the job quietly (never failed), cancels its batch, and keeps the run for prosetta:resume. */
    private function stop(Suspensions $suspensions, string $circuit, string $reason, bool $suspend = true): void {
        if ($suspend) {
            $suspensions->suspend($circuit, $this->scope !== null ? RunScope::fromArray($this->scope) : new RunScope([$this->locale], [], [], $this->force), $reason);
        }

        $this->batch()?->cancel();
        $this->delete();
    }
}
