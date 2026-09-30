<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Discovery;

use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\PathFilter;

/**
 * Finds every lang root: lang_path() as '*', plus each loadTranslationsFrom()
 * hint the translator knows (resolving the translator first so providers'
 * hints are registered), plus config('prosetta.paths') overrides.
 */
final readonly class RootDiscovery {
    public function __construct(private PathFilter $filter) {}

    /**
     * @param list<string>|null $only limit to these namespaces
     * @return list<LangRoot>
     */
    public function roots(?array $only = null): array {
        $candidates = $this->candidates();
        $include = (array) config('prosetta.namespaces.include', ['*']);
        $exclude = (array) config('prosetta.namespaces.exclude', []);
        $roots = [];

        foreach ($candidates as $namespace => $path) {
            $namespace = (string) $namespace;

            if ($only !== null && ! in_array($namespace, $only, true)) {
                continue;
            }

            if (in_array($namespace, $exclude, true)) {
                continue;
            }

            if ($namespace !== KeyRef::ROOT && ! in_array('*', $include, true) && ! in_array($namespace, $include, true)) {
                continue;
            }

            $normalized = PathFilter::normalize($path);

            if (! is_dir($normalized) || $this->filter->excluded($normalized)) {
                continue;
            }

            $roots[] = new LangRoot($namespace, $normalized);
        }

        return $roots;
    }

    /**
     * A plain-language warning for each name in namespaces.include or
     * namespaces.exclude that matches no namespace, e.g. a lang file such as
     * "legal" listed as though it were one: it is still translated.
     *
     * @return list<string>
     */
    public function misnamed(): array {
        $candidates = $this->candidates();
        $source = (string) config('prosetta.source_locale', 'en');
        $warnings = [];

        foreach (['include', 'exclude'] as $list) {
            foreach ((array) config("prosetta.namespaces.$list", []) as $name) {
                if (! is_string($name) || $name === '*' || array_key_exists($name, $candidates)) {
                    continue;
                }

                $warnings[] = is_file(lang_path("$source/$name.php"))
                    ? "\"$name\" in prosetta.namespaces.$list is a lang file, not a namespace, so it is still translated. To leave it out, add lang/*/$name.php to prosetta.exclude_paths."
                    : "\"$name\" in prosetta.namespaces.$list matches no namespace.";
            }
        }

        return $warnings;
    }

    /** @return array<string, string> every namespace Prosetta could read, before include, exclude and exclude_paths */
    private function candidates(): array {
        $candidates = [KeyRef::ROOT => lang_path()];

        if (config('prosetta.namespaces.discover', true)) {
            $loader = app('translator')->getLoader();

            if (method_exists($loader, 'namespaces')) {
                foreach ($loader->namespaces() as $namespace => $path) {
                    $candidates[(string) $namespace] = (string) $path;
                }
            }
        }

        foreach ((array) config('prosetta.paths', []) as $namespace => $path) {
            $candidates[(string) $namespace] = (string) $path;
        }

        return $candidates;
    }
}
