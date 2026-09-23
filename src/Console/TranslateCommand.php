<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use LonelyLights\Prosetta\ProsettaManager;
use Throwable;

final class TranslateCommand extends Command {
    protected $signature = 'prosetta:translate
        {--locale=* : Only these locales}
        {--namespace=* : Only these namespaces}
        {--key=* : Only these key references}
        {--force : Retranslate keys that are already current}
        {--sync : Run now instead of queueing}';

    protected $description = 'Draft missing and stale translations with the bound TranslationDriver.';

    /** @throws Throwable when the queued batch cannot be dispatched */
    public function handle(ProsettaManager $prosetta): int {
        $result = $prosetta->translate(
            $this->option('locale'),
            $this->option('namespace'),
            $this->option('key'),
            (bool) $this->option('force'),
            ! $this->option('sync'),
        );

        if ($result instanceof Batch) {
            $this->components->info("Queued batch {$result->id} with {$result->totalJobs} job(s) on the ".config('prosetta.queue.name').' queue.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d draft(s), %d with issues, %d failed; %d input / %d output tokens.',
            count($result->drafted), count($result->withIssues), count($result->failed), $result->inputTokens, $result->outputTokens,
        ));

        if ($result->stopped !== null) {
            $this->components->error('Stopped early: '.$result->stopped.' The rest is suspended for prosetta:resume unless only the per-run budget ran out.');
        }

        foreach ($result->withIssues as $ref) {
            $this->components->warn("Needs attention: $ref");
        }

        return $result->failed === [] && $result->stopped === null ? self::SUCCESS : self::FAILURE;
    }
}
