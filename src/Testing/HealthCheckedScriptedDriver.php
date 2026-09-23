<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Testing;

use LonelyLights\Prosetta\Contracts\ChecksHealth;
use Throwable;

/** A ScriptedDriver with a health check; failHealth() queues errors for upcoming checks. */
final class HealthCheckedScriptedDriver extends ScriptedDriver implements ChecksHealth {
    public int $healthChecks = 0;

    /** @var list<Throwable> */
    private array $healthFailures = [];

    public function failHealth(Throwable ...$errors): static {
        $this->healthFailures = [...$this->healthFailures, ...array_values($errors)];

        return $this;
    }

    public function checkHealth(): void {
        $this->healthChecks++;

        if (($error = array_shift($this->healthFailures)) !== null) {
            throw $error;
        }
    }
}
