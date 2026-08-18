<?php

use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create user (which automatically creates a team)
    $user = User::factory()->create();
    $this->team = $user->teams()->first();

    // Create server with the team
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

it('detects sentinel_token changes with wasChanged', function () {
    $changeDetected = false;

    // Register a test listener that will be called after the model's booted listeners
    ServerSetting::updated(function ($settings) use (&$changeDetected) {
        if ($settings->wasChanged('sentinel_token')) {
            $changeDetected = true;
        }
    });

    $settings = $this->server->settings;
    $settings->sentinel_token = 'new-token-value';
    $settings->save();

    expect($changeDetected)->toBeTrue();
});

it('detects sentinel_custom_url changes with wasChanged', function () {
    $changeDetected = false;

    ServerSetting::updated(function ($settings) use (&$changeDetected) {
        if ($settings->wasChanged('sentinel_custom_url')) {
            $changeDetected = true;
        }
    });

    $settings = $this->server->settings;
    $settings->sentinel_custom_url = 'https://new-url.com';
    $settings->save();

    expect($changeDetected)->toBeTrue();
});

it('detects sentinel_metrics_refresh_rate_seconds changes with wasChanged', function () {
    $changeDetected = false;

    ServerSetting::updated(function ($settings) use (&$changeDetected) {
        if ($settings->wasChanged('sentinel_metrics_refresh_rate_seconds')) {
            $changeDetected = true;
        }
    });

    $settings = $this->server->settings;
    $settings->sentinel_metrics_refresh_rate_seconds = 60;
    $settings->save();

    expect($changeDetected)->toBeTrue();
});

it('detects sentinel_metrics_history_days changes with wasChanged', function () {
    $changeDetected = false;

    ServerSetting::updated(function ($settings) use (&$changeDetected) {
        if ($settings->wasChanged('sentinel_metrics_history_days')) {
            $changeDetected = true;
        }
    });

    $settings = $this->server->settings;
    $settings->sentinel_metrics_history_days = 14;
    $settings->save();

    expect($changeDetected)->toBeTrue();
});

it('detects sentinel_push_interval_seconds changes with wasChanged', function () {
    $changeDetected = false;

    ServerSetting::updated(function ($settings) use (&$changeDetected) {
        if ($settings->wasChanged('sentinel_push_interval_seconds')) {
            $changeDetected = true;
        }
    });

    $settings = $this->server->settings;
    $settings->sentinel_push_interval_seconds = 30;
    $settings->save();

    expect($changeDetected)->toBeTrue();
});

it('does not detect changes when unrelated field is changed', function () {
    $changeDetected = false;

    ServerSetting::updated(function ($settings) use (&$changeDetected) {
        if (
            $settings->wasChanged('sentinel_token') ||
            $settings->wasChanged('sentinel_custom_url') ||
            $settings->wasChanged('sentinel_metrics_refresh_rate_seconds') ||
            $settings->wasChanged('sentinel_metrics_history_days') ||
            $settings->wasChanged('sentinel_push_interval_seconds')
        ) {
            $changeDetected = true;
        }
    });

    $settings = $this->server->settings;
    $settings->is_reachable = ! $settings->is_reachable;
    $settings->save();

    expect($changeDetected)->toBeFalse();
});

it('does not detect changes when sentinel field is set to same value', function () {
    $changeDetected = false;

    ServerSetting::updated(function ($settings) use (&$changeDetected) {
        if ($settings->wasChanged('sentinel_token')) {
            $changeDetected = true;
        }
    });

    $settings = $this->server->settings;
    $currentToken = $settings->sentinel_token;
    $settings->sentinel_token = $currentToken;
    $settings->save();

    expect($changeDetected)->toBeFalse();
});
