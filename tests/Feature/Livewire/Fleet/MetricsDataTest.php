<?php

use App\Actions\Server\StartSentinel;
use App\Livewire\Fleet\Metrics;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelMetricsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ConcurrentMetricsClient;

uses(RefreshDatabase::class);

class StubMetricsClient extends SentinelMetricsClient
{
    /** @var array<string, ?array> serverUuid => summary */
    public static array $summaries = [];

    /** @var array<string, array> serverUuid => containers */
    public static array $containers = [];

    public static array $histories = [];

    public static array $failingMetrics = [];

    public function summary(): ?array
    {
        return self::$summaries[$this->server->uuid] ?? null;
    }

    public function containersCurrent(): array
    {
        return self::$containers[$this->server->uuid] ?? [];
    }

    public function history(string $metric, string $range): array
    {
        if (in_array($metric, self::$failingMetrics, true)) {
            throw new RuntimeException('metric unavailable');
        }

        return self::$histories[$this->server->uuid][$metric] ?? [];
    }

    public function networkHistory(string $range): array
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

class ProcessTestableMetrics extends Metrics
{
    protected function metricsClient(Server $server): SentinelMetricsClient
    {
        return new ConcurrentMetricsClient($server);
    }
}

beforeEach(function () {
    // The settings hook starts Sentinel when metrics turn on. A sync queue would run it and make a real SSH call.
    Queue::fake();
    Concurrency::setDefaultInstance('sync');
    StubMetricsClient::$summaries = [];
    StubMetricsClient::$containers = [];
    StubMetricsClient::$histories = [];
    StubMetricsClient::$failingMetrics = [];

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->team = $team;
});

function metricsServer($team, $privateKey, bool $reachable = true): Server
{
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $server->settings->update(['is_metrics_enabled' => true]);
    $server->settings->forceFill(['is_reachable' => $reachable, 'is_usable' => $reachable])->saveQuietly();

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
    $dead = metricsServer($this->team, $this->privateKey, reachable: false);

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

it('counts a reachable server as online when Sentinel returns no summary', function () {
    $server = metricsServer($this->team, $this->privateKey);
    $server->update(['name' => 'web-1']);
    StubMetricsClient::$summaries = [$server->uuid => null];

    $component = Livewire::test(TestableMetrics::class)
        ->assertSee('No metrics')
        ->assertDontSee('Offline');

    $row = collect($component->get('serverRows'))->firstWhere('uuid', $server->uuid);
    expect($row['online'])->toBeTrue();
    expect($row['reporting'])->toBeFalse();
    expect($component->get('kpis')['serversOnline'])->toBe(1);
    expect($component->get('kpis')['serversReporting'])->toBe(0);
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
    $dead = metricsServer($this->team, $this->privateKey, reachable: false);
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

it('falls back to cpu for an unknown container metric', function () {
    $s = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$s->uuid => onlineSummary()];
    StubMetricsClient::$containers = [$s->uuid => [
        ['id' => 'a', 'cpu' => 5.0, 'memUsed' => 900, 'memPercent' => 9.0, 'diskBytes' => 1, 'net' => 0.0],
    ]];

    Livewire::withQueryParams(['cmetric' => 'foo'])
        ->test(TestableMetrics::class)
        ->assertOk()
        ->assertSet('containerMetric', 'cpu')
        ->set('containerMetric', 'bar')
        ->assertSet('containerMetric', 'cpu');
});

it('keys container rows by server so shared container ids do not collide', function () {
    $one = metricsServer($this->team, $this->privateKey);
    $two = metricsServer($this->team, $this->privateKey);
    $proxy = ['id' => 'coolify-proxy', 'cpu' => 1.0, 'memUsed' => 10, 'memPercent' => 1.0, 'diskBytes' => 1, 'net' => 0.0];

    StubMetricsClient::$summaries = [$one->uuid => onlineSummary(), $two->uuid => onlineSummary()];
    StubMetricsClient::$containers = [$one->uuid => [$proxy], $two->uuid => [$proxy]];

    Livewire::test(TestableMetrics::class)
        ->assertSeeHtml('wire:key="fleet-container-'.$one->uuid.'-coolify-proxy"')
        ->assertSeeHtml('wire:key="fleet-container-'.$two->uuid.'-coolify-proxy"');
});

it('passes the spark time axis to the KPI sparklines on first paint', function () {
    $s = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$s->uuid => onlineSummary()];

    $component = Livewire::test(TestableMetrics::class);
    $component->set('chartData', ['sparkCategories' => [1700000100000], 'cpuSpark' => [12.5]]);

    $component->assertSeeHtml('1700000100000');
});

it('removes a transferred server on refresh', function () {
    $server = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$server->uuid => onlineSummary()];
    $component = Livewire::test(TestableMetrics::class);
    expect($component->get('serverRows'))->toHaveCount(1);

    $server->update(['team_id' => Team::factory()->create()->id]);
    $component->call('loadData');

    expect($component->get('serverRows'))->toBe([]);
    expect($component->get('containersRaw'))->toBe([]);
    expect($component->get('kpis')['serversTotal'])->toBe(0);
});

it('permits team members to read metrics', function () {
    $server = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$server->uuid => onlineSummary()];
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);

