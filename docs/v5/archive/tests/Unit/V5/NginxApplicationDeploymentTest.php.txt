<?php

use App\Actions\V5\Application\DeployNginxApplication;
use App\Models\V5\Application;
use App\Models\V5\Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V5TestSchema;
use Tests\TestCase;

uses(TestCase::class);

it('builds an nginx container spec for the coold mesh runtime', function () {
    $application = new Application([
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'mesh_namespace' => 'default',
        'status' => 'creating',
    ]);

    $action = new DeployNginxApplication(Mockery::mock(FluxClient::class));
    $method = new ReflectionMethod($action, 'containerSpec');
    $method->setAccessible(true);
    $spec = $method->invoke($action, $application);

    expect($spec)
        ->toMatchArray([
            'name' => 'coolify-v5-nginx-test',
            'image' => 'docker.io/library/nginx:alpine',
            'networks' => ['coolify-default-mesh'],
            'network_aliases' => ['coolify-v5-nginx-test'],
            'dns_search' => ['default.coolify.internal'],
            'restart_policy' => 'unless-stopped',
        ])
        ->not->toHaveKey('command')
        ->not->toHaveKey('privileged');
});

it('stops and removes the created container when starting it fails', function () {
    Event::fake();
    $application = createNginxDeploymentApplication();

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('pullImage')->once()->andReturn('Image pulled.');
    $fluxClient->shouldReceive('createContainer')->once()->andReturn('container-123');
    $fluxClient
        ->shouldReceive('startContainer')
        ->once()
        ->with(Mockery::type('string'), 'container-123')
        ->andThrow(new RuntimeException('podman start exited with status 125'));
    $fluxClient
        ->shouldReceive('stopContainer')
        ->once()
        ->with(Mockery::type('string'), 'container-123')
        ->andReturn('Container stopped.');
    $fluxClient
        ->shouldReceive('removeContainer')
        ->once()
        ->with(Mockery::type('string'), 'container-123', true)
        ->andReturn('Container removed.');

    $result = (new DeployNginxApplication($fluxClient))->handle($application);

    expect($result->status)->toBe('failed')
        ->and($result->status_message)->toBe('podman start exited with status 125')
        ->and($result->runtime_container_id)->toBeNull();
});

it('does not clean up any container when creating the container fails', function () {
    Event::fake();
    $application = createNginxDeploymentApplication();

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('pullImage')->once()->andReturn('Image pulled.');
    $fluxClient
        ->shouldReceive('createContainer')
        ->once()
        ->andThrow(new RuntimeException('Flux did not return a container id.'));
    $fluxClient->shouldNotReceive('startContainer');
    $fluxClient->shouldNotReceive('stopContainer');
    $fluxClient->shouldNotReceive('removeContainer');

    $result = (new DeployNginxApplication($fluxClient))->handle($application);

    expect($result->status)->toBe('failed')
        ->and($result->status_message)->toBe('Flux did not return a container id.')
        ->and($result->runtime_container_id)->toBeNull();
});

it('keeps the original deploy failure and logs a warning when cleanup itself fails', function () {
    Event::fake();
    Log::spy();
    $application = createNginxDeploymentApplication();

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('pullImage')->once()->andReturn('Image pulled.');
    $fluxClient->shouldReceive('createContainer')->once()->andReturn('container-123');
    $fluxClient
        ->shouldReceive('startContainer')
        ->once()
        ->andThrow(new RuntimeException('original deploy failure'));
    $fluxClient
        ->shouldReceive('stopContainer')
        ->once()
        ->andThrow(new RuntimeException('stop failed'));
    $fluxClient
        ->shouldReceive('removeContainer')
        ->once()
        ->andThrow(new RuntimeException('remove failed'));

    $result = (new DeployNginxApplication($fluxClient))->handle($application);

    expect($result->status)->toBe('failed')
        ->and($result->status_message)->toBe('original deploy failure')
        ->and($result->runtime_container_id)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->with('Could not stop the container created by a failed v5 deploy.', Mockery::type('array'))
        ->once();
    Log::shouldHaveReceived('warning')
        ->with('Could not remove the container created by a failed v5 deploy.', Mockery::type('array'))
        ->once();
});

