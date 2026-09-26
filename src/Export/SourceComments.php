<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

/**
 * The comments a developer wrote in a source lang file: its heading (between
 * the opening lines and `return`) and the comment directly above each key,
 * at any depth. Read with PHP's tokenizer, so strings that merely contain
 * "//" or "#" are never mistaken for comments.
 */
final readonly class SourceComments {
    /** @param array<string, string> $keys dot path => the comment above that key */
    public function __construct(
        public ?string $header,
        public array $keys,
    ) {}

    public static function fromFile(string $path): self {
        return is_file($path) ? self::fromSource((string) file_get_contents($path)) : new self(null, []);
    }

    public static function fromSource(string $source): self {
        $tokens = token_get_all($source);

        if (($tokens[0][0] ?? null) !== T_OPEN_TAG) {
            return new self(null, []);
        }

        $header = [];
        $keys = [];
        $pending = [];
        /** @var list<string|null> $stack the key each open bracket belongs to, or null for a list or the root */
        $stack = [];
        $returned = false;
        $lastKey = null;
        $afterArrow = false;
        # Whether a Line Has Ended Since the Last Code: a Comment Before That Trails the Code, It Doesn't Head the Next Key
        $lineEnded = true;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $type = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;

            if ($type === T_COMMENT || $type === T_DOC_COMMENT) {
                if ($lineEnded) {
                    # A Line Comment Carries Its Newline; the Writer Adds Its Own
                    $pending[] = rtrim($text);
                }

                $lineEnded = $lineEnded || str_ends_with($text, "\n");

                continue;
            }

            if ($type === T_WHITESPACE || $type === T_OPEN_TAG) {
                $lineEnded = $lineEnded || str_contains($text, "\n");

                continue;
            }

            $lineEnded = false;

            if (! $returned) {
                if ($type === T_RETURN) {
                    $returned = true;
                    $header = $pending;
                }

                $pending = [];

                continue;
            }

            if ($text === '[') {
                $stack[] = $afterArrow ? $lastKey : null;
                $afterArrow = false;
                $pending = [];

                continue;
            }

            if ($text === ']') {
                array_pop($stack);
                $pending = [];

                continue;
            }

            if (($type === T_CONSTANT_ENCAPSED_STRING || $type === T_LNUMBER) && self::nextIsArrow($tokens, $i)) {
                $key = match (true) {
                    $type === T_LNUMBER => $text,
                    # Single Quotes Unescape Only \\ and \', as PHP Reads Them
                    str_starts_with($text, "'") => strtr(substr($text, 1, -1), ['\\\\' => '\\', "\\'" => "'"]),
                    default => stripcslashes(substr($text, 1, -1)),
                };
                $path = implode('.', [...array_filter($stack, fn ($part) => $part !== null), $key]);

                if ($pending !== []) {
                    $keys[$path] = implode("\n", $pending);
                }

                $lastKey = $key;
                $pending = [];

                continue;
            }

            $afterArrow = $type === T_DOUBLE_ARROW;
            $pending = [];
        }

        return new self($header === [] ? null : implode("\n", $header), $keys);
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private static function nextIsArrow(array $tokens, int $at): bool {
        for ($j = $at + 1, $count = count($tokens); $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_DOUBLE_ARROW;
        }

        return false;
    }
}
