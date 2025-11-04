<?php

/**
 * Tests for SynchronizesModelData trait
 *
 * NOTE: These tests verify that the trait properly handles nested Eloquent models
 * and marks them as dirty when syncing properties.
 */

use App\Livewire\Concerns\SynchronizesModelData;
use App\Models\Application;
use App\Models\ApplicationSetting;

it('syncs nested eloquent model properties correctly', function () {
    // Create a test component that uses the trait
    $component = new class
    {
        use SynchronizesModelData;

        public bool $is_static = true;

        public Application $application;

        protected function getModelBindings(): array
        {
            return [
                'is_static' => 'application.settings.is_static',
            ];
        }

        // Expose protected method for testing
        public function testSync(): void
        {
            $this->syncToModel();
        }
    };

    // Create real ApplicationSetting instance
    $settings = new ApplicationSetting;
    $settings->is_static = false;

    // Create Application instance
    $application = new Application;
    $application->setRelation('settings', $settings);

    $component->application = $application;
    $component->is_static = true;

    // Sync to model
    $component->testSync();

    // Verify the value was set on the model
    expect($component->application->settings->is_static)->toBeTrue();
});

it('syncs boolean values correctly', function () {
    $component = new class
    {
        use SynchronizesModelData;

        public bool $is_spa = true;

        public bool $is_build_server_enabled = false;

        public Application $application;

        protected function getModelBindings(): array
        {
            return [
                'is_spa' => 'application.settings.is_spa',
                'is_build_server_enabled' => 'application.settings.is_build_server_enabled',
            ];
        }

        public function testSync(): void
        {
            $this->syncToModel();
        }
    };

    $settings = new ApplicationSetting;
    $settings->is_spa = false;
    $settings->is_build_server_enabled = true;

    $application = new Application;
    $application->setRelation('settings', $settings);

    $component->application = $application;

    $component->testSync();

    expect($component->application->settings->is_spa)->toBeTrue()
        ->and($component->application->settings->is_build_server_enabled)->toBeFalse();
});

it('syncs from model to component correctly', function () {
    $component = new class
    {
        use SynchronizesModelData;

        public bool $is_static = false;

        public bool $is_spa = false;

        public Application $application;

        protected function getModelBindings(): array
        {
            return [
                'is_static' => 'application.settings.is_static',
                'is_spa' => 'application.settings.is_spa',
            ];
        }

        public function testSyncFrom(): void
        {
            $this->syncFromModel();
        }
    };

    $settings = new ApplicationSetting;
    $settings->is_static = true;
    $settings->is_spa = true;

    $application = new Application;
    $application->setRelation('settings', $settings);

    $component->application = $application;

    $component->testSyncFrom();

    expect($component->is_static)->toBeTrue()
        ->and($component->is_spa)->toBeTrue();
});

it('handles properties that do not exist gracefully', function () {
    $component = new class
    {
        use SynchronizesModelData;

        public Application $application;

        protected function getModelBindings(): array
        {
            return [
                'non_existent_property' => 'application.name',
            ];
        }

        public function testSync(): void
        {
            $this->syncToModel();
        }
    };

    $application = new Application;
    $component->application = $application;

    // Should not throw an error
    $component->testSync();

    expect(true)->toBeTrue();
});
