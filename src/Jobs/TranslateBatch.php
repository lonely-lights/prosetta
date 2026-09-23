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
use LonelyLights\Prosetta\Translation\TranslationRunner;
use Throwable;

/** One chunk of one file for one locale. Idempotent: the runner re-checks before calling the AI. */
final class TranslateBatch implements ShouldQueue {
    use Batchable, InteractsWithQueue, Queueable;

    /** Seconds a worker may spend running this job before it is treated as timed out. */
    public int $timeout = 300;

    /** @param list<int> $keyIds */
    public function __construct(
        public string $locale,
        public int $fileId,
        public array $keyIds,
        public bool $force = false,
    ) {}

    /**
     * Being released for rate limiting or overlap consumes an attempt, so
     * bound retries by time instead of a fixed try count.
     */
    public function retryUntil(): DateTimeInterface {
        return now()->addHours(2);
    }

    /** @return list<object> */
    public function middleware(): array {
        return [
            new RateLimited('prosetta-ai'),
            (new WithoutOverlapping("prosetta:$this->locale:$this->fileId"))->releaseAfter(30)->expireAfter(600),
        ];
    }

    /** @throws Throwable when a database transaction fails */
    public function handle(TranslationRunner $runner): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $runner->run($this->locale, $this->keyIds, $this->force);
    }
}
