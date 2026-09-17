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

class StubMetricsClient extends SentinelMetricsClient
{
    /** @var array<string, ?array> serverUuid => summary */
    public static array $summaries = [];

    /** @var array<string, array> serverUuid => containers */
    public static array $containers = [];

    public function summary(): ?array
    {
        return self::$summaries[$this->server->uuid] ?? null;
    }

    public function containersCurrent(): array
    {
        return self::$containers[$this->server->uuid] ?? [];
    }

    public function history(string $metric, string $from): array
    {
        return [];
    }

    public function networkHistory(string $from): array
    {
        return ['rx' => [], 'tx' => []];
    }
}

class TestableMetrics extends Metrics
{
    protected function metricsClient(Server $server): SentinelMetricsClient
    {
        return new StubMetricsClient($server);
    }
}

beforeEach(function () {
    StubMetricsClient::$summaries = [];
    StubMetricsClient::$containers = [];

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->team = $team;
});

function metricsServer($team, $privateKey): Server
{
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $server->settings->update(['is_metrics_enabled' => true]);

    return $server;
}

function onlineSummary(array $overrides = []): array
{
    return array_merge([
        'online' => true, 'cpu' => 30.0, 'memUsed' => 100, 'memTotal' => 200,
        'diskUsed' => 10, 'diskTotal' => 100, 'diskPercent' => 10.0,
        'load1' => 0.4, 'netRx' => 5.0, 'netTx' => 1.0,
    ], $overrides);
}

it('keeps an unreachable server in the table but excludes it from KPIs', function () {
    $ok = metricsServer($this->team, $this->privateKey);
    $dead = metricsServer($this->team, $this->privateKey);

    StubMetricsClient::$summaries = [
        $ok->uuid => onlineSummary(['cpu' => 30.0]),
        $dead->uuid => null,
    ];

    $component = Livewire::test(TestableMetrics::class);

    expect($component->get('serverRows'))->toHaveCount(2);

    $deadRow = collect($component->get('serverRows'))->firstWhere('uuid', $dead->uuid);
    expect($deadRow['online'])->toBeFalse();
    expect($deadRow['cpu'])->toBeNull();

    expect($component->get('kpis')['serversOnline'])->toBe(1);
    expect($component->get('kpis')['cpuAvg'])->toBe(30.0);
});

it('ranks containers across servers by the selected metric', function () {
    $s = metricsServer($this->team, $this->privateKey);

    StubMetricsClient::$summaries = [$s->uuid => onlineSummary(['diskPercent' => 50.0])];
    StubMetricsClient::$containers = [$s->uuid => [
        ['id' => 'low', 'cpu' => 5.0, 'memUsed' => 10, 'memPercent' => 1.0, 'diskBytes' => 1, 'net' => 0.0],
        ['id' => 'high', 'cpu' => 88.0, 'memUsed' => 20, 'memPercent' => 2.0, 'diskBytes' => 2, 'net' => 0.0],
    ]];

    $component = Livewire::test(TestableMetrics::class);

    expect(array_column($component->get('topContainers'), 'id')[0])->toBe('high');
});

it('shows the KPI tiles, a stale badge for offline servers, and the container toggle', function () {
    $ok = metricsServer($this->team, $this->privateKey);
    $ok->update(['name' => 'web-1']);
    $dead = metricsServer($this->team, $this->privateKey);
    $dead->update(['name' => 'web-2']);

    StubMetricsClient::$summaries = [
        $ok->uuid => onlineSummary(['cpu' => 95.0, 'diskPercent' => 95.0]),
        $dead->uuid => null,
    ];

    Livewire::test(TestableMetrics::class)
        ->assertSee('web-1')
        ->assertSee('web-2')
        ->assertSee('Fleet CPU')
        ->assertSee('healthy')
        ->assertSee('attention')
        ->assertSee('Offline');
});

it('re-ranks containers on metric switch without re-fetching', function () {
    $s = metricsServer($this->team, $this->privateKey);

    StubMetricsClient::$summaries = [$s->uuid => onlineSummary()];
    StubMetricsClient::$containers = [$s->uuid => [
        ['id' => 'a', 'cpu' => 5.0, 'memUsed' => 900, 'memPercent' => 9.0, 'diskBytes' => 1, 'net' => 0.0],
        ['id' => 'b', 'cpu' => 90.0, 'memUsed' => 100, 'memPercent' => 1.0, 'diskBytes' => 9, 'net' => 0.0],
    ]];

    $component = Livewire::test(TestableMetrics::class);
    expect(array_column($component->get('topContainers'), 'id')[0])->toBe('b'); // cpu

    // Empty the stub so a re-fetch would change nothing/ break; the re-rank must use cached rows.
    StubMetricsClient::$containers = [];
    $component->set('containerMetric', 'memory');

    expect(array_column($component->get('topContainers'), 'id')[0])->toBe('a'); // memory, still ranked
});

it('cannot see another team’s servers', function () {
    $otherTeam = Team::factory()->create();
    // Reuse the existing key id: the factory's private key is hardcoded, so a second
    // PrivateKey::create collides on fingerprint. Only the server's team_id matters here.
    $server = Server::factory()->create(['team_id' => $otherTeam->id, 'private_key_id' => $this->privateKey->id]);
    $server->settings->update(['is_metrics_enabled' => true]);

    $component = Livewire::test(TestableMetrics::class);

    expect($component->get('serverRows'))->toHaveCount(0);
});

it('drops the "Fleet" tile prefix when scoped to a single server', function () {
    $s = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$s->uuid => onlineSummary()];

    Livewire::test(TestableMetrics::class)
        ->assertSee('Fleet CPU')
        ->set('serverUuid', $s->uuid)
        ->assertDontSee('Fleet')
        ->assertSee('for the selected server');
});

it('renders container metric values without leaking a blade directive', function () {
    $s = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$s->uuid => onlineSummary()];
    StubMetricsClient::$containers = [$s->uuid => [
        ['id' => 'a', 'cpu' => 5.0, 'memUsed' => 900, 'memPercent' => 9.0, 'diskBytes' => 1, 'net' => 2048.0],
    ]];

    Livewire::test(TestableMetrics::class)
        ->set('containerMetric', 'network')
        ->assertDontSee('@break')
        ->assertSee('/s');
});
