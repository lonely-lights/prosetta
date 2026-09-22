<?php

namespace LonelyLights\Prosetta\Tests;

use LonelyLights\Prosetta\ProsettaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra {
    protected function getPackageProviders($app): array {
        return [ProsettaServiceProvider::class];
    }
}
