<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions;

/** The translation changed after the person opened it (the cycle updated it, or someone else acted first). */
final class ReviewConflict extends ProsettaException {
    public function __construct(public readonly int $translationId) {
        parent::__construct("Translation [$translationId] changed since you opened it.");
    }
}
