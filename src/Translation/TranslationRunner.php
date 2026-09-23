<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationItem;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\TranslationDrafted;
use LonelyLights\Prosetta\Exceptions\MissingDriverException;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Guard\GlossaryGuard;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\ProviderGate;
use LonelyLights\Prosetta\Support\LocaleCode;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;
use Throwable;

/**
 * Translates one chunk of keys for one locale. Re-checks every key first, so
 * a queued job whose English changed, or that someone already handled, does
 * nothing. Results pass the guard; failures get one retry with feedback, and
 * anything still broken is saved as a draft carrying its issues.
 */
final readonly class TranslationRunner {
    public function __construct(
        private Container $container,
        private LocaleSource $locales,
        private PlaceholderGuard $guard,
        private GlossaryGuard $glossary,
        private Dispatcher $events,
        private ProviderGate $gate,
    ) {}

    /**
     * @param list<int> $keyIds
     * @throws Throwable when a database transaction fails, or a ProviderException, CallDeferred or BudgetExhausted when the provider can't be called
     */
    public function run(string $locale, array $keyIds, bool $force = false, ?string $runId = null): TranslateReport {
        $report = new TranslateReport;
        $target = $this->locales->find($locale) ?? throw new ProsettaException("Unknown locale [$locale].");
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');

        /** @var Collection<int, TranslationKey> $keys */
        $keys = $keyModel::query()->with('file')->whereKey($keyIds)->whereNull('obsolete_at')->get()->keyBy(fn (TranslationKey $key) => (int) $key->getKey());
        $existing = $translationModel::query()->where('locale', $locale)->whereIn('key_id', $keyIds)->get()->keyBy('key_id');
        $keys = $keys->filter(fn (TranslationKey $key) => $force || WorkState::needsWork($key, $existing->get($key->getKey())));
        $report->skipped = count($keyIds) - $keys->count();

        if ($keys->isEmpty()) {
            return $report;
        }

        $driver = $this->driver();
        $items = $keys->map(fn (TranslationKey $key) => new TranslationItem(
            (string) $key->getKey(),
            $key->ref()->toString(),
            $key->source_value,
            $key->context,
            $key->max_length,
            $key->placeholders ?? [],
            $existing->get($key->getKey())?->approved_value,
        ))->values()->all();

        $source = $this->locales->source();
        $batch = new TranslationBatch($source, $target, LocaleCode::isVariantOf($locale, $source) ? $source : null, $this->model($locale), $items);
        $outcomes = $this->attempt($driver, $batch, $locale, $runId);
        $stopped = null;

        try {
            for ($retries = (int) config('prosetta.ai.retries_on_issues', 1); $retries > 0; $retries--) {
                $failing = array_filter($outcomes, fn (array $outcome) => $this->blocking($outcome['issues']) && ! $this->refused($outcome['issues']));

                if ($failing === []) {
                    break;
                }

                $feedback = array_map(fn (array $outcome) => array_map(fn (Issue $issue) => $issue->message, $outcome['issues']), $failing);
                $retryItems = array_values(array_filter($items, fn (TranslationItem $item) => array_key_exists($item->id, $failing)));
                $retried = $this->attempt($driver, $batch->withItems($retryItems)->withFeedback($feedback), $locale, $runId);

                foreach ($retried as $id => $outcome) {
                    $outcome['input'] += $outcomes[$id]['input'];
                    $outcome['output'] += $outcomes[$id]['output'];
                    $outcomes[$id] = $outcome;
                }
            }
        } catch (Throwable $e) {
            # The First Attempt Is Already Paid For: Keep It, Then Let the Caller Deal With the Failure
            $stopped = $e;
        }

        foreach ($outcomes as $id => $outcome) {
            $this->persist($keys->get((int) $id), $existing->get((int) $id), $locale, $outcome, $report);
        }

        if ($stopped !== null) {
            throw $stopped;
        }

        return $report;
    }

    /**
     * Splits a call's tokens across its items by weight; the remainder goes to the first item.
     *
     * @param array<array-key, int> $weights
     * @return array<array-key, int>
     */
    public static function apportion(int $total, array $weights): array {
        $sum = array_sum($weights);
        $shares = [];
        $given = 0;

        foreach ($weights as $id => $weight) {
            $shares[$id] = $sum > 0 ? intdiv($total * $weight, $sum) : 0;
            $given += $shares[$id];
        }

        if ($shares !== []) {
            $shares[array_key_first($shares)] += $total - $given;
        }

        return $shares;
    }

    /** @return array<array-key, array{value: string|null, issues: list<Issue>, provider: string, model: string, invocation: string|null, input: int, output: int}> */
    private function attempt(TranslationDriver $driver, TranslationBatch $batch, string $locale, ?string $runId): array {
        $result = $this->gate->call($driver, Circuits::nameFor($driver, $batch->model), $runId, fn () => $driver->translate($batch));
        $weights = [];

        foreach ($batch->items as $item) {
            $weights[$item->id] = max(1, mb_strlen($item->source));
        }

        $input = self::apportion($result->inputTokens, $weights);
        $output = self::apportion($result->outputTokens, $weights);
        $outcomes = [];

        foreach ($batch->items as $item) {
            $value = $result->values[$item->id] ?? null;
            $refusal = $result->refused[$item->id] ?? null;

            $outcomes[$item->id] = [
                'value' => $refusal === null && is_string($value) ? $value : null,
                'issues' => match (true) {
                    $refusal !== null => [Issue::error('refused', "The provider refused to translate this: $refusal")],
                    is_string($value) => [...$this->guard->check($item->source, $value, $locale), ...$this->glossary->check($item->source, $value, $batch->target->glossary)],
                    default => [Issue::error('missing_value', 'The driver returned no value for this key.')],
                },
                'provider' => $result->provider,
                'model' => $result->model,
                'invocation' => $result->invocationId,
                'input' => $input[$item->id],
                'output' => $output[$item->id],
            ];
        }

        return $outcomes;
    }

    /**
     * @param array{value: string|null, issues: list<Issue>, provider: string, model: string, invocation: string|null, input: int, output: int} $outcome
     * @throws Throwable when a database transaction fails
     */
    private function persist(TranslationKey $key, ?Translation $existing, string $locale, array $outcome, TranslateReport $report): void {
        $ref = $locale.' '.$key->ref()->toString();
        $report->inputTokens += $outcome['input'];
        $report->outputTokens += $outcome['output'];

        if ($outcome['value'] === null) {
            $report->failed[] = $ref;

            if ($this->refused($outcome['issues'])) {
                $report->refused[] = $ref;
            }

            return;
        }

        $translationModel = Settings::model('translation');
        /** @var Translation $translation */
        $translation = $existing ?? new $translationModel(['key_id' => $key->getKey(), 'locale' => $locale]);

        DB::transaction(function () use ($translation, $key, $outcome): void {
            $translation->fill([
                'value' => $outcome['value'], 'source_hash' => $key->source_hash,
                'status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Ai,
                'issues' => Issue::store($outcome['issues']),
                'ai_provider' => $outcome['provider'], 'ai_model' => $outcome['model'],
                'input_tokens' => $outcome['input'], 'output_tokens' => $outcome['output'],
                'ai_invocation_id' => $outcome['invocation'],
            ])->save();

            $translation->logReview(ReviewAction::Submitted, null, newValue: $outcome['value'], notes: 'Machine translation by '.$outcome['model'].'.');
        });

        $report->drafted[] = $ref;

        if ($this->blocking($outcome['issues'])) {
            $report->withIssues[] = $ref;
        }

        $this->events->dispatch(new TranslationDrafted($translation));
    }

    /** @param list<Issue> $issues */
    private function blocking(array $issues): bool {
        foreach ($issues as $issue) {
            if ($issue->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Issue> $issues */
    private function refused(array $issues): bool {
        foreach ($issues as $issue) {
            if ($issue->code === 'refused') {
                return true;
            }
        }

        return false;
    }

    private function model(string $locale): ?string {
        $models = (array) config('prosetta.ai.models', []);
        $model = $models[$locale] ?? config('prosetta.ai.model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    private function driver(): TranslationDriver {
        if (! $this->container->bound(TranslationDriver::class)) {
            throw MissingDriverException::make();
        }

        try {
            return $this->container->make(TranslationDriver::class);
        } catch (BindingResolutionException $e) {
            throw new ProsettaException("The bound TranslationDriver could not be built: {$e->getMessage()}", 0, $e);
        }
    }
}
