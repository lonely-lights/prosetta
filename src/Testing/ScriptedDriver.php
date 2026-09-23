<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Testing;

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use Throwable;

/**
 * A test driver that fails on cue: each fail() argument is thrown by one
 * upcoming translate() call, in order; after that it echoes like
 * FakeTranslationDriver. refuse() makes it refuse items with a given source.
 */
class ScriptedDriver implements TranslationDriver {
    /** @var list<TranslationBatch> */
    public array $calls = [];

    /** @var list<Throwable> */
    private array $failures = [];

    /** @var array<string, string> source => reason */
    private array $refusals = [];

    private FakeTranslationDriver $echo;

    public function __construct() {
        $this->echo = new FakeTranslationDriver;
    }

    public function fail(Throwable ...$errors): static {
        $this->failures = [...$this->failures, ...array_values($errors)];

        return $this;
    }

    public function refuse(string $source, string $reason = 'unsafe'): static {
        $this->refusals[$source] = $reason;

        return $this;
    }

    public function translate(TranslationBatch $batch): TranslationBatchResult {
        $this->calls[] = $batch;

        if (($error = array_shift($this->failures)) !== null) {
            throw $error;
        }

        $result = $this->echo->translate($batch);
        $refused = [];

        foreach ($batch->items as $item) {
            if (isset($this->refusals[$item->source])) {
                $refused[$item->id] = $this->refusals[$item->source];
            }
        }

        return new TranslationBatchResult(array_diff_key($result->values, $refused), $result->provider, $result->model, $result->inputTokens, $result->outputTokens, $result->invocationId, $refused);
    }
}
