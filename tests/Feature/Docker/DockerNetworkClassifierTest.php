<?php

use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkSourceType;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Services\Docker\DockerNetworkClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createClassifierServer(): Server
{
    return Server::factory()->create(['team_id' => Team::factory()->create()->id]);
}

it('classifies docker native networks as system', function (string $networkName) {
    $classification = (new DockerNetworkClassifier)->classify(createClassifierServer(), $networkName);

    expect($classification['source_type'])->toBe(DockerNetworkSourceType::System->value)
        ->and($classification['network_role'])->toBe(DockerNetworkRole::System->value)
        ->and($classification['is_system'])->toBeTrue()
        ->and($classification['managed_by_coolify'])->toBeFalse();
})->with(['bridge', 'host', 'none']);

it('classifies coolify default networks', function () {
    $classifier = new DockerNetworkClassifier;
    $server = createClassifierServer();

    expect($classifier->classify($server, 'coolify')['source_type'])->toBe(DockerNetworkSourceType::StandaloneDockerDestination->value)
        ->and($classifier->classify($server, 'coolify')['is_system'])->toBeTrue()
        ->and($classifier->classify($server, 'coolify')['managed_by_coolify'])->toBeTrue()
        ->and($classifier->classify($server, 'coolify-overlay')['source_type'])->toBe(DockerNetworkSourceType::SwarmDockerDestination->value)
        ->and($classifier->classify($server, 'coolify-overlay')['network_role'])->toBe(DockerNetworkRole::DefaultDestination->value)
        ->and($classifier->classify($server, 'coolify-overlay')['managed_by_coolify'])->toBeTrue();
});

it('classifies service and application stack networks on the server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::factory()->create([
        'uuid' => 'service-stack-network',
        'server_id' => $server->id,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination::class,
    ]);
    $application = Application::factory()->create([
        'uuid' => 'application-stack-network',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination::class,
    ]);
    $classifier = new DockerNetworkClassifier;

    expect($classifier->classify($server, $service->uuid)['source_type'])->toBe(DockerNetworkSourceType::ServiceStackDefault->value)
        ->and($classifier->classify($server, $service->uuid)['source_id'])->toBe($service->id)
        ->and($classifier->classify($server, $application->uuid)['source_type'])->toBe(DockerNetworkSourceType::ComposeStackDefault->value)
        ->and($classifier->classify($server, $application->uuid)['source_id'])->toBe($application->id)
        ->and($classifier->classify($server, $service->uuid)['managed_by_coolify'])->toBeTrue()
        ->and($classifier->classify($server, $application->uuid)['managed_by_coolify'])->toBeTrue();
});

it('classifies preview stack networks', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'uuid' => 'previewapp',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination::class,
    ]);
    $preview = ApplicationPreview::create([
        'uuid' => 'preview-record',
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://example.com/pr/42',
    ]);

    $classification = (new DockerNetworkClassifier)->classify($server, 'previewapp-42');

    expect($classification['source_type'])->toBe(DockerNetworkSourceType::PreviewDeployment->value)
        ->and($classification['source_id'])->toBe($preview->id)
        ->and($classification['network_role'])->toBe(DockerNetworkRole::PreviewStack->value)
        ->and($classification['managed_by_coolify'])->toBeTrue();
});

it('classifies unknown networks as imported external shared networks', function () {
    $classification = (new DockerNetworkClassifier)->classify(createClassifierServer(), 'external-network');

    expect($classification['source_type'])->toBe(DockerNetworkSourceType::ImportedExternal->value)
        ->and($classification['network_role'])->toBe(DockerNetworkRole::SharedExternal->value)
        ->and($classification['managed_by_coolify'])->toBeFalse()
        ->and($classification['external'])->toBeTrue()
        ->and($classification['is_system'])->toBeFalse();
});
