<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

enum Severity: string {
    case Error = 'error';
    case Warning = 'warning';
}
