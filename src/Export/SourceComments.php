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
        /**
         * One frame per open bracket: the path part it belongs to (its key, its
         * index in a list, or null for the root), the index its next unkeyed
         * element will take, and whether the current element is unkeyed.
         *
         * @var list<array{part: string|null, next: int, unkeyed: bool}> $frames
         */
        $frames = [];
        # Whether the Next Token Begins an Element: Right After "[" or ","
        $elementStart = false;
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

            $top = array_key_last($frames);

            if ($text === '[') {
                $part = null;

                if ($afterArrow) {
                    $part = $lastKey;
                } elseif ($top !== null && $elementStart) {
                    # An Unkeyed Array Inside a List Takes the Next Index, as PHP Numbers It
                    $part = (string) $frames[$top]['next'];
                    $frames[$top]['unkeyed'] = true;
                }

                $frames[] = ['part' => $part, 'next' => 0, 'unkeyed' => false];
                $afterArrow = false;
                $elementStart = true;
                $pending = [];

                continue;
            }

            if ($text === ']') {
                array_pop($frames);
                $afterArrow = false;
                $elementStart = false;
                $pending = [];

                continue;
            }

            if ($text === ',') {
                if ($top !== null && $frames[$top]['unkeyed']) {
                    $frames[$top]['next']++;
                    $frames[$top]['unkeyed'] = false;
                }

                $afterArrow = false;
                $elementStart = true;
                $pending = [];

                continue;
            }

            $parts = array_values(array_filter(array_column($frames, 'part'), fn ($part) => $part !== null));

            if (($type === T_CONSTANT_ENCAPSED_STRING || $type === T_LNUMBER) && self::nextIsArrow($tokens, $i)) {
                $key = match (true) {
                    $type === T_LNUMBER => $text,
                    # Single Quotes Unescape Only \\ and \', as PHP Reads Them
                    str_starts_with($text, "'") => strtr(substr($text, 1, -1), ['\\\\' => '\\', "\\'" => "'"]),
                    default => stripcslashes(substr($text, 1, -1)),
                };
                $path = implode('.', [...$parts, $key]);

                if ($pending !== []) {
                    $keys[$path] = implode("\n", $pending);
                }

                $lastKey = $key;
                $elementStart = false;
                $pending = [];

                continue;
            }

            # A Plain Value Opening an Element Is a List Item: Its Path Ends in Its Index
            if (($type === T_CONSTANT_ENCAPSED_STRING || $type === T_LNUMBER) && $elementStart && $top !== null) {
                if ($pending !== []) {
                    $keys[implode('.', [...$parts, (string) $frames[$top]['next']])] = implode("\n", $pending);
                }

                $frames[$top]['unkeyed'] = true;
                $elementStart = false;
                $pending = [];

                continue;
            }

            $afterArrow = $type === T_DOUBLE_ARROW;
            $elementStart = false;
            $pending = [];
        }

        return new self($header === [] ? null : implode("\n", $header), $keys);
    }

    /**
     * The comment above a key as plain text, for translators and reviewers:
     * without the //, # or /* markers and a docblock's leading stars.
     */
    public function plain(string $key): ?string {
        if (! isset($this->keys[$key])) {
            return null;
        }

        $lines = [];

        foreach (explode("\n", $this->keys[$key]) as $line) {
            $line = trim($line);
            $line = (string) preg_replace(['~^(//+|#+|/\*+)~', '~\*+/$~', '~^\*+(?!/)~'], '', $line);
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
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
