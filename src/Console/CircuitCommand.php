<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Support\State;

final class CircuitCommand extends Command {
    protected $signature = 'prosetta:circuit
        {action=status : status or reset}
        {circuit? : One circuit to reset; all when omitted}';

    protected $description = 'Show translation circuits, suspended work and budgets, or reset circuits after a halt.';

    public function handle(Circuits $circuits, Suspensions $suspensions, Budget $budget): int {
        if ($this->argument('action') === 'reset') {
            $names = $this->argument('circuit') !== null ? [(string) $this->argument('circuit')] : $circuits->names();

            foreach ($names as $name) {
                $circuits->for($name)->reset();
                $this->components->info("Reset [$name]. Suspended work resumes on the next prosetta:resume.");
            }

            return self::SUCCESS;
        }

        $rows = array_map(function (string $name) use ($circuits) {
            $state = $circuits->for($name)->state();

            return [
                $name,
                $state['state'] === 'open' ? ($state['reason'] === 'halt' ? "halted ({$state['halt']})" : 'open') : 'closed',
                $state['failures'],
                $state['until'] === null ? ($state['state'] === 'open' ? 'until reset' : '-') : date('Y-m-d H:i:s', $state['until']),
                $state['message'] ?? '',
            ];
        }, $circuits->names());

        $this->table(['Circuit', 'State', 'Failures', 'Next test', 'Last error'], $rows);
        $this->line(count($suspensions->all()).' suspended run(s).');

        foreach ($budget->usage() as $period => ['used' => $used, 'limit' => $limit]) {
            $this->line("$period: $used / ".($limit ?? 'no limit').' tokens');
        }

        $lastRun = State::get('cycle.last_run');
        if ($lastRun !== null) {
            $this->line('Last cycle: '.date('Y-m-d H:i:s', (int) $lastRun));
        }

        return self::SUCCESS;
    }
}
