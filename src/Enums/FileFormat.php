<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum FileFormat: string {
    case Php = 'php';
    case Json = 'json';
    /** Content keys: kept in the database only, never read from or written to a lang file. */
    case Database = 'database';
}
