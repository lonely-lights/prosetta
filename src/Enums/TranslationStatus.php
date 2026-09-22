<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum TranslationStatus: string {
    case Draft = 'draft';
    case NeedsReview = 'needs_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