    expect(Livewire::test(TestableMetrics::class)->get('kpis')['serversOnline'])->toBe(1);
});

it('does not query Sentinel on a server that Coolify cannot reach', function () {
    $server = metricsServer($this->team, $this->privateKey, reachable: false);
    StubMetricsClient::$summaries = [$server->uuid => onlineSummary()];
    StubMetricsClient::$histories = [$server->uuid => ['cpu' => [[1000, 25.0]]]];

    $component = Livewire::test(TestableMetrics::class);

    $row = collect($component->get('serverRows'))->firstWhere('uuid', $server->uuid);
    expect($row['online'])->toBeFalse();
    expect($row['reporting'])->toBeFalse();
    expect($component->get('chartData')['cpu'])->toBe([]);
});

it('shows the page when a metrics worker runs past the process timeout', function () {
    $server = metricsServer($this->team, $this->privateKey);
    Concurrency::shouldReceive('run')->andThrow(new RuntimeException('The process exceeded the timeout of 60 seconds.'));

    $component = Livewire::test(TestableMetrics::class)->assertOk();

    $row = collect($component->get('serverRows'))->firstWhere('uuid', $server->uuid);
    expect($row['reporting'])->toBeFalse();
    expect($component->get('kpis')['serversReporting'])->toBe(0);
});

it('keeps host trends when an older Sentinel has no summary endpoint', function () {
    $server = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$histories = [$server->uuid => ['cpu' => [[1000, 25.0]], 'memory' => [[1000, 100.0]]]];

    $component = Livewire::test(TestableMetrics::class);

    expect($component->get('chartData')['cpu'])->toBe([[1000, 25.0]]);
    expect($component->get('chartData')['memory'])->toBe([[1000, 100.0]]);
    expect($component->get('topContainers'))->toBe([]);
});

it('keeps other metrics when one independent operation fails', function () {
    $server = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$server->uuid => onlineSummary()];
    StubMetricsClient::$histories = [$server->uuid => ['cpu' => [[1000, 25.0]]]];
    StubMetricsClient::$failingMetrics = ['disk'];

    $component = Livewire::test(TestableMetrics::class);

    expect($component->get('chartData')['cpu'])->toBe([[1000, 25.0]]);
    expect($component->get('chartData')['disk'])->toBe([]);
    expect($component->get('kpis')['serversOnline'])->toBe(1);
});

it('resolves container names for the instance team with id zero', function () {
    $rootTeam = Team::factory()->create(['id' => 0]);
    auth()->user()->teams()->attach($rootTeam, ['role' => 'owner']);
    auth()->user()->unsetRelation('teams');
    session(['currentTeam' => $rootTeam]);
    $project = Project::factory()->create(['team_id' => 0]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);

    $meta = Livewire::test(TestableMetrics::class)->instance()->containerMeta($application->uuid);

    expect($meta['name'])->toBe($application->name);
    expect($meta['link'])->toContain($application->uuid);
});

it('denies metrics refresh after team membership is removed', function () {
    $this->withoutVite();
    $server = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$server->uuid => onlineSummary()];
    $component = Livewire::test(TestableMetrics::class);

    auth()->user()->teams()->detach($this->team);
    auth()->user()->unsetRelation('teams');

    $component->call('loadData')->assertForbidden();
});

it('clears stale container rankings after a server transfer', function () {
    $server = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$server->uuid => onlineSummary()];
    StubMetricsClient::$containers = [$server->uuid => [['id' => 'private-container', 'cpu' => 10]]];
    $component = Livewire::test(TestableMetrics::class);

    $server->update(['team_id' => Team::factory()->create()->id]);
    $component->set('containerMetric', 'memory');

    expect($component->get('topContainers'))->toBe([]);
    expect($component->get('serverRows'))->toBe([]);
});

