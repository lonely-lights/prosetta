<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

use Illuminate\Database\Eloquent\Collection;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;
use LonelyLights\Prosetta\Translation\SourceChange;

/**
 * What a cycle sends to the AI: in every target language, approved
 * translations whose English changed substantively (update mode), and in
 * auto-translate languages, every other key that needs work: nothing yet,
 * only a rejected candidate, or an unapproved draft made from older English.
 * Two kinds of key it would send are held back and reported instead: one a
 * person's candidate is waiting on (never overwritten by the AI, whatever
 * English it was made from), and one the provider failed to translate
 * CycleFailures::LIMIT times from its current English.
 */
final readonly class CycleWork {
    public function __construct(private LocaleSource $locales, private CycleFailures $failures) {}

    /** @return array<string, array<int, list<int>>> locale => file id => key ids */
    public function build(): array {
        return $this->plan()['work'];
    }

    /**
     * The work, and the keys held back from it, each with its reason.
     *
     * @return array{work: array<string, array<int, list<int>>>, held: list<string>}
     */
    public function plan(): array {
        $auto = $this->locales->autoTranslateTargets();
        $keys = $this->keys();
        $failures = $this->failures->all();
        $work = [];
        $held = [];

        foreach ($this->targets() as $locale) {
            $existing = $this->translations($locale, $keys);

            foreach ($keys as $key) {
                $translation = $existing->get($key->getKey());

                $needed = WorkState::isStale($key, $translation)
                    ? $this->needsUpdate($key, $translation)
                    : in_array($locale, $auto, true) && WorkState::needsWork($key, $translation);

                if (! $needed) {
                    continue;
                }

                $ref = $locale.' '.$key->ref()->toString();

                if ($this->awaitsPerson($translation)) {
                    $held[] = "$ref (awaiting human review)";
                } elseif (CycleFailures::capped($failures, $locale, $key)) {
                    $held[] = sprintf('%s (skipped: translation failed %d times; the cycle tries again once its English changes)', $ref, CycleFailures::LIMIT);
                } else {
                    $work[$locale][$key->file_id][] = (int) $key->getKey();
                }
            }
        }

        return ['work' => $work, 'held' => $held];
    }

    /**
     * Approved translations in any target language made from different English than the key has now.
     *
     * @return list<string> "{locale} {ref}"
     */
    public function stale(): array {
        $keys = $this->keys();
        $stale = [];

        foreach ($this->targets() as $locale) {
            $existing = $this->translations($locale, $keys);

            foreach ($keys as $key) {
                if (WorkState::isStale($key, $existing->get($key->getKey()))) {
                    $stale[] = $locale.' '.$key->ref()->toString();
                }
            }
        }

        return $stale;
    }

    /** Stale, with no candidate made from the current English yet, and not a change confirmation can handle. */
    private function needsUpdate(TranslationKey $key, ?Translation $translation): bool {
        return WorkState::isStale($key, $translation)
            && ! WorkState::hasCurrentCandidate($key, $translation)
            && ! ($translation->approved_source_value !== null && SourceChange::isCosmetic($translation->approved_source_value, $key->source_value));
    }

    /** A candidate from a person or an import, not yet reviewed: the AI must not overwrite it. */
    private function awaitsPerson(?Translation $translation): bool {
        return $translation !== null
            && $translation->value !== null
            && ! in_array($translation->origin, [TranslationOrigin::Ai, TranslationOrigin::Derived], true)
            && in_array($translation->status, [TranslationStatus::Draft, TranslationStatus::NeedsReview], true);
    }

    /** @return list<string> */
    private function targets(): array {
        return array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets());
    }

    /** @return Collection<int, TranslationKey> */
    private function keys(): Collection {
        $keyTable = Settings::table('keys');
        $fileTable = Settings::table('files');
        $model = Settings::model('key');

        return $model::query()->select("$keyTable.*")->with('file')
            ->join($fileTable, "$fileTable.id", '=', "$keyTable.file_id")
            ->whereNull("$keyTable.obsolete_at")
            ->orderBy("$keyTable.id")
            ->get();
    }

    /**
     * @param Collection<int, TranslationKey> $keys
     * @return Collection<int, Translation> keyed by key id
     */
    private function translations(string $locale, Collection $keys): Collection {
        $model = Settings::model('translation');

        return $model::query()->where('locale', $locale)->whereIn('key_id', $keys->modelKeys())->get()->keyBy('key_id');
    }
}
