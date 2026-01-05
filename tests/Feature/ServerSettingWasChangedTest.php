<?php

use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('wasChanged returns true after saving a changed field', function () {
    // Create user and server
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $settings = $server->settings;

    // Change a field
    $settings->is_reachable = ! $settings->is_reachable;
    $settings->save();

    // In the updated hook, wasChanged should return true
    expect($settings->wasChanged('is_reachable'))->toBeTrue();
});

it('isDirty returns false after saving', function () {
    // Create user and server
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $settings = $server->settings;

    // Change a field
    $settings->is_reachable = ! $settings->is_reachable;
    $settings->save();

    // After save, isDirty returns false (this is the bug)
    expect($settings->isDirty('is_reachable'))->toBeFalse();
});

it('can detect sentinel_token changes with wasChanged', function () {
    // Create user and server
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $settings = $server->settings;
    $originalToken = $settings->sentinel_token;

    // Create a tracking variable using model events
    $tokenWasChanged = false;
    ServerSetting::updated(function ($model) use (&$tokenWasChanged) {
        if ($model->wasChanged('sentinel_token')) {
            $tokenWasChanged = true;
        }
    });

    // Change the token
    $settings->sentinel_token = 'new-token-value-for-testing';
    $settings->save();

    expect($tokenWasChanged)->toBeTrue();
});
