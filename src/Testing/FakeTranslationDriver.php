<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Testing;

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Data\TranslationItem;

/**
 * A deterministic driver for tests, Prosetta's and hosts' alike. By default
 * it echoes the source with " [code]" appended, which keeps placeholders,
 * plural ranges and HTML intact.
 */
final class FakeTranslationDriver implements TranslationDriver {
    /** @var list<TranslationBatch> */
    public array $calls = [];

    private string $mode = 'echo';

    public function dropPlaceholders(): self {
        $this->mode = 'drop';

        return $this;
    }

    public function recasePlaceholders(): self {
        $this->mode = 'recase';

        return $this;
    }

    public function fixOnRetry(): self {
        $this->mode = 'drop-then-fix';

        return $this;
    }

    public function omitValues(): self {
        $this->mode = 'omit';

        return $this;
    }

    public function translate(TranslationBatch $batch): TranslationBatchResult {
        $this->calls[] = $batch;
        $retry = $batch->feedback !== [];
        $values = [];

        foreach ($batch->items as $item) {
            if ($this->mode === 'omit') {
                continue;
            }

            $value = $item->source.' ['.$batch->target->code.']';

            $values[$item->id] = match (true) {
                $this->mode === 'drop', $this->mode === 'drop-then-fix' && ! $retry => (string) preg_replace('/:[A-Za-z_][A-Za-z0-9_]*/', '', $value),
                $this->mode === 'recase' => (string) preg_replace_callback('/:([a-z])/', fn (array $m) => ':'.strtoupper($m[1]), $value),
                default => $value,
            };
        }

        $tokens = array_sum(array_map(fn (TranslationItem $item) => strlen($item->source), $batch->items));

        return new TranslationBatchResult($values, 'fake', $batch->model ?? 'fake-model', $tokens, $tokens, 'fake-'.count($this->calls));
    }
}
