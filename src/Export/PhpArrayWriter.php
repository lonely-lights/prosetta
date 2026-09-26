<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

/**
 * Renders a lang array as readable PHP, the way a person would write it:
 * strict types, short arrays, four-space indents, single quotes, real
 * Unicode, and lists without their index keys. The source file's own
 * comments (its heading, and the note above any key) can be carried over.
 */
final readonly class PhpArrayWriter {
    /** @param array<array-key, mixed> $data */
    public function render(array $data, string $header = '', ?SourceComments $comments = null): string {
        $output = "<?php\n\ndeclare(strict_types=1);\n\n";

        if ($header !== '') {
            $lines = array_map(fn (string $line) => rtrim(' * '.$line), explode("\n", $header));
            $output .= "/*\n".implode("\n", $lines)."\n */\n\n";
        }

        if ($comments?->header !== null) {
            $output .= $comments->header."\n";
        }

        return $output.'return '.$this->array($data, 0, '', $comments !== null ? $comments->keys : []).";\n";
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $comments dot path => the comment above that key
     */
    private function array(array $data, int $depth, string $path = '', array $comments = []): string {
        if ($data === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $isList = array_is_list($data);
        $lines = [];

        foreach ($data as $key => $value) {
            $at = $path === '' ? (string) $key : "$path.$key";
            $rendered = is_array($value) ? $this->array($value, $depth + 1, $at, $comments) : $this->string((string) $value);

            if (! $isList && isset($comments[$at])) {
                foreach (explode("\n", $comments[$at]) as $line) {
                    $lines[] = $indent.ltrim($line);
                }
            }

            $lines[] = $indent.($isList ? '' : (is_int($key) ? (string) $key : $this->string($key)).' => ').$rendered.',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    private function string(string $value): string {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
