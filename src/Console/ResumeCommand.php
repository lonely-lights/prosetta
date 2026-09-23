<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use LonelyLights\Prosetta\Contracts\ChecksHealth;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Events\TranslationResumed;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuit;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\ProviderGate;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Translation\Translator;
use Throwable;

final class ResumeCommand extends Command {
    protected $signature = 'prosetta:resume';

    protected $description = 'Test providers whose circuits are due and queue suspended translation work again.';

    /** @throws Throwable when a batch cannot be dispatched */
    public function handle(Suspensions $suspensions, Circuits $circuits, ProviderGate $gate, Budget $budget, Translator $translator, Container $container): int {
        $resumed = [];
        $tested = [];

        foreach ($suspensions->all() as $id => $suspended) {
            if (($period = $budget->exhausted(null)) !== null) {
                $this->line("Waiting: the $period budget is used up.");

                break;
            }

            $name = $suspended['circuit'];

            if ($name !== 'budget' && ! ($tested[$name] ??= $this->ready($circuits->for($name), $gate, $container))) {
                $this->line("Waiting: [$name] is not ready yet.");

                continue;
            }

            $scope = $suspended['scope'];
            # Clear First: a Requeued Job That Suspends the Scope Again Must Not Be Wiped Afterwards
            $suspensions->clear($id);

            try {
                $translator->translate($scope->locales, $scope->namespaces, $scope->keys, $scope->force, forcedBefore: $scope->force ? $scope->startedAt : null);
            } catch (Throwable $e) {
                $suspensions->suspend($name, $scope, $suspended['reason']);

                throw $e;
            }

            $resumed[$name] = ($resumed[$name] ?? 0) + 1;
        }

        foreach ($resumed as $name => $count) {
            event(new TranslationResumed($name, $count));
            $this->components->info("Resumed $count suspended run(s) for [$name].");
        }

        return self::SUCCESS;
    }

    /** Closed: go. Held or cooling down: wait. Due for a test: health-check it, or let the queued jobs test it. */
    private function ready(Circuit $circuit, ProviderGate $gate, Container $container): bool {
        $decision = $circuit->decision();

        if ($decision->kind === 'call') {
            return true;
        }

        if ($decision->kind !== 'test') {
            return false;
        }

        try {
            $driver = $container->bound(TranslationDriver::class) ? $container->make(TranslationDriver::class) : null;
        } catch (BindingResolutionException $e) {
            # Give the Test Turn Back, or the Circuit Can't Be Tested Until the Lock Expires
            $decision->release();

            throw new ProsettaException("The bound TranslationDriver could not be built: {$e->getMessage()}", 0, $e);
        }

        if ($driver instanceof ChecksHealth) {
            return $gate->test($driver, $circuit, $decision);
        }

        # No Health Check: the Requeued Jobs Test the Circuit Themselves, One at a Time
        $decision->release();

        return true;
    }
}
