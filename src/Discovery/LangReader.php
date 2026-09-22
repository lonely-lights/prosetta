<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Discovery;

use Illuminate\Filesystem\Filesystem;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Exceptions\LangFileException;
use LonelyLights\Prosetta\Support\KeyRef;
use Throwable;

/** Reads lang files the way Laravel loads them, flattened to dot keys in source order. */
final class LangReader {
    public function __construct(private readonly Filesystem $files) {}

    /** @return list<array{group: string, format: FileFormat}> */
    public function groups(LangRoot $root, string $locale): array {
        $groups = [];
        $directory = $root->path.'/'.$locale;

        if (is_dir($directory)) {
            foreach ($this->files->allFiles($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $groups[] = ['group' => substr($relative, 0, -4), 'format' => FileFormat::Php];
            }
        }

        usort($groups, fn (array $a, array $b) => strcmp($a['group'], $b['group']));

        if ($root->isRoot() && is_file($root->path.'/'.$locale.'.json')) {
            $groups[] = ['group' => KeyRef::JSON_GROUP, 'format' => FileFormat::Json];
        }

        return $groups;
    }

    public function path(LangRoot $root, string $locale, string $group, FileFormat $format): string {
        return $format === FileFormat::Json
            ? $root->path.'/'.$locale.'.json'
            : $root->path.'/'.$locale.'/'.$group.'.php';
    }

    /** @return array<string, string> */
    public function read(LangRoot $root, string $locale, string $group, FileFormat $format): array {
        $path = $this->path($root, $locale, $group, $format);

        if (! is_file($path)) {
            return [];
        }

        return $format === FileFormat::Json ? $this->readJson($path) : $this->flatten($this->readPhp($path));
    }

    /** @return array<array-key, mixed> */
    private function readPhp(string $path): array {
        # Under FPM or Octane a file Prosetta just wrote can come back stale from OPcache
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        try {
            $data = $this->files->getRequire($path);
        } catch (Throwable $e) {
            throw LangFileException::unreadable($path, $e);
        }

        if (! is_array($data)) {
            throw LangFileException::notAnArray($path);
        }

        return $data;
    }

    /** @return array<string, string> */
    private function readJson(string $path): array {
        try {
            $data = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw LangFileException::unreadable($path, $e);
        }

        if (! is_array($data)) {
            throw LangFileException::notAnArray($path);
        }

        $values = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $values[(string) $key] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array {
        $values = [];

        foreach ($data as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $values += $this->flatten($value, $full);
            } elseif (is_string($value)) {
                $values[$full] = $value;
            }
        }

        return $values;
    }
}
