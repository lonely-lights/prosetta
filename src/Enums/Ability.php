<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum Ability: string {
    /** Edit candidates and request AI drafts for a locale. */
    case Translate = 'translate';
    /** Approve, reject or edit-and-approve for a locale; implies Translate. */
    case Review = 'review';
    /** Sync, export and rename; not tied to a locale. */
    case Manage = 'manage';

    public function gate(): string {
        return 'prosetta.'.$this->value;
    }
}
