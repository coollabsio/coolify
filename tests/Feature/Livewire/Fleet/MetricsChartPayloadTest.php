<?php

use App\Livewire\Fleet\Metrics;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelMetricsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class ChartStubClient extends SentinelMetricsClient
{
    public function summary(): ?array
    {
        return ['online' => true, 'cpu' => 10.0, 'memUsed' => 1, 'memTotal' => 2,
            'diskUsed' => 1, 'diskTotal' => 2, 'diskPercent' => 50.0, 'load1' => 0.1, 'netRx' => 0.0, 'netTx' => 0.0];
    }

    public function containersCurrent(): array
    {
        return [];
    }

    public function history(string $metric, string $from): array
    {
        return [[1000, 10.0], [2000, 20.0]];
    }

    public function networkHistory(string $from): array
    {
        return ['rx' => [[1000, 5.0]], 'tx' => [[1000, 1.0]]];
    }
}

class ChartTestableMetrics extends Metrics
{
    protected function metricsClient(Server $server): SentinelMetricsClient
    {
        return new ChartStubClient($server);
    }
}

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->team = $team;
});

it('dispatches a chart payload with every trend series', function () {
    $s = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $s->settings->update(['is_metrics_enabled' => true]);

    Livewire::test(ChartTestableMetrics::class)
        ->call('loadData')
        ->assertDispatched('refreshChartData-fleet-metrics', function ($event, $params) {
            $payload = $params[0];

            // Livewire JSON-serializes event params, so whole floats arrive as ints;
            // compare loosely (10 == 10.0).
            return $payload['cpu'] == [[1000, 10], [2000, 20]]
                && $payload['networkRx'] == [[1000, 5]]
                && array_key_exists('load', $payload);
        });
});
