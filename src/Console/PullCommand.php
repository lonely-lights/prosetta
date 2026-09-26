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
use Throwable;

/**
 * Brings the interface-text approvals production has made into this
 * database, so the usual export can write them to the lang files for a
 * commit. An approval applies only where the key still exists with the
 * same English, and never over a newer approval made here.
 */
final class PullCommand extends Command {
    protected $signature = 'prosetta:pull
        {--since= : Pull approvals made after this time, instead of after the last pull}
        {--export : Export right after pulling}';

    protected $description = 'Pull approvals made in production into this database';

    private int $pulled = 0;

    private int $same = 0;

    private int $englishDiffers = 0;

    private int $unknown = 0;

    private int $newerHere = 0;

    /** @var array<string, true> */
    private array $locales = [];

    /** @throws Throwable when a transaction fails */
    public function handle(KeyFinder $finder, LocaleSource $source, Dispatcher $events, Exporter $exporter): int {
        $url = config('prosetta.pull.url');
        $token = config('prosetta.pull.token');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            $this->error('Set prosetta.pull.url and prosetta.pull.token (PROSETTA_PULL_URL, PROSETTA_PULL_TOKEN) to pull.');

            return self::FAILURE;
        }

        $targets = array_map(fn (LocaleDescriptor $locale) => $locale->code, $source->targets());
        # Where the Last Pull Stopped: a Time and the Id Within It, So a Same-Second Approval Isn't Skipped
        $since = $this->option('since') ?: State::get('pull.since');
        $after = $this->option('since') ? 0 : (int) State::get('pull.after', 0);

        do {
            $response = Http::withToken($token)->acceptJson()->timeout(30)
                ->get($url, array_filter(['since' => $since, 'after' => $after ?: null]));

            if (! $response->successful()) {
                $this->error("Production answered {$response->status()}; check the URL and token.");

                return self::FAILURE;
            }

            /** @var list<array{id: int, ref: string, locale: string, value: string, source_hash: string, reviewed_by: ?string, reviewed_at: string}> $approvals */
            $approvals = (array) $response->json('approvals', []);

            foreach ($approvals as $approval) {
                DB::transaction(fn () => $this->apply($approval, $finder, $targets, $events));
                $since = $approval['reviewed_at'];
                $after = (int) $approval['id'];
                State::put('pull.since', $since);
                State::put('pull.after', $after);
            }

            $next = $response->json('next');
        } while (is_array($next));

        $this->info("Pulled {$this->pulled}; {$this->same} already here; skipped {$this->englishDiffers} whose English differs here, {$this->newerHere} approved more recently here, {$this->unknown} unknown.");

        if ($this->option('export') && $this->locales !== []) {
            $report = $exporter->export(array_keys($this->locales));
            $this->line(count($report->written).' lang files written.');
        }

        return self::SUCCESS;
    }

    /**
     * @param array{id: int, ref: string, locale: string, value: string, source_hash: string, reviewed_by: ?string, reviewed_at: string} $approval
     * @param list<string> $targets
     */
    private function apply(array $approval, KeyFinder $finder, array $targets, Dispatcher $events): void {
        $key = $finder->find($approval['ref']);

        if ($key === null || $key->obsolete_at !== null || ! in_array($approval['locale'], $targets, true)) {
            $this->unknown++;

            return;
        }

        if ($key->source_hash !== $approval['source_hash']) {
            $this->englishDiffers++;

            return;
        }

        $model = Settings::model('translation');
        /** @var Translation $translation */
        $translation = $model::query()->firstOrNew(['key_id' => $key->getKey(), 'locale' => $approval['locale']]);

        if ($translation->exists && $translation->approved_value === $approval['value'] && $translation->approved_source_hash === $key->source_hash) {
            $this->same++;

            return;
        }

        $reviewedAt = Carbon::parse($approval['reviewed_at']);

        if ($translation->exists && $translation->reviewed_at !== null && $translation->reviewed_at->greaterThan($reviewedAt)) {
            $this->newerHere++;

            return;
        }

        $previous = $translation->value;
        $translation->fill([
            'value' => $approval['value'], 'source_hash' => $key->source_hash,
            'approved_value' => $approval['value'], 'approved_source_hash' => $key->source_hash, 'approved_source_value' => $key->source_value,
            'status' => TranslationStatus::Approved, 'origin' => $translation->exists ? $translation->origin : TranslationOrigin::Imported,
            'issues' => null, 'reviewed_by' => $approval['reviewed_by'], 'reviewed_at' => $reviewedAt,
        ])->save();
        $translation->logReview(ReviewAction::Approved, $approval['reviewed_by'], $previous, $approval['value'], 'Pulled from production.');
        $events->dispatch(new TranslationApproved($translation, $approval['reviewed_by']));
        $this->locales[$approval['locale']] = true;
        $this->pulled++;
    }
}
