<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

/** Renders a lang array as readable PHP: short arrays, four-space indents, single quotes, real Unicode. */
final class PhpArrayWriter {
    /** @param array<array-key, mixed> $data */
    public function render(array $data, string $header = ''): string {
        $output = "<?php\n\n";

        if ($header !== '') {
            $lines = array_map(fn (string $line) => rtrim(' * '.$line), explode("\n", $header));
            $output .= "/*\n".implode("\n", $lines)."\n */\n\n";
        }

        return $output.'return '.$this->array($data, 0).";\n";
    }

    /** @param array<array-key, mixed> $data */
    private function array(array $data, int $depth): string {
        if ($data === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($data as $key => $value) {
            $rendered = is_array($value) ? $this->array($value, $depth + 1) : $this->string((string) $value);
            $lines[] = $indent.(is_int($key) ? (string) $key : $this->string($key)).' => '.$rendered.',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    private function string(string $value): string {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
