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

        // Ensure InstanceSettings (id 0) exists for Feature tests that hit app code using instanceSettings()
        if (\Illuminate\Support\Facades\Schema::hasTable('instance_settings')) {
            InstanceSettings::updateOrCreate(['id' => 0], ['is_api_enabled' => true]);
        }
    }
}
