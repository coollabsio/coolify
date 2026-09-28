<?php

use App\Livewire\Project\Shared\Metrics;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings->fill([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'is_metrics_enabled' => true,
    ])->save();
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'status' => 'degraded:unhealthy',
    ]);
});

function fakeComposeMetricsServer(Application $application, array $states): void
{
    $containers = collect($states)->map(fn (string $state, string $service) => json_encode([
        'Names' => "{$service}-{$application->uuid}-20260101T120000",
        'Labels' => "coolify.applicationId={$application->id},coolify.pullRequestId=0,com.docker.compose.service={$service},coolify.name={$service}-{$application->uuid}",
        'State' => $state,
    ]))->implode("\n");

    Process::fake(function ($process) use ($containers) {
        if (str_contains($process->command, 'docker ps -a')) {
            return Process::result(output: $containers);
        }
        if (str_contains($process->command, 'coolify-sentinel')) {
            return Process::result(output: '[{"time":"1700000000000","percent":"12.5","used":10747904}]');
        }

        return Process::result();
    });
}

it('does not query docker while rendering the metrics page', function () {
    fakeComposeMetricsServer($this->application, ['web' => 'running']);

    Livewire::test(Metrics::class, ['resource' => $this->application])
        ->assertSet('containersLoaded', false)
        ->assertSeeHtml('$wire.loadData()');

    Process::assertNothingRan();
});

it('shows metrics for each running service of a docker compose application', function () {
    fakeComposeMetricsServer($this->application, ['web' => 'running', 'api' => 'running', 'migrate' => 'exited']);
    $uuid = $this->application->uuid;

    Livewire::test(Metrics::class, ['resource' => $this->application])
        ->call('loadData')
        ->assertSet('containers', ["api-{$uuid}" => 'api', "web-{$uuid}" => 'web'])
        ->assertSet('container', "api-{$uuid}")
        ->assertDontSee('Metrics unavailable')
        ->assertSee('Service')
        ->assertDispatched('refreshChartData-metrics-cpu', ['seriesData' => [[1700000000000, 12.5]]])
        ->assertDispatched('refreshChartData-metrics-memory', ['seriesData' => [[1700000000000, 10.25]]])
        ->set('container', "web-{$uuid}")
        ->call('loadData')
        ->assertSet('container', "web-{$uuid}");

    Process::assertRan(fn ($process) => str_contains($process->command, "/api/container/api-{$uuid}/cpu/history"));
    Process::assertRan(fn ($process) => str_contains($process->command, "/api/container/web-{$uuid}/memory/history"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "/api/container/migrate-{$uuid}/"));
});

it('hides the service selector when a docker compose application has a single running service', function () {
    fakeComposeMetricsServer($this->application, ['web' => 'running']);

    Livewire::test(Metrics::class, ['resource' => $this->application])
        ->call('loadData')
        ->assertSet('container', "web-{$this->application->uuid}")
        ->assertDontSee('Service')
        ->assertSee('Time range');
});

it('only reads metrics of containers that belong to the docker compose application', function () {
    fakeComposeMetricsServer($this->application, ['web' => 'running']);

    $component = Livewire::test(Metrics::class, ['resource' => $this->application])
        ->set('container', 'coolify-db')
        ->call('loadData')
        ->assertSet('container', "web-{$this->application->uuid}");

    Process::assertNotRan(fn ($process) => str_contains($process->command, '/api/container/coolify-db/'));
    expect(fn () => $component->set('containers', ['coolify-db' => 'db']))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('shows the not running state when no docker compose container is running', function () {
    fakeComposeMetricsServer($this->application, ['web' => 'exited']);

    Livewire::test(Metrics::class, ['resource' => $this->application])
        ->call('loadData')
        ->assertSet('containers', [])
        ->assertSee('Container is not running')
        ->assertDontSeeHtml('$wire.loadData()');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'coolify-sentinel'));
});

it('does not look for containers of a stopped docker compose application', function () {
    fakeComposeMetricsServer($this->application, ['web' => 'running']);
    $this->application->update(['status' => 'exited']);

    Livewire::test(Metrics::class, ['resource' => $this->application->fresh()])
        ->assertSee('Container is not running')
        ->assertDontSeeHtml('$wire.loadData()');
});

it('reads metrics of single container resources by their uuid only', function () {
    fakeComposeMetricsServer($this->application, []);
    $this->application->update(['build_pack' => 'nixpacks', 'status' => 'running:healthy']);
    $database = StandalonePostgresql::create([
        'name' => 'metrics-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'status' => 'running:healthy',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);

    foreach ([$this->application->fresh(), $database] as $resource) {
        Livewire::test(Metrics::class, ['resource' => $resource])
            ->set('container', 'coolify-db')
            ->call('loadData');

        Process::assertRan(fn ($process) => str_contains($process->command, "/api/container/{$resource->uuid}/cpu/history"));
    }

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker ps -a'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, '/api/container/coolify-db/'));
});

it('rejects invalid container names before reading metrics', function () {
    Process::fake();

    expect(fn () => $this->application->getCpuMetrics(5, "web';id;'"))
        ->toThrow(InvalidArgumentException::class, 'Invalid container name.');
    Process::assertNothingRan();
});
