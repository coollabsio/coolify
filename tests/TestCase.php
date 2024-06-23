<?php

namespace Tests;

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $instance = InstanceSettings::factory()->create();
    }
}
