<?php

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

function isStartSentinelJob($job): bool
{
    return $job instanceof JobDecorator && $job->getAction() instanceof StartSentinel;
}

beforeEach(function () {
    Queue::fake();

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
            $settings->wasChanged('sentinel_push_interval_seconds') ||
            $settings->wasChanged('traffic_topn') ||
            $settings->wasChanged('traffic_sample_threshold') ||
            $settings->wasChanged('traffic_retention_1h_days') ||
            $settings->wasChanged('traffic_retention_1d_days') ||
            $settings->wasChanged('is_geoip_enabled') ||
            $settings->wasChanged('geoip_refresh_days') ||
            $settings->wasChanged('geoip_maxmind_license_key')
        ) {
            $changeDetected = true;
        }
    });

    $settings = $this->server->settings;
    $settings->is_reachable = ! $settings->is_reachable;
    $settings->save();

    expect($changeDetected)->toBeFalse();
});

it('detects traffic analytics setting changes with wasChanged', function () {
    $changeDetected = false;

    ServerSetting::updated(function ($settings) use (&$changeDetected) {
        if ($settings->wasChanged('traffic_topn')) {
            $changeDetected = true;
        }
    });

    $settings = $this->server->settings;
    $settings->traffic_topn = 200;
    $settings->save();

    expect($changeDetected)->toBeTrue();
});

it('does not restart sentinel when a traffic knob changes while sentinel is disabled', function () {
    $settings = $this->server->settings;
    $settings->is_sentinel_enabled = false;
    $settings->save();

    $settings->traffic_topn = 999;
    $settings->save();

    Queue::assertNotPushed(JobDecorator::class, fn ($job) => isStartSentinelJob($job));
});

it('restarts sentinel when a traffic knob changes while sentinel is enabled', function () {
    $settings = $this->server->settings;
    $settings->is_sentinel_enabled = true;
    $settings->save();

    $settings->traffic_topn = 888;
    $settings->save();

    Queue::assertPushed(JobDecorator::class, fn ($job) => isStartSentinelJob($job));
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
