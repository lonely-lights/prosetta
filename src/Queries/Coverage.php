<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use LonelyLights\Prosetta\Automation\CycleFailures;
use LonelyLights\Prosetta\Automation\Health;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\UsageLedger;
use LonelyLights\Prosetta\Review\Status;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\State;

/** How far each of the viewer's languages is, and how the automation is doing. */
final readonly class Coverage {
    public function __construct(
        private LocaleSource $locales,
        private CycleFailures $failures,
        private Rejections $rejections,
        private UsageLedger $usage,
        private Budget $budget,
        private Circuits $circuits,
        private Health $health,
    ) {}

    public function for(Viewer $viewer): CoverageReport {
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');
        $keys = $keyModel::query()->whereNull('obsolete_at')->get();
        $failures = $this->failures->all();
        $rejections = $this->rejections->all();
        $languages = [];

        foreach ($viewer->locales() as $code) {
            $descriptor = $this->locales->find($code);
            $translations = $translationModel::query()->where('locale', $code)->get()->keyBy('key_id');
            $counts = array_fill_keys(['approved', 'draft', 'flagged', 'pending', 'stale', 'missing', 'held'], 0);

            foreach ($keys as $key) {
                /** @var TranslationKey $key */
                $counts[Status::of($key, $translations->get($key->getKey()), $code, $failures, $rejections)]++;
            }

            $languages[] = [
                'code' => $code,
                'name' => $descriptor?->englishName ?? $code,
                'nativeName' => $descriptor?->nativeName ?? $code,
                'mode' => ($descriptor?->replacements ?? []) !== [] ? 'derived' : 'ai',
                'keys' => $keys->count(),
                ...$counts,
                'tokensThisMonth' => $this->usage->sum(from: now()->startOfMonth(), locale: $code),
            ];
        }

        $lastRun = State::get('cycle.last_run');
        $lastReport = State::get('cycle.last_report');

        return new CoverageReport(
            $languages,
            $lastRun === null ? null : (int) $lastRun,
            is_array($lastReport) ? $lastReport : null,
            array_map(function (string $name) {
                $state = $this->circuits->for($name)->state();

                return ['name' => $name, 'state' => (string) $state['state'], 'reason' => $state['reason'], 'until' => $state['until']];
            }, $this->circuits->names()),
            $this->budget->usage(),
            $this->health->problems(),
            $viewer->isEditable,
        );
    }
}
