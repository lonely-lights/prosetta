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
 * suspended for prosetta:resume.
 */
final class TranslateBatch implements ShouldQueue {
    use Batchable, InteractsWithQueue, Queueable;

    /** Seconds a worker may spend running this job before it is treated as timed out. */
    public int $timeout = 300;

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
     * attempts, so retries are bounded by time: long enough that Prosetta,
     * not the queue, decides when an outage has lasted too long.
     */
    public function retryUntil(): DateTimeInterface {
        $outage = (int) config('prosetta.resilience.outage_timeout', 21600);
        $cooldown = (int) config('prosetta.resilience.circuit.max_cooldown', 3600);

        return now()->addSeconds(max(21600, $outage + $cooldown + 600));
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
            $runner->run($this->locale, $this->keyIds, $this->force, $this->batchId);
        } catch (CallDeferred $deferred) {
            if ($deferred->reason === 'held' || $deferred->outage) {
                $this->stop($suspensions, $deferred->circuit, $deferred->reason === 'held' ? 'halted' : 'outage');

                return;
            }

            $this->release($deferred->seconds);
        } catch (ProviderRejected|ProviderQuotaExhausted $halt) {
            $this->stop($suspensions, (string) $halt->circuit, $halt instanceof ProviderQuotaExhausted ? 'quota' : 'rejected');
        } catch (ProviderException $transient) {
            if ($transient->circuit !== null && $circuits->for($transient->circuit)->outageExceeded()) {
                $this->stop($suspensions, $transient->circuit, 'outage');

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
