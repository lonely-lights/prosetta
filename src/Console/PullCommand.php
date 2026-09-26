<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\TranslationApproved;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\State;
use LonelyLights\Prosetta\Support\WorkState;
use LonelyLights\Prosetta\Sync\Syncer;
use Throwable;

/**
 * Brings the interface-text approvals production has made into this
 * database, so the usual export can write them to the lang files for a
 * commit. Syncs the lang files first, so keys deployed alongside are known.
 * An approval applies only where the key exists with the same English; a
 * developer's edit waiting for review is kept. Production's reviewers are
 * credited in the trail's notes, since their ids mean nothing here.
 */
final class PullCommand extends Command {
    protected $signature = 'prosetta:pull
        {--after= : Pull approvals after this production review id, instead of after the last pull}
        {--no-sync : Skip reading the lang files first}
        {--export : Export right after pulling}';

    protected $description = 'Pull approvals made in production into this database';

    private int $pulled = 0;

    private int $same = 0;

    /** @var list<string> */
    private array $skipped = [];

    /** @var array<string, true> */
    private array $locales = [];

    /** @throws Throwable when a transaction fails */
    public function handle(KeyFinder $finder, LocaleSource $source, Dispatcher $events, Exporter $exporter, Syncer $syncer): int {
        $url = config('prosetta.pull.url');
        $token = config('prosetta.pull.token');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            $this->error('Set prosetta.pull.url and prosetta.pull.token (PROSETTA_PULL_URL, PROSETTA_PULL_TOKEN) to pull.');

            return self::FAILURE;
        }

        # The Token Must Never Cross the Network in the Clear
        if (! str_starts_with(strtolower($url), 'https://') && ! $this->getLaravel()->environment('local')) {
            $this->error('prosetta.pull.url must use https, so the token is never sent in the clear.');

            return self::FAILURE;
        }

        if (! $this->option('no-sync')) {
            $syncer->sync(quiet: true);
        }

        $targets = array_map(fn (LocaleDescriptor $locale) => $locale->code, $source->targets());
        $after = $this->option('after') !== null ? (int) $this->option('after') : (int) State::get('pull.after', 0);

        do {
            $response = Http::withToken($token)->acceptJson()->timeout(30)->withoutRedirecting()->get($url, ['after' => $after]);

            if (! $response->successful()) {
                $this->error("Production answered {$response->status()}; check the URL and token.");

                return self::FAILURE;
            }

            /** @var list<array{id: int, ref: string, locale: string, value: string, source_hash: string, reviewed_by: ?string, reviewed_at: string}> $approvals */
            $approvals = (array) $response->json('approvals', []);

            foreach ($approvals as $approval) {
                DB::transaction(fn () => $this->apply($approval, $finder, $targets, $events));
                $after = (int) $approval['id'];
                State::put('pull.after', $after);
            }

            $next = $response->json('next');
        } while (is_array($next));

        $this->info("Pulled {$this->pulled}; {$this->same} already here; ".count($this->skipped).' skipped.');

        foreach ($this->skipped as $line) {
            $this->line("  skipped $line");
        }

        if ($this->skipped !== []) {
            $this->line('Once those are resolved here, pull them again with --after=<an earlier production review id>.');
        }

        if ($this->option('export') && $this->locales !== []) {
            $report = $exporter->export(array_keys($this->locales));
            $this->line($report->disabled ? 'Export is turned off here, so no lang files were written.' : count($report->written).' lang files written.');
        }

        return self::SUCCESS;
    }

    /**
     * @param array{id: int, ref: string, locale: string, value: string, source_hash: string, reviewed_by: ?string, reviewed_at: string} $approval
     * @param list<string> $targets
     */
    private function apply(array $approval, KeyFinder $finder, array $targets, Dispatcher $events): void {
        $label = "{$approval['ref']} ({$approval['locale']})";
        $key = $finder->find($approval['ref']);

        if ($key === null || $key->obsolete_at !== null || ! in_array($approval['locale'], $targets, true)) {
            $this->skipped[] = "$label: no such current key or language here";

            return;
        }

        if ($key->source_hash !== $approval['source_hash']) {
            $this->skipped[] = "$label: its English differs here";

            return;
        }

        $model = Settings::model('translation');
        /** @var Translation $translation */
        $translation = $model::query()->firstOrNew(['key_id' => $key->getKey(), 'locale' => $approval['locale']]);

        if ($translation->exists && $translation->approved_value === $approval['value'] && $translation->approved_source_hash === $key->source_hash) {
            $this->same++;

            return;
        }

        $previous = $translation->approved_value;
        $credit = 'Pulled from production'.($approval['reviewed_by'] === null ? '' : " (reviewer {$approval['reviewed_by']})").', approved there '.Carbon::parse($approval['reviewed_at'])->toDateTimeString().'.';
        $approved = [
            'approved_value' => $approval['value'], 'approved_source_hash' => $key->source_hash, 'approved_source_value' => $key->source_value,
            'reviewed_by' => null, 'reviewed_at' => now(),
        ];

        # A Developer's Edit Waiting for Review Stays; Only What Is Approved Changes Under It
        if ($translation->exists && WorkState::hasCurrentCandidate($key, $translation)) {
            $translation->fill($approved)->save();
        } else {
            $translation->fill([
                ...$approved,
                'value' => $approval['value'], 'source_hash' => $key->source_hash,
                'status' => TranslationStatus::Approved, 'issues' => null,
                'origin' => $translation->exists ? $translation->origin : TranslationOrigin::Imported,
            ])->save();
        }

        $translation->logReview(ReviewAction::Approved, null, $previous, $approval['value'], $credit);
        $events->dispatch(new TranslationApproved($translation, null));
        $this->locales[$approval['locale']] = true;
        $this->pulled++;
    }
}
