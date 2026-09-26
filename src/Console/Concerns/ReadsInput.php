<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console\Concerns;

/** Reads a command's arguments and options as the types Prosetta's services take. */
trait ReadsInput {
    protected function text(string $argument): string {
        $value = $this->argument($argument);

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    protected function texts(string $option): array {
        $value = $this->option($option);

        return array_values(array_filter(is_array($value) ? $value : [], is_string(...)));
    }
}
