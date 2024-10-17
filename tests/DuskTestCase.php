<?php

namespace Tests;

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\TestCase as BaseTestCase;

abstract class DuskTestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function driver(): RemoteWebDriver
    {
        return RemoteWebDriver::create(
            env('DUSK_DRIVER_URL'),
            DesiredCapabilities::chrome()
        );
    }

    protected function baseUrl()
    {
        return 'https://staging.heyandras.dev';
    }
}
