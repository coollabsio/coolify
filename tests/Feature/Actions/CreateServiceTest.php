<?php

use App\Actions\Service\CreateService;
use App\Actions\Shared\ResolveResourcePlacement;
use App\Data\ResourcePlacement;
use App\Exceptions\ServiceTemplateNotFoundException;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function servicePlacement(): ResourcePlacement
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);

    return ResolveResourcePlacement::run($team->id, $project->uuid, 'production', null, $server->uuid, null);
}

it('creates a service from raw docker compose in the resolved placement', function () {
    Queue::fake();
    $placement = servicePlacement();
    $compose = base64_encode("services:\n  app:\n    image: nginx:alpine\n");

    $service = CreateService::run($placement, null, $compose, ['name' => 'my-svc'], false);

    expect($service)->toBeInstanceOf(Service::class)
        ->and($service->environment_id)->toBe($placement->environment->id)
        ->and($service->destination_id)->toBe($placement->destination->id)
        ->and($service->name)->toBe('my-svc');
});

it('throws ServiceTemplateNotFoundException for an unknown slug', function () {
    Queue::fake();
    $placement = servicePlacement();

    CreateService::run($placement, 'definitely-not-a-template', null);
})->throws(ServiceTemplateNotFoundException::class);

it('rejects providing both a template slug and docker compose', function () {
    Queue::fake();
    $placement = servicePlacement();
    $compose = base64_encode("services:\n  app:\n    image: nginx:alpine\n");

    CreateService::run($placement, 'some-slug', $compose);
})->throws(InvalidArgumentException::class);
