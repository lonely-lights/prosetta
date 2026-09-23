<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

/** Applies prosetta.exclude_paths: skipped when reading, refused when writing. */
final readonly class PathFilter {
    public static function normalize(string $path): string {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');
    }

    public function excluded(string $path): bool {
        $path = self::normalize($path);

        foreach ((array) config('prosetta.exclude_paths', []) as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $pattern = self::normalize(self::isAbsolute($pattern) ? $pattern : base_path($pattern));

            if ($this->matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $path, string $pattern): bool {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $pattern = strtolower($pattern);
        }

        return $path === $pattern || str_starts_with($path, $pattern.'/') || fnmatch($pattern, $path);
    }

    private static function isAbsolute(string $path): bool {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
