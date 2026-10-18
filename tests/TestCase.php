<?php

namespace Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Facade;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Laravel flushes the application on tear down but leaves it set as the facade
     * application and container instance. Plain unit tests that mock facades or call
     * helpers afterwards would then resolve bindings from that empty application.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }
}
