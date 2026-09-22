<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

final class ExportReport {
    /** @var list<string> */
    public array $written = [];

    /** @var list<string> */
    public array $unchanged = [];

    /** @var list<string> */
    public array $refused = [];

    /** @var array<string, int> path => keys in the file */
    public array $keys = [];

    public function __construct(public readonly bool $dryRun = false) {}

    /** @return array<string, mixed> */
    public function toArray(): array {
        return ['dry_run' => $this->dryRun, 'written' => $this->written, 'unchanged' => $this->unchanged, 'refused' => $this->refused, 'keys' => $this->keys];
    }
}
