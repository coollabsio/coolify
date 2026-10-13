<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function makeComposeApplicationWithNetworks(string $networks): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '5',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n    networks:\n      - shared\n      - other\n      - plain\nnetworks:\n{$networks}",
    ]);
}

/**
 * Builds the lines of a Compose `.env` file of a deployment: the runtime `.env` next to the compose
 * file (used by `docker compose up`) or the build-time `.env` (used by `docker compose build`).
 *
 * @return Collection<int, string>
 */
function composeNetworkDeploymentEnv(Application $application, int $pullRequestId = 0, string $method = 'generate_runtime_environment_variables'): Collection
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnNull();

    $properties = [
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'build_pack' => 'dockercompose',
        'mainServer' => $application->destination->server,
        'pull_request_id' => $pullRequestId,
        'commit' => 'HEAD',
        'container_name' => 'network-variable-app',
        'saved_outputs' => collect(),
    ];
    if ($pullRequestId !== 0) {
        $properties['preview'] = ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => $pullRequestId,
            'pull_request_html_url' => 'https://example.com/pr/'.$pullRequestId,
            'fqdn' => 'https://preview.example.com',
        ]);
    }
    foreach ($properties as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    return collect($reflection->getMethod($method)->invoke($job));
}

test('variables in network names become application variables with their default', function () {
    $application = makeComposeApplicationWithNetworks(<<<'YAML'
  shared:
    external: true
    name: ${SHARED_NETWORK:-traefik_public}
  other:
    external: true
    name: ${OTHER_NETWORK}
  plain:
    name: fixed-network
YAML);

    $application->parse();

    $variables = $application->environment_variables()->get()->keyBy('key');

    expect($variables->get('SHARED_NETWORK')?->value)->toBe('traefik_public')
        ->and($variables->has('OTHER_NETWORK'))->toBeTrue()
        ->and((string) $variables->get('OTHER_NETWORK')->value)->toBe('')
        ->and($variables->get('SHARED_NETWORK')->is_runtime)->toBeTrue()
        ->and($variables->get('SHARED_NETWORK')->is_buildtime)->toBeTrue()
        ->and($variables->keys()->filter(fn ($key) => str_contains($key, 'fixed') || str_contains($key, 'plain')))->toBeEmpty()
        // Compose resolves the name from .env at deployment, so the compose file keeps the variable.
        ->and($application->fresh()->docker_compose)->toContain('${SHARED_NETWORK:-traefik_public}')
        ->and($application->fresh()->docker_compose)->toContain('${OTHER_NETWORK}');
});

test('network name variables also exist as preview variables of the application', function () {
    $application = makeComposeApplicationWithNetworks(<<<'YAML'
  shared:
    external: true
    name: ${SHARED_NETWORK:-traefik_public}
  other:
    name: other-network
  plain:
    name: plain-network
YAML);

    $application->parse();

    $previewVariables = $application->environment_variables_preview()->where('key', 'SHARED_NETWORK')->get();

    expect($previewVariables)->toHaveCount(1)
        ->and($previewVariables->first()->value)->toBe('traefik_public');
});

test('a changed application network variable value is kept when the compose file is parsed again', function () {
    $application = makeComposeApplicationWithNetworks(<<<'YAML'
  shared:
    external: true
    name: ${SHARED_NETWORK-traefik-public}
  other:
    name: other-network
  plain:
    name: plain-network
YAML);

    $application->parse();
    expect($application->environment_variables()->where('key', 'SHARED_NETWORK')->value('value'))->toBe('traefik-public');

    $application->environment_variables()->where('key', 'SHARED_NETWORK')->firstOrFail()->update(['value' => 'my_shared']);
    $application->fresh()->parse();

    expect($application->environment_variables()->where('key', 'SHARED_NETWORK')->count())->toBe(1)
        ->and($application->environment_variables()->where('key', 'SHARED_NETWORK')->value('value'))->toBe('my_shared')
        ->and($application->environment_variables_preview()->where('key', 'SHARED_NETWORK')->count())->toBe(1);
});

test('plain network names do not create application variables', function () {
    $application = makeComposeApplicationWithNetworks(<<<'YAML'
  shared:
    external: true
    name: traefik_public
  other:
    name: other-network
  plain:
YAML);

    $application->parse();

    expect($application->environment_variables()->count())->toBe(0)
        ->and($application->environment_variables_preview()->count())->toBe(0);
});

test('network name variables with an invalid default are not created', function () {
    $application = makeComposeApplicationWithNetworks(<<<'YAML'
  shared:
    external: true
    name: ${SHARED_NETWORK:-bad name;id}
  other:
    name: other-network
  plain:
    name: plain-network
YAML);

    $application->parse();

    expect($application->environment_variables()->where('key', 'SHARED_NETWORK')->exists())->toBeFalse();
});

test('the deployment .env of a compose application contains the network name variable', function () {
    $application = makeComposeApplicationWithNetworks(<<<'YAML'
  shared:
    external: true
    name: ${SHARED_NETWORK:-traefik_public}
  other:
    external: true
    name: ${OTHER_NETWORK}
  plain:
    name: plain-network
YAML);

    $application->parse();
    $application->environment_variables()->where('key', 'OTHER_NETWORK')->firstOrFail()->update(['value' => 'edge_net']);

    $env = composeNetworkDeploymentEnv($application);

    expect($env)->toContain('SHARED_NETWORK=traefik_public')
        ->and($env)->toContain('OTHER_NETWORK=edge_net');

    $buildEnv = composeNetworkDeploymentEnv($application, method: 'generate_buildtime_environment_variables')->implode("\n");

    expect($buildEnv)->toMatch('/^SHARED_NETWORK=.*traefik_public/m')
        ->and($buildEnv)->toMatch('/^OTHER_NETWORK=.*edge_net/m');
});

test('the preview deployment .env of a compose application contains the network name variable', function () {
    $application = makeComposeApplicationWithNetworks(<<<'YAML'
  shared:
    external: true
    name: ${SHARED_NETWORK:-traefik_public}
  other:
    name: other-network
  plain:
    name: plain-network
YAML);

    $application->parse();

    expect(composeNetworkDeploymentEnv($application, 7))->toContain('SHARED_NETWORK=traefik_public');
});