it('merges metrics from real concurrent workers and isolates endpoint failures', function () {
    Concurrency::setDefaultInstance('process');
    metricsServer($this->team, $this->privateKey);
    metricsServer($this->team, $this->privateKey);

    $component = Livewire::test(ProcessTestableMetrics::class);

    expect($component->get('kpis')['serversOnline'])->toBe(2);
    expect($component->get('topContainers'))->toHaveCount(2);
    expect($component->get('chartData')['cpu'])->toBe([[1000, 25.0]]);
    expect($component->get('chartData')['memory'])->toBe([[1000, 200.0]]);
    expect($component->get('chartData')['disk'])->toBe([]);
    expect($component->get('chartData')['networkRx'])->toBe([[1000, 10.0]]);
});

it('clears stale metrics after the last server is deleted', function () {
    $server = metricsServer($this->team, $this->privateKey);
    StubMetricsClient::$summaries = [$server->uuid => onlineSummary()];
    StubMetricsClient::$containers = [$server->uuid => [['id' => 'deleted-container', 'cpu' => 10]]];
    $component = Livewire::test(TestableMetrics::class);

    $server->delete();
    $component->set('containerMetric', 'memory');

    expect($component->get('topContainers'))->toBe([]);
    expect($component->get('serverRows'))->toBe([]);
});

it('repairs the Sentinel token before creating worker clients', function () {
    $server = metricsServer($this->team, $this->privateKey);
    $server->settings->updateQuietly(['sentinel_token' => 'invalid token']);
    app()->bind(SentinelMetricsClient::class, fn ($app, $parameters) => new ConcurrentMetricsClient($parameters['server']));

    loadLazy(Livewire::test(Metrics::class));

    $token = $server->settings->fresh()->sentinel_token;
    expect(ServerSetting::isValidSentinelToken($token))->toBeTrue();
    expect($token)->not->toBe('invalid token');
});

/** A server that Coolify can reach, with metrics off. */
function reachableServerWithoutMetrics(int $teamId, PrivateKey $privateKey): Server
{
    $server = Server::factory()->create(['team_id' => $teamId, 'private_key_id' => $privateKey->id]);
    $server->settings->forceFill(['is_reachable' => true, 'is_usable' => true])->saveQuietly();

    return $server;
}

it('enables metrics on each eligible server of the team from the empty state', function () {
    $first = reachableServerWithoutMetrics($this->team->id, $this->privateKey);
    $second = reachableServerWithoutMetrics($this->team->id, $this->privateKey);
    $build = reachableServerWithoutMetrics($this->team->id, $this->privateKey);
    $build->settings->update(['server_role' => 'build', 'is_build_server' => true]);
    $unreachable = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $foreign = reachableServerWithoutMetrics(Team::factory()->create()->id, $this->privateKey);

    $component = Livewire::test(TestableMetrics::class)
        ->assertSee('Enable metrics on 2 servers')
        ->call('enableMetrics');

    expect($first->settings->fresh()->is_metrics_enabled)->toBeTruthy();
    expect($second->settings->fresh()->is_metrics_enabled)->toBeTruthy();
    expect($build->settings->fresh()->is_metrics_enabled)->toBeFalsy();
    expect($unreachable->settings->fresh()->is_metrics_enabled)->toBeFalsy();
    expect($foreign->settings->fresh()->is_metrics_enabled)->toBeFalsy();
    StartSentinel::assertPushed(2);

    expect(array_column($component->get('serverRows'), 'uuid'))->toEqualCanonicalizing([$first->uuid, $second->uuid]);
    $component->assertDontSee('No server metrics yet');
});

it('keeps enabling metrics on the other servers when one server fails', function () {
    $broken = reachableServerWithoutMetrics($this->team->id, $this->privateKey);
    $healthy = reachableServerWithoutMetrics($this->team->id, $this->privateKey);
    DB::statement("CREATE TRIGGER fail_settings_update BEFORE UPDATE ON server_settings WHEN NEW.server_id = {$broken->id} BEGIN SELECT RAISE(ABORT, 'write failed'); END");

    Livewire::test(TestableMetrics::class)
        ->call('enableMetrics')
        ->assertDispatched('success', fn (string $event, array $params) => str_contains($params[0], 'Metrics enabled on 1 server.'))
        ->assertDispatched('error', fn (string $event, array $params) => str_contains($params[0], $broken->name));

    expect($broken->settings->fresh()->is_metrics_enabled)->toBeFalsy();
    expect($healthy->settings->fresh()->is_metrics_enabled)->toBeTruthy();
    StartSentinel::assertPushed(1);
});

it('does not let a team member enable metrics', function () {
    $server = reachableServerWithoutMetrics($this->team->id, $this->privateKey);
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(TestableMetrics::class)
        ->assertSee('No server metrics yet')
        ->assertDontSee('Enable metrics on '.$server->name)
        ->call('enableMetrics');

    expect($server->settings->fresh()->is_metrics_enabled)->toBeFalsy();
    StartSentinel::assertNotPushed();
});
