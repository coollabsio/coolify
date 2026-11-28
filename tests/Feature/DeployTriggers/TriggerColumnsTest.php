<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fixture = deployTriggerFixture();
});

it('defaults smart triggers on for applications and the instance', function () {
    $application = deployTriggerApplication($this->fixture);

    expect($application->settings->refresh()->is_smart_triggers_enabled)->toBeTrue()
        ->and(InstanceSettings::get()->is_smart_triggers_enabled)->toBeTrue();
});

it('casts the trigger json columns to arrays', function () {
    $application = deployTriggerApplication($this->fixture);
    $application->forceFill([
        'trigger_base_commit' => str_repeat('a', 40),
        'trigger_set' => ['display' => ['apps/web/**']],
    ])->save();

    $application->refresh();

    expect($application->trigger_set)->toBe(['display' => ['apps/web/**']])
        ->and($application->trigger_base_commit)->toBe(str_repeat('a', 40));
});

it('stores the trigger reason and skip counters on deployments', function () {
    $application = deployTriggerApplication($this->fixture);
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'dep-1',
        'status' => ApplicationDeploymentStatus::SKIPPED->value,
        'trigger_reason' => ['reason_code' => 'no_match'],
        'skipped_count' => 2,
        'first_skipped_commit' => str_repeat('b', 40),
    ]);

    expect($deployment->refresh()->trigger_reason)->toBe(['reason_code' => 'no_match'])
        ->and($deployment->skipped_count)->toBe(2)
        ->and($deployment->first_skipped_commit)->toBe(str_repeat('b', 40));
});

it('has a skipped deployment status', function () {
    expect(ApplicationDeploymentStatus::from('skipped'))->toBe(ApplicationDeploymentStatus::SKIPPED);
});
