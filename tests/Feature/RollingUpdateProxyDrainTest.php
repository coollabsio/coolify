<?php

use App\Enums\ProxyTypes;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
});

class TestableRollingUpdateDrainDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}
}

function createApplicationForRollingUpdateDrainTest(string $proxyType = 'TRAEFIK', bool $exactLabels = false): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
        'proxy' => ['type' => $proxyType],
    ]);
    $server->settings->update(['generate_exact_labels' => $exactLabels]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://example.com',
        'ports_exposes' => '3000',
    ])->fresh();
}

/**
 * Runs the drain step of a rolling update against a faked server and returns the deployment log lines.
 *
 * @param  array<string, string>|null  $oldProxyLabels  labels of the running old container, null when there is none
 * @param  array<string, string>  $newProxyLabels
 * @return list<string>
 */
function runRollingUpdateDrainStep(Application $application, bool $newVersionIsHealthy, ?array $oldProxyLabels = ['traefik.enable' => 'true'], array $newProxyLabels = ['traefik.enable' => 'true']): array
{
    Process::fake(function ($process) use ($application, $oldProxyLabels, $newProxyLabels) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        if (str_contains($command, 'docker inspect')) {
            return Process::result(output: json_encode(['coolify.name' => 'new', ...$newProxyLabels])."\n".json_encode(['coolify.name' => 'old', ...$oldProxyLabels]));
        }
        if (str_contains($command, 'docker ps') && str_contains($command, "coolify.applicationUuid={$application->uuid}")) {
            $containers = [['ID' => 'new-id', 'Names' => 'app-new', 'State' => 'running', 'Labels' => '']];
            if ($oldProxyLabels !== null) {
                $containers[] = ['ID' => 'old-id', 'Names' => 'app-old', 'State' => 'running', 'Labels' => ''];
            }

            return Process::result(output: collect($containers)->map(fn (array $container) => json_encode($container))->implode("\n"));
        }

        return Process::result(output: '');
    });

    $logs = [];
    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message) use (&$logs) {
        $logs[] = $message;
    });

    $job = new TestableRollingUpdateDrainDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'deployment_uuid' => 'rolling-update-drain',
        'server' => $application->destination->server,
        'container_name' => 'app-new',
        'pull_request_id' => 0,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    Cache::forget('deployment-new-version-healthy:rolling-update-drain');
    if ($newVersionIsHealthy) {
        Cache::put('deployment-new-version-healthy:rolling-update-drain', true);
    }

    $reflection->getMethod('wait_for_proxy_to_route_new_container')->invoke($job);

    return $logs;
}

it('retries failed connections on another container in Traefik application labels', function (bool $exactLabels) {
    $application = createApplicationForRollingUpdateDrainTest(ProxyTypes::TRAEFIK->value, $exactLabels);

    $labels = collect(generateLabelsApplication($application));

    expect($labels)->toContain("traefik.http.middlewares.retry-{$application->uuid}.retry.attempts=5");
    $routerMiddlewares = $labels->filter(fn (string $label) => str_contains($label, "routers.https-0-{$application->uuid}.middlewares="));
    expect($routerMiddlewares)->toHaveCount(1)
        ->and($routerMiddlewares->first())->toContain("retry-{$application->uuid}");
})->with([
    'generic labels' => false,
    'exact labels' => true,
]);

it('retries failed connections on another upstream in Caddy application labels', function () {
    $application = createApplicationForRollingUpdateDrainTest(ProxyTypes::CADDY->value, exactLabels: true);

    expect(generateLabelsApplication($application))
        ->toContain('caddy_0.handle_path.0_reverse_proxy.lb_try_duration=5s');
});

it('does not add retry labels to preview deployments', function () {
    $application = createApplicationForRollingUpdateDrainTest();
    $preview = ApplicationPreview::forceCreate([
        'application_id' => $application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/7',
        'fqdn' => 'https://pr-7.example.com',
    ]);

    expect(implode("\n", generateLabelsApplication($application, $preview)))
        ->not->toContain('retry')
        ->not->toContain('lb_try_duration');
});

it('waits for the proxy before old containers stop when the new container is healthy', function () {
    Sleep::fake();
    $application = createApplicationForRollingUpdateDrainTest();

    $logs = runRollingUpdateDrainStep($application, newVersionIsHealthy: true);

    Sleep::assertSequence([Sleep::for(DEFAULT_ROLLING_UPDATE_DRAIN_SECONDS)->seconds()]);
    expect($logs)->toBe(['Waiting 5 seconds for the proxy to send traffic to the new container.']);
});

it('does not wait when the drain time is 0 or the new container is not healthy', function (int $drainSeconds, bool $newVersionIsHealthy) {
    Sleep::fake();
    $application = createApplicationForRollingUpdateDrainTest();
    $application->settings->update(['rolling_update_drain_seconds' => $drainSeconds]);

    $logs = runRollingUpdateDrainStep($application, $newVersionIsHealthy);

    Sleep::assertNeverSlept();
    expect($logs)->toBe([]);
})->with([
    'drain turned off' => [0, true],
    'new container not healthy' => [5, false],
]);

it('does not wait when no old container runs', function () {
    Sleep::fake();
    $application = createApplicationForRollingUpdateDrainTest();

    $logs = runRollingUpdateDrainStep($application, newVersionIsHealthy: true, oldProxyLabels: null);

    Sleep::assertNeverSlept();
    expect($logs)->toBe([]);
});

it('stops the old containers without waiting when the proxy labels changed', function () {
    Sleep::fake();
    $application = createApplicationForRollingUpdateDrainTest();

    $logs = runRollingUpdateDrainStep(
        $application,
        newVersionIsHealthy: true,
        oldProxyLabels: ['traefik.http.routers.http-0-app.middlewares' => 'gzip-sse'],
        newProxyLabels: ['traefik.http.routers.http-0-app.middlewares' => 'gzip-sse,retry-app'],
    );

    Sleep::assertNeverSlept();
    expect($logs)->toBe(['The proxy labels changed, so the old containers stop without waiting.']);
});

it('saves the rolling update drain time on the advanced page', function () {
    $user = User::factory()->create();
    $application = createApplicationForRollingUpdateDrainTest();
    $application->team()->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $application->team()]);

    Livewire::test(Advanced::class, ['application' => $application])
        ->assertSet('rollingUpdateDrainSeconds', DEFAULT_ROLLING_UPDATE_DRAIN_SECONDS)
        ->set('rollingUpdateDrainSeconds', 20)
        ->call('saveRollingUpdateDrainSeconds')
        ->assertHasNoErrors()
        ->set('rollingUpdateDrainSeconds', 301)
        ->call('saveRollingUpdateDrainSeconds')
        ->assertHasErrors(['rollingUpdateDrainSeconds' => ['max']]);

    expect($application->settings->fresh()->rolling_update_drain_seconds)->toBe(20);
});
