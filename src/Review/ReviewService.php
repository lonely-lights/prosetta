<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Automation\CycleFailures;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\TranslationApproved;
use LonelyLights\Prosetta\Events\TranslationRejected;
use LonelyLights\Prosetta\Events\TranslationSubmitted;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Exceptions\ReviewConflict;
use LonelyLights\Prosetta\Exceptions\ReviewLocked;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\Settings;
use Throwable;

/** Every human action on a translation. Each one checks the locale, leaves a review row and fires an event. */
final readonly class ReviewService {
    public function __construct(
        private Authorizer $authorizer,
        private PlaceholderGuard $guard,
        private Dispatcher $events,
        private KeyFinder $finder,
        private LocaleSource $locales,
        private Rejections $rejections,
        private CycleFailures $failures,
    ) {}

    /**
     * Replaces the candidate with a human's value. With $approve, the edit and
     * the approval happen together or not at all: a value with blocking issues,
     * or a self-approval that config forbids, is refused before anything changes.
     * @throws Throwable when a database transaction fails
     */
    public function edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false, ?string $expected = null): Translation {
        $translation = $this->load($translationId);
        $this->unlocked($by, $translation->key);
        $this->current($translation, $expected);
        $this->authorizer->authorize($by, $approve ? Ability::Review : Ability::Translate, $translation->locale);
        $key = $translation->key;
        $previous = $translation->value;
        $issues = Issue::store($this->guard->check($key->source_value, $value, $translation->locale));

        if ($approve && Issue::anyBlocking($issues)) {
            throw new ProsettaException('Not approved: issues.');
        }

        if ($approve && $by !== null && ! config('prosetta.review.allow_self_approval', true)) {
            throw new ProsettaException('Not approved: self_approval.');
        }

        DB::transaction(function () use ($translation, $key, $value, $by, $notes, $previous, $issues, $approve): void {
            $translation->update([
                'value' => $value, 'source_hash' => $key->source_hash,
                'status' => TranslationStatus::NeedsReview, 'origin' => TranslationOrigin::Manual,
                'issues' => $issues,
            ]);
            $translation->logReview(ReviewAction::Edited, $this->id($by), $previous, $value, $notes);

            if ($approve) {
                $this->markApproved($translation, $by, $notes);
            }
        });

        $this->rejections->clear($translation->locale, (int) $translation->key_id);
        $this->failures->clear($translation->locale, (int) $translation->key_id);

        $this->events->dispatch(new TranslationSubmitted($translation, $this->id($by)));

        if ($approve) {
            $this->events->dispatch(new TranslationApproved($translation, $this->id($by)));
        }

        return $translation->refresh();
    }

    /**
     * Writes a human translation for any current key and target locale,
     * including keys with no translation yet, through the same review trail.
     * @throws Throwable when a database transaction fails
     */
    public function write(string $keyRef, string $locale, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation {
        $key = $this->finder->find($keyRef);

        if ($key === null || $key->obsolete_at !== null) {
            throw new ProsettaException("No current key [$keyRef].");
        }

        $this->unlocked($by, $key);

        if (! in_array($locale, array_map(fn (LocaleDescriptor $target) => $target->code, $this->locales->targets()), true)) {
            throw new ProsettaException("[$locale] is not a locale Prosetta maintains.");
        }

        $this->authorizer->authorize($by, $approve ? Ability::Review : Ability::Translate, $locale);
        $model = Settings::model('translation');

        return DB::transaction(function () use ($model, $key, $locale, $value, $by, $notes, $approve): Translation {
            $translation = $model::query()->firstOrCreate(
                ['key_id' => $key->getKey(), 'locale' => $locale],
                ['status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Manual],
            );

            return $this->edit((int) $translation->getKey(), $value, $by, $notes, $approve);
        });
    }

    /**
     * @param int|list<int> $translationIds
     * $expected maps a translation id to the fingerprint the page saw; a changed one is skipped as 'conflict'.
     * @throws Throwable when a database transaction fails
     */
    public function approve(int|array $translationIds, ?Authenticatable $by, ?string $notes = null, array $expected = []): ApproveReport {
        $report = new ApproveReport;
        $translations = array_map(fn ($id) => $this->load((int) $id), (array) $translationIds);

        # Check Every Locale First, so a Mixed Batch Never Half-Applies
        foreach ($translations as $translation) {
            $this->unlocked($by, $translation->key);
            $this->authorizer->authorize($by, Ability::Review, $translation->locale);
        }

        foreach ($translations as $translation) {
            $id = (int) $translation->getKey();

            if (isset($expected[$id]) && self::fingerprint($translation) !== $expected[$id]) {
                $report->skipped[$id] = 'conflict';

                continue;
            }

            if (($reason = $this->refusal($translation, $by)) !== null) {
                $report->skipped[(int) $translation->getKey()] = $reason;

                continue;
            }

            DB::transaction(fn () => $this->markApproved($translation, $by, $notes));
            $this->rejections->clear($translation->locale, $translation->key_id);
            $this->failures->clear($translation->locale, $translation->key_id);

            $report->approved[] = (int) $translation->getKey();
            $this->events->dispatch(new TranslationApproved($translation, $this->id($by)));
        }

        return $report;
    }

    private function markApproved(Translation $translation, ?Authenticatable $by, ?string $notes): void {
        $translation->update([
            'approved_value' => $translation->value, 'approved_source_hash' => $translation->source_hash,
            'approved_source_value' => $translation->source_hash === $translation->key->source_hash ? $translation->key->source_value : null,
            'status' => TranslationStatus::Approved, 'reviewed_by' => $this->id($by), 'reviewed_at' => now(),
        ]);
        $translation->logReview(ReviewAction::Approved, $this->id($by), newValue: $translation->value, notes: $notes);
    }

    /** @throws Throwable when a database transaction fails */
    public function reject(int $translationId, ?Authenticatable $by, ?string $notes = null, ?string $expected = null): Translation {
        $translation = $this->load($translationId);
        $this->unlocked($by, $translation->key);
        $this->current($translation, $expected);
        $this->authorizer->authorize($by, Ability::Review, $translation->locale);
        $alreadyRejected = $translation->status === TranslationStatus::Rejected;

        DB::transaction(function () use ($translation, $by, $notes): void {
            $translation->update(['status' => TranslationStatus::Rejected, 'reviewed_by' => $this->id($by), 'reviewed_at' => now()]);
            $translation->logReview(ReviewAction::Rejected, $this->id($by), $translation->value, notes: $notes);
        });

        # A Double-Submitted Reject Counts Once Toward Holding the Key
        if (! $alreadyRejected) {
            $this->rejections->record($translation);
        }

        $this->events->dispatch(new TranslationRejected($translation, $this->id($by)));

        return $translation->refresh();
    }

    /**
     * Re-approves a stale translation against the key's current English without
     * changing its value: the human already reviewed this wording, it's just
     * the source that moved. Refuses a translation that isn't approved, or
     * whose approval already matches the key's current English.
     * @throws Throwable when a database transaction fails
     */
    public function confirm(int $translationId, ?Authenticatable $by, ?string $notes = null, ?string $expected = null): Translation {
        $translation = $this->load($translationId);
        $this->unlocked($by, $translation->key);
        $this->current($translation, $expected);
        $this->authorizer->authorize($by, Ability::Review, $translation->locale);
        $key = $translation->key;

        if ($translation->approved_value === null || $translation->approved_source_hash === $key->source_hash) {
            throw new ProsettaException('Only a stale approved translation can be confirmed.');
        }

        DB::transaction(function () use ($translation, $key, $by, $notes): void {
            $translation->update([
                'value' => $translation->approved_value, 'approved_value' => $translation->approved_value,
                'source_hash' => $key->source_hash, 'approved_source_hash' => $key->source_hash,
                'approved_source_value' => $key->source_value,
                'status' => TranslationStatus::Approved, 'reviewed_by' => $this->id($by), 'reviewed_at' => now(),
                'issues' => null,
            ]);
            $translation->logReview(ReviewAction::Confirmed, $this->id($by), newValue: $translation->approved_value, notes: $notes);
        });

        $this->events->dispatch(new TranslationApproved($translation, $this->id($by)));

        return $translation->refresh();
    }

    /**
     * Approves every current draft or needs-review candidate for a locale (optionally one namespace or group).
     * With $strict, only candidates with no issues at all: warnings (glossary, rewrite) hold one back too.
     * With $since (a Unix time), only AI and derived drafts written since then, with no issues: one run's own
     * drafts, never a person's pending edit or an older draft.
     * @throws Throwable when a database transaction fails
     */
    public function approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?Authenticatable $by = null, bool $strict = false, ?int $since = null): ApproveReport {
        $this->authorizer->authorize($by, Ability::Review, $locale);
        $t = Settings::table('translations');
        $k = Settings::table('keys');
        $f = Settings::table('files');
        $model = Settings::model('translation');

        $ids = $model::query()->select("$t.id")
            ->join($k, "$k.id", '=', "$t.key_id")
            ->join($f, "$f.id", '=', "$k.file_id")
            ->where("$t.locale", $locale)
            ->whereNull("$k.obsolete_at")
            ->whereIn("$t.status", [TranslationStatus::Draft->value, TranslationStatus::NeedsReview->value])
            ->whereColumn("$t.source_hash", "$k.source_hash")
            ->when($namespace !== null, fn ($query) => $query->where("$f.namespace", $namespace))
            ->when($group !== null, fn ($query) => $query->where("$f.group", $group))
            ->when($strict || $since !== null, fn ($query) => $query->whereNull("$t.issues"))
            # Where Review Is Read-Only, a Person Can Still Approve Content, Which Never Reaches a File
            ->when($by !== null && ! Viewer::editable(), fn ($query) => $query->where("$f.format", FileFormat::Database->value))
            ->when($since !== null, fn ($query) => $query
                ->whereIn("$t.origin", [TranslationOrigin::Ai->value, TranslationOrigin::Derived->value])
                ->where("$t.status", TranslationStatus::Draft->value)
                ->where("$t.updated_at", '>=', now()->setTimestamp((int) $since)))
            ->orderBy("$t.id")
            ->pluck("$t.id")
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->approve($ids, $by);
    }

    /** What a page saw of a translation: any change to its candidate, approval, status or English changes this. */
    public static function fingerprint(Translation $translation): string {
        # serialize() Can't Fail on Any String, Where json_encode Throws on Invalid UTF-8
        return Fingerprint::of(serialize([
            $translation->value, $translation->approved_value, $translation->status->value, $translation->source_hash, $translation->approved_source_hash,
        ]));
    }

    private function current(Translation $translation, ?string $expected): void {
        if ($expected !== null && self::fingerprint($translation) !== $expected) {
            throw new ReviewConflict((int) $translation->getKey());
        }
    }

    private function refusal(Translation $translation, ?Authenticatable $by): ?string {
        if ($translation->value === null) {
            return 'empty';
        }

        if ($translation->status === TranslationStatus::Rejected) {
            return 'rejected';
        }

        if ($translation->hasBlockingIssues()) {
            return 'issues';
        }

        if ($translation->status === TranslationStatus::Approved
            && $translation->approved_value === $translation->value
            && $translation->approved_source_hash === $translation->source_hash) {
            return 'already_approved';
        }

        if ($by !== null && ! config('prosetta.review.allow_self_approval', true)) {
            $last = $translation->reviews()
                ->whereIn('action', [ReviewAction::Edited->value, ReviewAction::Submitted->value, ReviewAction::Reported->value])
                ->latest('id')
                ->first();

            if ($last !== null && $last->reviewer_id === $this->id($by)) {
                return 'self_approval';
            }
        }

        return null;
    }

    /** People can't change file translations where review is read-only; content, and the system (no user), always can. */
    private function unlocked(?Authenticatable $by, ?TranslationKey $key = null): void {
        if ($by !== null && ! Viewer::editable($key)) {
            throw ReviewLocked::make();
        }
    }

    private function load(int $id): Translation {
        $model = Settings::model('translation');

        return $model::query()->with('key.file')->findOrFail($id);
    }

    private function id(?Authenticatable $by): ?string {
        return $by === null ? null : (string) $by->getAuthIdentifier();
    }
}
