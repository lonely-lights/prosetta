<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions;

use Throwable;

final class LangFileException extends ProsettaException {
    public static function unreadable(string $path, Throwable $previous): self {
        return new self("Could not read the lang file [$path]: {$previous->getMessage()}", 0, $previous);
    }

    public static function notAnArray(string $path): self {
        return new self("The lang file [$path] must return an array.");
    }
}
