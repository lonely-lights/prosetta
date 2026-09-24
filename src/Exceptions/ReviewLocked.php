<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions;

/** A person tried to change translations where review is read-only (production, by default). */
final class ReviewLocked extends ProsettaException {
    public static function make(): self {
        return new self('Translations are read-only here: approvals made in this environment could never reach the lang files.');
    }
}
