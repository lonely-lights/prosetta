<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions;

final class MissingDriverException extends ProsettaException {
    public static function make(): self {
        return new self('No TranslationDriver is bound. Bind LonelyLights\\Prosetta\\Contracts\\TranslationDriver in a service provider, or set prosetta.ai.driver.');
    }
}
