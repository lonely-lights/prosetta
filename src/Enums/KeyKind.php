<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

/** "content" keys hold Eloquent fields (TranslatesContent); they live in content/<folder> files. */
enum KeyKind: string {
    case File = 'file';
    case Content = 'content';
}
