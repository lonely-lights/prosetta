<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/**
 * A language's required and banned terms. When an entry's English term
 * appears in the source (as a whole word, singular or plural, any case), the
 * translation must contain its target or one of its accepted forms (a
 * warning if not) and none of its banned alternatives (an error, which gets
 * the normal retry). The target side is a plain case-insensitive substring
 * match, since many scripts have no word boundaries; accept lists the
 * inflections a substring of the target can't catch (a plural, a possessive).
 */
final readonly class GlossaryGuard {
    /**
     * @param list<array{source: string, target: string, accept?: list<string>, banned?: list<string>}> $glossary
     * @return list<Issue>
     */
    public function check(string $source, string $translation, array $glossary): array {
        $issues = [];

        foreach ($glossary as $entry) {
            $term = trim((string) ($entry['source'] ?? ''));

            if ($term === '' || preg_match('/\b'.preg_quote($term, '/').'(s|es)?\b/iu', $source) !== 1) {
                continue;
            }

            $target = (string) ($entry['target'] ?? '');

            foreach ((array) ($entry['banned'] ?? []) as $banned) {
                if ($banned !== '' && mb_stripos($translation, (string) $banned) !== false) {
                    $issues[] = Issue::error('glossary_banned', "Use \"$target\" for \"$term\", not \"$banned\".");
                }
            }

            if ($target !== '' && ! $this->contains($translation, [$target, ...(array) ($entry['accept'] ?? [])])) {
                $issues[] = Issue::warning('glossary_missing', "\"$term\" should be translated as \"$target\".");
            }
        }

        return $issues;
    }

    /** @param list<mixed> $forms */
    private function contains(string $translation, array $forms): bool {
        foreach ($forms as $form) {
            $form = trim((string) $form);

            if ($form !== '' && mb_stripos($translation, $form) !== false) {
                return true;
            }
        }

        return false;
    }
}
