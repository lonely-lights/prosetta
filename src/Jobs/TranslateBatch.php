<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use LonelyLights\Prosetta\Translation\TranslationRunner;

/** One chunk of one file for one locale. Idempotent: the runner re-checks before calling the AI. */
final class TranslateBatch implements ShouldQueue {
    use Batchable, InteractsWithQueue, Queueable;

    /** @param list<int> $keyIds */
    public function __construct(
        public string $locale,
        public int $fileId,
        public array $keyIds,
        public bool $force = false,
    ) {}

    /** @return list<object> */
    public function middleware(): array {
        return [
            new RateLimited('prosetta-ai'),
            (new WithoutOverlapping("prosetta:{$this->locale}:{$this->fileId}"))->releaseAfter(30),
        ];
    }

    public function handle(TranslationRunner $runner): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $runner->run($this->locale, $this->keyIds, $this->force);
    }
}
