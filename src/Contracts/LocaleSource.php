<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Contracts;

use LonelyLights\Prosetta\Data\LocaleDescriptor;

/** Which locales Prosetta works with. Hosts may bind their own. */
interface LocaleSource {
    /** The canonical locale, whose files are read and never written. */
    public function source(): string;

    /** @return list<LocaleDescriptor> every locale Prosetta maintains, excluding the source */
    public function targets(): array;

    public function find(string $code): ?LocaleDescriptor;

    /** @return list<string> codes of target locales background mode auto-translates */
    public function autoTranslateTargets(): array;
}
