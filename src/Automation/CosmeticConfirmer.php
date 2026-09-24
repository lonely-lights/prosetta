<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;
use LonelyLights\Prosetta\Translation\SourceChange;
use Throwable;

/** Re-approves translations whose English changed only cosmetically (case, quotes, dashes, spacing, end punctuation), with no AI call. */
final readonly class CosmeticConfirmer {
    public function __construct(
        private ReviewService $review,
        private LocaleSource $locales,
    ) {}

    /**
     * @return int how many translations were confirmed
     * @throws Throwable when a database transaction fails
     */
    public function confirmAll(): int {
        $t = Settings::table('translations');
        $k = Settings::table('keys');
        $model = Settings::model('translation');
        $targets = array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets());

        $candidates = $model::query()->select("$t.*")->with('key')
            ->join($k, "$k.id", '=', "$t.key_id")
            ->whereNull("$k.obsolete_at")
            ->whereIn("$t.locale", $targets)
            ->whereNotNull("$t.approved_value")
            ->whereNotNull("$t.approved_source_value")
            ->whereColumn("$t.approved_source_hash", '!=', "$k.source_hash")
            ->orderBy("$t.id")
            ->get();
        $confirmed = 0;

        foreach ($candidates as $translation) {
            /** @var Translation $translation */
            $key = $translation->key;

            # A Candidate Made From the New English Is Someone's Work: Leave It for Review
            if (WorkState::hasCurrentCandidate($key, $translation) || ! SourceChange::isCosmetic((string) $translation->approved_source_value, $key->source_value)) {
                continue;
            }

            $this->review->confirm((int) $translation->getKey(), null, 'The English changed only cosmetically.');
            $confirmed++;
        }

        return $confirmed;
    }
}
