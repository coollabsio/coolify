<?php

use App\Enums\NetworkAttachmentStatus;
use App\Models\Application;
use App\Models\DockerNetwork;
use App\Models\Environment;
use App\Models\NetworkAttachment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\Docker\NetworkAttachmentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'attachment-'.fake()->uuid(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $this->network = DockerNetwork::create([
        'server_id' => $this->server->id,
        'display_name' => 'Backend Network',
        'docker_network_name' => 'backend-net',
        'driver' => 'bridge',
        'scope' => 'local',
        'managed_by_coolify' => true,
        'external' => false,
        'is_active' => true,
    ]);
});

it('creates desired attachment with correct business invariants', function () {
    $attachment = app(NetworkAttachmentManager::class)->createDesiredAttachment($this->application, $this->network, [
        'aliases' => 'api, backend',
        'is_primary' => true,
        'is_required' => true,
    ]);

    expect($attachment->aliases)->toBe(['api', 'backend'])
        ->and($attachment->is_managed)->toBeTrue()
        ->and($attachment->is_runtime_discovered)->toBeFalse()
        ->and($attachment->status)->toBe(NetworkAttachmentStatus::Desired)
        ->and($attachment->server_id)->toBe($this->server->id);
});

it('blocks network from another server', function () {
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    $otherNetwork = DockerNetwork::create([
        'server_id' => $otherServer->id,
        'display_name' => 'Other Network',
        'docker_network_name' => 'other-net',
    ]);

    app(NetworkAttachmentManager::class)->createDesiredAttachment($this->application, $otherNetwork, []);
})->throws(ValidationException::class);

it('blocks duplicate attachment for same resource and network', function () {
    app(NetworkAttachmentManager::class)->createDesiredAttachment($this->application, $this->network, []);

    app(NetworkAttachmentManager::class)->createDesiredAttachment($this->application, $this->network, []);
})->throws(ValidationException::class);

it('normalizes aliases and rejects unsafe aliases', function () {
    $manager = app(NetworkAttachmentManager::class);

    expect($manager->normalizeAliases('api, backend, api'))->toBe(['api', 'backend']);

    $manager->normalizeAliases('api, bad alias');
})->throws(ValidationException::class);

it('keeps only one primary attachment per resource', function () {
    $secondNetwork = DockerNetwork::create([
        'server_id' => $this->server->id,
        'display_name' => 'Public Network',
        'docker_network_name' => 'public-net',
    ]);

    $first = app(NetworkAttachmentManager::class)->createDesiredAttachment($this->application, $this->network, [
        'is_primary' => true,
    ]);
    $second = app(NetworkAttachmentManager::class)->createDesiredAttachment($this->application, $secondNetwork, [
        'is_primary' => true,
    ]);

    expect($first->refresh()->is_primary)->toBeFalse()
        ->and($second->refresh()->is_primary)->toBeTrue();
});

it('updates and deletes attachments', function () {
    $attachment = app(NetworkAttachmentManager::class)->createDesiredAttachment($this->application, $this->network, []);

    $updated = app(NetworkAttachmentManager::class)->updateAttachment($attachment, [
        'aliases' => 'api',
        'is_primary' => false,
        'is_required' => true,
    ]);

    expect($updated->aliases)->toBe(['api'])
        ->and($updated->is_required)->toBeTrue();

    app(NetworkAttachmentManager::class)->deleteAttachmentConfiguration($updated);

    expect(NetworkAttachment::query()->whereKey($attachment->id)->exists())->toBeFalse();
});

it('does not delete runtime discovered attachments', function () {
    $attachment = NetworkAttachment::create([
        'server_id' => $this->server->id,
        'docker_network_id' => $this->network->id,
        'attachable_type' => Application::class,
        'attachable_id' => $this->application->id,
        'resource_type' => 'application',
        'resource_id' => $this->application->id,
        'is_runtime_discovered' => true,
    ]);

    app(NetworkAttachmentManager::class)->deleteAttachmentConfiguration($attachment);
})->throws(ValidationException::class);

it('promotes runtime discovered attachment into managed configuration', function () {
    $attachment = NetworkAttachment::create([
        'server_id' => $this->server->id,
        'docker_network_id' => $this->network->id,
        'attachable_type' => Application::class,
        'attachable_id' => $this->application->id,
        'resource_type' => 'application',
        'resource_id' => $this->application->id,
        'aliases' => ['runtime'],
        'is_runtime_discovered' => true,
        'status' => NetworkAttachmentStatus::Attached,
    ]);

    $promoted = app(NetworkAttachmentManager::class)->createDesiredAttachment($this->application, $this->network, [
        'aliases' => 'api, backend',
        'is_primary' => true,
    ]);

    expect($promoted->id)->toBe($attachment->id)
        ->and($promoted->aliases)->toBe(['api', 'backend'])
        ->and($promoted->is_managed)->toBeTrue()
        ->and($promoted->is_runtime_discovered)->toBeFalse()
        ->and($promoted->is_primary)->toBeTrue()
        ->and($promoted->status)->toBe(NetworkAttachmentStatus::Attached);
});
