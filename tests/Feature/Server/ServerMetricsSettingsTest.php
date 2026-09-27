<?php

use App\Livewire\Server\Charts;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->first();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('shows metrics collection settings inside the main metrics section instead of a separate section', function () {
    $metricsView = file_get_contents(resource_path('views/livewire/server/charts.blade.php'));
    $sentinelView = file_get_contents(resource_path('views/livewire/server/sentinel.blade.php'));

    expect($metricsView)
        ->not->toContain('id="server-metrics-collection-section"')
        ->toContain('id="sentinelMetricsRefreshRateSeconds"')
        ->toContain('id="sentinelMetricsHistoryDays"')
        ->toContain('id="sentinelPushIntervalSeconds"')
        ->and($sentinelView)
        ->not->toContain('id="server-sentinel-metrics-section"')
        ->not->toContain('id="sentinelMetricsRefreshRateSeconds"')
        ->not->toContain('id="sentinelMetricsHistoryDays"')
        ->not->toContain('id="sentinelPushIntervalSeconds"');
});

it('saves metrics collection settings from the server metrics page', function () {
    Queue::fake();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_sentinel_enabled = true;
    $server->settings->is_metrics_enabled = true;
    $server->settings->save();

    Livewire::test(Charts::class, ['server_uuid' => $server->uuid])
        ->set('sentinelMetricsRefreshRateSeconds', 15)
        ->set('sentinelMetricsHistoryDays', 14)
        ->set('sentinelPushIntervalSeconds', 90)
        ->call('saveMetricsSettings')
        ->assertHasNoErrors();

    $settings = $server->settings->fresh();

    expect($settings->sentinel_metrics_refresh_rate_seconds)->toBe(15)
        ->and($settings->sentinel_metrics_history_days)->toBe(14)
        ->and($settings->sentinel_push_interval_seconds)->toBe(90);
});

it('validates metrics collection settings on the server metrics page', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(Charts::class, ['server_uuid' => $server->uuid])
        ->set('sentinelMetricsRefreshRateSeconds', 0)
        ->set('sentinelMetricsHistoryDays', 0)
        ->set('sentinelPushIntervalSeconds', 9)
        ->call('saveMetricsSettings')
        ->assertHasErrors([
            'sentinelMetricsRefreshRateSeconds',
            'sentinelMetricsHistoryDays',
            'sentinelPushIntervalSeconds',
        ]);
});

it('uses the local datetime axis so server charts show day separators', function () {
    $metricsView = file_get_contents(resource_path('views/livewire/server/charts.blade.php'));

    expect($metricsView)
        ->not->toContain('datetimeUTC: true')
        ->and(substr_count($metricsView, 'datetimeUTC: false'))
        ->toBe(3);
});

it('updates CPU and memory charts together when the time range changes', function () {
    $component = file_get_contents(app_path('Livewire/Server/Charts.php'));
    $metricsView = file_get_contents(resource_path('views/livewire/server/charts.blade.php'));

    expect($component)
        ->toContain('"refreshChartData-{$this->chartId}-metrics"')
        ->toContain("'cpuSeries' => \$cpuMetrics")
        ->toContain("'memorySeries' => \$memoryMetrics")
        ->and($metricsView)
        ->toContain("Livewire.on('refreshChartData-{!! \$chartId !!}-metrics'")
        ->toContain('data.cpuSeries')
        ->toContain('data.memorySeries');
});
