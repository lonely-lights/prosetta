<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Models\TranslationReport;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Support\Settings;
use Throwable;

/**
 * Members' reports of a bad translation. A report never changes what is
 * live: traced to one string, it becomes a hand edit waiting for review
 * (the member's suggestion, or the current wording flagged with their
 * note); untraced, it waits for staff.
 */
final readonly class Reports {
    /** Selections shorter than this match too much to trace. */
    public const int MIN_SELECTION = 3;

    public function __construct(
        private LocaleSource $locales,
        private KeyFinder $finder,
        private PlaceholderGuard $guard,
    ) {}

    /**
     * @throws ProsettaException when the locale isn't one Prosetta maintains, the selection is too short, or this member already has an open report on the string
     * @throws Throwable when the transaction fails
     */
    public function report(string $locale, string $selectedText, Authenticatable $by, ?string $suggestion = null, ?string $notes = null, ?string $url = null, ?string $keyRef = null): TranslationReport {
        $selectedText = trim($selectedText);
        $suggestion = $this->blankToNull($suggestion);
        $notes = $this->blankToNull($notes);
        $reporter = (string) $by->getAuthIdentifier();

        if (! in_array($locale, array_map(fn (LocaleDescriptor $target) => $target->code, $this->locales->targets()), true)) {
            throw new ProsettaException("[$locale] is not a translated language, so there's nothing to report.");
        }

        if ($keyRef === null && mb_strlen($selectedText) < self::MIN_SELECTION) {
            throw new ProsettaException('Select a few more words to report.');
        }

        $key = $keyRef !== null ? $this->finder->find($keyRef) : $this->trace($locale, $selectedText);
        $key = $key !== null && $key->obsolete_at === null ? $key : null;
        $model = Settings::model('report');

        if ($key !== null && $model::query()->where('key_id', $key->getKey())->where('locale', $locale)
            ->where('reporter_id', $reporter)->where('status', TranslationReport::OPEN)->exists()) {
            throw new ProsettaException('You have already reported this; a reviewer will look at it.');
        }

        return DB::transaction(function () use ($model, $key, $locale, $selectedText, $suggestion, $notes, $reporter, $url): TranslationReport {
            /** @var TranslationReport $report */
            $report = $model::query()->create([
                'key_id' => $key?->getKey(), 'locale' => $locale, 'selected_text' => $selectedText,
                'suggestion' => $suggestion, 'notes' => $notes, 'reporter_id' => $reporter, 'url' => $url,
            ]);

            if ($key !== null) {
                $this->candidate($key, $locale, $suggestion, $notes);
            }

            return $report;
        });
    }

    /** Closes an open report without acting on it, e.g. one that couldn't be traced. */
    public function dismiss(int $reportId, Authenticatable $by): void {
        $model = Settings::model('report');
        $model::query()->whereKey($reportId)->where('status', TranslationReport::OPEN)->update([
            'status' => TranslationReport::DISMISSED, 'resolved_by' => (string) $by->getAuthIdentifier(), 'resolved_at' => now(),
        ]);
    }

    /** Closes every open report on a translation, when a reviewer acts on it. */
    public function close(int $keyId, string $locale, string $status, ?string $by): void {
        $model = Settings::model('report');
        $model::query()->where('key_id', $keyId)->where('locale', $locale)->where('status', TranslationReport::OPEN)
            ->update(['status' => $status, 'resolved_by' => $by, 'resolved_at' => now()]);
    }

    /**
     * Open reports in the languages this viewer reviews, newest first.
     *
     * @return list<TranslationReport>
     */
    public function open(Viewer $viewer): array {
        $model = Settings::model('report');

        return array_values($model::query()->with('key.file')->where('status', TranslationReport::OPEN)
            ->whereIn('locale', array_values(array_filter($viewer->locales(), $viewer->canReview(...))))
            ->latest('id')->limit(200)->get()->all());
    }

    /**
     * Open reports per key and locale, for marking queue items.
     *
     * @return array<string, int> "keyId:locale" => count
     */
    public function counts(string $locale): array {
        $model = Settings::model('report');

        return $model::query()->where('status', TranslationReport::OPEN)->where('locale', $locale)->whereNotNull('key_id')
            ->groupBy('key_id')->selectRaw('key_id, count(*) as reports')->pluck('reports', 'key_id')
            ->mapWithKeys(fn ($count, $keyId) => ["$keyId:$locale" => (int) $count])->all();
    }

    /** The one current string whose approved wording contains the selection, or null when none or several do. */
    private function trace(string $locale, string $selectedText): ?TranslationKey {
        $t = Settings::table('translations');
        $k = Settings::table('keys');
        $model = Settings::model('translation');
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($selectedText));

        $keyIds = $model::query()->toBase()->join($k, "$k.id", '=', "$t.key_id")
            ->where("$t.locale", $locale)->whereNull("$k.obsolete_at")->whereNotNull("$t.approved_value")
            ->whereRaw("lower($t.approved_value) like ? escape '!'", ['%'.$escaped.'%'])
            ->limit(2)->pluck("$t.key_id");

        if ($keyIds->count() !== 1) {
            return null;
        }

        $keyModel = Settings::model('key');

        /** @var TranslationKey|null */
        return $keyModel::query()->with('file')->find($keyIds->first());
    }

    /** Puts the report in front of a reviewer as a hand edit; what is live stays until they approve something. */
    private function candidate(TranslationKey $key, string $locale, ?string $suggestion, ?string $notes): void {
        $translationModel = Settings::model('translation');
        /** @var Translation $translation */
        $translation = $translationModel::query()->firstOrCreate(
            ['key_id' => $key->getKey(), 'locale' => $locale],
            ['status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Manual],
        );
        $value = $suggestion ?? $translation->approved_value ?? $translation->value ?? '';
        $issues = $this->guard->check($key->source_value, $value, $locale);
        $issues[] = Issue::warning('reported', 'A member reported this'.($notes === null ? '.' : ': '.$notes));
        $previous = $translation->value;

        $translation->update([
            'value' => $value, 'source_hash' => $key->source_hash,
            'status' => TranslationStatus::NeedsReview, 'origin' => TranslationOrigin::Manual,
            'issues' => Issue::store($issues),
        ]);
        $translation->logReview(ReviewAction::Reported, null, $previous, $value, $notes);
    }

    private function blankToNull(?string $value): ?string {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
