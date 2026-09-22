<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Contracts;

use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;

/**
 * The host's AI. Prosetta ships no implementation. Keep drivers stateless:
 * a batch in, a result out, no Eloquent on either side.
 */
interface TranslationDriver {
    public function translate(TranslationBatch $batch): TranslationBatchResult;
}