it('cleans up the container when it does not stay running after start', function () {
    Event::fake();
    $application = createNginxDeploymentApplication();

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('pullImage')->once()->andReturn('Image pulled.');
    $fluxClient->shouldReceive('createContainer')->once()->andReturn('container-123');
    $fluxClient->shouldReceive('startContainer')->once()->andReturn('Container started.');
    $fluxClient
        ->shouldReceive('inspectContainer')
        ->once()
        ->andReturn(['State' => ['Running' => false]]);
    $fluxClient
        ->shouldReceive('stopContainer')
        ->once()
        ->with(Mockery::type('string'), 'container-123')
        ->andReturn('Container stopped.');
    $fluxClient
        ->shouldReceive('removeContainer')
        ->once()
        ->with(Mockery::type('string'), 'container-123', true)
        ->andReturn('Container removed.');

    $result = (new DeployNginxApplication($fluxClient))->handle($application);

    expect($result->status)->toBe('failed')
        ->and($result->status_message)->toBe('Container did not stay running.')
        ->and($result->runtime_container_id)->toBeNull();
});

it('persists the container id only for a successful deploy', function () {
    Event::fake();
    $application = createNginxDeploymentApplication();

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('pullImage')->once()->andReturn('Image pulled.');
    $fluxClient->shouldReceive('createContainer')->once()->andReturn('container-123');
    $fluxClient->shouldReceive('startContainer')->once()->andReturn('Container started.');
    $fluxClient
        ->shouldReceive('inspectContainer')
        ->once()
        ->andReturn(['State' => ['Running' => true]]);
    $fluxClient->shouldNotReceive('stopContainer');
    $fluxClient->shouldNotReceive('removeContainer');

    $result = (new DeployNginxApplication($fluxClient))->handle($application);

    expect($result->status)->toBe('running')
        ->and($result->runtime_container_id)->toBe('container-123');
});

it('persists the runtime container id immediately after create, before start', function () {
    Event::fake();
    $application = createNginxDeploymentApplication();

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('pullImage')->once()->andReturn('Image pulled.');
    $fluxClient->shouldReceive('createContainer')->once()->andReturn('container-123');

    $persistedBeforeStart = null;
    $fluxClient
        ->shouldReceive('startContainer')
        ->once()
        ->andReturnUsing(function () use ($application, &$persistedBeforeStart) {
            // Read the row straight from the database to prove the id is durable
            // before start runs, not just held in the in-memory model.
            $persistedBeforeStart = Application::query()->find($application->getKey())?->runtime_container_id;

            throw new RuntimeException('podman start exited with status 125');
        });
    $fluxClient->shouldReceive('stopContainer')->once()->andReturn('Container stopped.');
    $fluxClient->shouldReceive('removeContainer')->once()->andReturn('Container removed.');

    $result = (new DeployNginxApplication($fluxClient))->handle($application);

    expect($persistedBeforeStart)->toBe('container-123')
        ->and($result->status)->toBe('failed')
        ->and($result->runtime_container_id)->toBeNull();
});

function createNginxDeploymentApplication(): Application
{
    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_servers');

    V5TestSchema::createServersTable();
    V5TestSchema::createApplicationsTable();

    $server = Server::query()->forceCreate([
        'uuid' => 'nginx-deployment-server-uuid',
        'team_id' => 1,
        'created_by_user_id' => 1,
        'name' => 'node-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);

    return Application::query()->forceCreate([
        'uuid' => 'nginx-deployment-application-uuid',
        'team_id' => 1,
        'project_id' => 1,
        'environment_id' => 1,
        'created_by_user_id' => 1,
        'server_id' => $server->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'creating',
        'mesh_namespace' => 'default',
    ]);
}
