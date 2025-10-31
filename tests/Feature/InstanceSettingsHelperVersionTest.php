<?php

use App\Jobs\PullHelperImageJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches PullHelperImageJob when helper_version changes', function () {
    Queue::fake();

    // Create user and servers
    $user = User::factory()->create();
    $team = $user->teams()->first();
    Server::factory()->count(3)->create(['team_id' => $team->id]);

    $settings = InstanceSettings::firstOrCreate([], ['helper_version' => 'v1.0.0']);

    // Change helper_version
    $settings->helper_version = 'v1.2.3';
    $settings->save();

    // Verify PullHelperImageJob was dispatched for all servers
    Queue::assertPushed(PullHelperImageJob::class, 3);
});

it('does not dispatch PullHelperImageJob when helper_version is unchanged', function () {
    Queue::fake();

    // Create user and servers
    $user = User::factory()->create();
    $team = $user->teams()->first();
    Server::factory()->count(3)->create(['team_id' => $team->id]);

    $settings = InstanceSettings::firstOrCreate([], ['helper_version' => 'v1.0.0']);
    $currentVersion = $settings->helper_version;

    // Set to same value
    $settings->helper_version = $currentVersion;
    $settings->save();

    // Verify no jobs were dispatched
    Queue::assertNotPushed(PullHelperImageJob::class);
});

it('does not dispatch PullHelperImageJob when other fields change', function () {
    Queue::fake();

    // Create user and servers
    $user = User::factory()->create();
    $team = $user->teams()->first();
    Server::factory()->count(3)->create(['team_id' => $team->id]);

    $settings = InstanceSettings::firstOrCreate([], ['helper_version' => 'v1.0.0']);

    // Change different field
    $settings->is_auto_update_enabled = ! $settings->is_auto_update_enabled;
    $settings->save();

    // Verify no jobs were dispatched
    Queue::assertNotPushed(PullHelperImageJob::class);
});

it('detects helper_version changes with wasChanged', function () {
    $changeDetected = false;

    InstanceSettings::updated(function ($settings) use (&$changeDetected) {
        if ($settings->wasChanged('helper_version')) {
            $changeDetected = true;
        }
    });

    $settings = InstanceSettings::firstOrCreate([], ['helper_version' => 'v1.0.0']);
    $settings->helper_version = 'v2.0.0';
    $settings->save();

    expect($changeDetected)->toBeTrue();
});
