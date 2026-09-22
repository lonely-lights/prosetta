<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\TranslationApproved;
use LonelyLights\Prosetta\Events\TranslationRejected;
use LonelyLights\Prosetta\Events\TranslationSubmitted;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Support\Settings;

/** Every human action on a translation. Each one checks the locale, leaves a review row and fires an event. */
final class ReviewService {
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly PlaceholderGuard $guard,
        private readonly Dispatcher $events,
        private readonly KeyFinder $finder,
        private readonly LocaleSource $locales,
    ) {}

    /**
     * Replaces the candidate with a human's value. With $approve, the edit and
     * the approval happen together or not at all: a value with blocking issues,
     * or a self-approval that config forbids, is refused before anything changes.
     */
    public function edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation {
        $translation = $this->load($translationId);
        $this->authorizer->authorize($by, $approve ? Ability::Review : Ability::Translate, $translation->locale);
        $key = $translation->key;
        $previous = $translation->value;
        $issues = Issue::store($this->guard->check($key->source_value, $value, $translation->locale));

        if ($approve && Issue::anyBlocking($issues)) {
            throw new ProsettaException('Not approved: issues.');
        }

        if ($approve && $by !== null && ! (bool) config('prosetta.review.allow_self_approval', true)) {
            throw new ProsettaException('Not approved: self_approval.');
        }

        DB::transaction(function () use ($translation, $key, $value, $by, $notes, $previous, $issues, $approve): void {
            $translation->update([
                'value' => $value, 'source_hash' => $key->source_hash,
                'status' => TranslationStatus::NeedsReview, 'origin' => TranslationOrigin::Manual,
                'issues' => $issues,
            ]);
            $translation->reviews()->create([
                'reviewer_id' => $this->id($by), 'action' => ReviewAction::Edited,
                'previous_value' => $previous, 'new_value' => $value, 'notes' => $notes,
            ]);

            if ($approve) {
                $this->markApproved($translation, $by, $notes);
            }
        });

        $this->events->dispatch(new TranslationSubmitted($translation, $this->id($by)));

        if ($approve) {
            $this->events->dispatch(new TranslationApproved($translation, $this->id($by)));
        }

        return $translation->refresh();
    }

    /**
     * Writes a human translation for any current key and target locale,
     * including keys with no translation yet, through the same review trail.
     */
    public function write(string $keyRef, string $locale, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation {
        $key = $this->finder->find($keyRef);

        if ($key === null || $key->obsolete_at !== null) {
            throw new ProsettaException("No current key [$keyRef].");
        }

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

    /** @param int|list<int> $translationIds */
    public function approve(int|array $translationIds, ?Authenticatable $by, ?string $notes = null): ApproveReport {
        $report = new ApproveReport;

        foreach ((array) $translationIds as $id) {
            $translation = $this->load((int) $id);
            $this->authorizer->authorize($by, Ability::Review, $translation->locale);

            if (($reason = $this->refusal($translation, $by)) !== null) {
                $report->skipped[(int) $translation->getKey()] = $reason;

                continue;
            }

            DB::transaction(fn () => $this->markApproved($translation, $by, $notes));

            $report->approved[] = (int) $translation->getKey();
            $this->events->dispatch(new TranslationApproved($translation, $this->id($by)));
        }

        return $report;
    }

    private function markApproved(Translation $translation, ?Authenticatable $by, ?string $notes): void {
        $translation->update([
            'approved_value' => $translation->value, 'approved_source_hash' => $translation->source_hash,
            'status' => TranslationStatus::Approved, 'reviewed_by' => $this->id($by), 'reviewed_at' => now(),
        ]);
        $translation->reviews()->create([
            'reviewer_id' => $this->id($by), 'action' => ReviewAction::Approved,
            'new_value' => $translation->value, 'notes' => $notes,
        ]);
    }

    public function reject(int $translationId, ?Authenticatable $by, ?string $notes = null): Translation {
        $translation = $this->load($translationId);
        $this->authorizer->authorize($by, Ability::Review, $translation->locale);

        DB::transaction(function () use ($translation, $by, $notes): void {
            $translation->update(['status' => TranslationStatus::Rejected, 'reviewed_by' => $this->id($by), 'reviewed_at' => now()]);
            $translation->reviews()->create([
                'reviewer_id' => $this->id($by), 'action' => ReviewAction::Rejected,
                'previous_value' => $translation->value, 'notes' => $notes,
            ]);
        });

        $this->events->dispatch(new TranslationRejected($translation, $this->id($by)));

        return $translation->refresh();
    }

    /** Approves every current draft or needs-review candidate for a locale (optionally one namespace or group). */
    public function approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?Authenticatable $by = null): ApproveReport {
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
            ->orderBy("$t.id")
            ->pluck("$t.id")
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->approve($ids, $by);
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

        if ($by !== null && ! (bool) config('prosetta.review.allow_self_approval', true)) {
            $last = $translation->reviews()
                ->whereIn('action', [ReviewAction::Edited->value, ReviewAction::Submitted->value])
                ->latest('id')
                ->first();

            if ($last !== null && $last->reviewer_id === $this->id($by)) {
                return 'self_approval';
            }
        }

        return null;
    }

    private function load(int $id): Translation {
        $model = Settings::model('translation');

        return $model::query()->with('key.file')->findOrFail($id);
    }

    private function id(?Authenticatable $by): ?string {
        return $by === null ? null : (string) $by->getAuthIdentifier();
    }
}
