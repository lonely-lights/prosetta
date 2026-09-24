<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum ReviewAction: string {
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Edited = 'edited';
    case Imported = 'imported';
    case Confirmed = 'confirmed';
}
