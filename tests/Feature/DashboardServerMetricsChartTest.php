<?php

use App\Livewire\Dashboard;
use App\Livewire\Server\Index as ServerIndex;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->team = $team;
});

it('renders a metrics chart only for servers with metrics enabled', function () {
    $enabledServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $enabledServer->settings->update(['is_metrics_enabled' => true]);

    $disabledServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $disabledServer->settings->update(['is_metrics_enabled' => false]);

    Livewire::test(Dashboard::class)
        ->assertSeeHtml("dashboard-server-metrics-{$enabledServer->uuid}")
        ->assertDontSeeHtml("dashboard-server-metrics-{$disabledServer->uuid}");
});

it('renders dashboard metrics charts on the server index grid', function () {
    $enabledServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $enabledServer->settings->update(['is_metrics_enabled' => true]);

    $disabledServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $disabledServer->settings->update(['is_metrics_enabled' => false]);

    Livewire::test(ServerIndex::class)
        ->assertSeeHtml("dashboard-server-metrics-{$enabledServer->uuid}")
        ->assertDontSeeHtml("dashboard-server-metrics-{$disabledServer->uuid}");
});

it('configures the dashboard chart as a ten minute cpu and memory sparkline with hover details', function () {
    $chart = file_get_contents(resource_path('views/livewire/dashboard/server-metrics-chart.blade.php'));
    $component = file_get_contents(app_path('Livewire/Dashboard/ServerMetricsChart.php'));

    expect($component)
        ->toContain('$this->server->getCpuMetrics(10)')
        ->toContain('$this->server->getMemoryMetrics(10)');
    expect($chart)
        ->toContain('new ApexCharts')
        ->toContain('sparkline: { enabled: true }')
        ->toContain('absolute right-0 bottom-0 h-2/3 w-full')
        ->toContain('[&_.apexcharts-svg]:overflow-hidden')
        ->not->toContain('w-full overflow-hidden rounded-b-xl')
        ->toContain('CPU:')
        ->toContain('Memory:')
        ->toContain('formatTimestamp(timestamp)')
        ->toContain("min: 0,\n                            max: 100,")
        ->toContain('labels: { show: false }');
});

it('refreshes dashboard metrics every minute while visible and after returning from the background', function () {
    $chart = file_get_contents(resource_path('views/livewire/dashboard/server-metrics-chart.blade.php'));

    expect($chart)
        ->toContain('window.setInterval')
        ->toContain('60000')
        ->toContain('document.hidden')
        ->toContain("document.addEventListener('visibilitychange'")
        ->toContain('Date.now() - this.hiddenAt >= 60000')
        ->toContain('$wire.loadData()')
        ->toContain('window.clearInterval')
        ->toContain("document.removeEventListener('visibilitychange'");
});

it('keeps the status badge only on server cards without metrics', function () {
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

    expect($dashboard)->toContain('@unless ($server->isMetricsEnabled())');
});
