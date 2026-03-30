<?php

use App\Livewire\Project\Shared\ResourceOperations;
use App\Models\Application;
use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create(['server_id' => $this->server->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('cloning application generates new uuid for persistent volumes', function () {
    $volume = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $originalUuid = $volume->uuid;

    $newApp = clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $clonedVolume = $newApp->persistentStorages()->first();

    expect($clonedVolume)->not->toBeNull();
    expect($clonedVolume->uuid)->not->toBe($originalUuid);
    expect($clonedVolume->mount_path)->toBe('/data');
});

test('cloning application with multiple persistent volumes generates unique uuids', function () {
    $volume1 = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $volume2 = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-config',
        'mount_path' => '/config',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $newApp = clone_application($this->application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $clonedVolumes = $newApp->persistentStorages()->get();

    expect($clonedVolumes)->toHaveCount(2);

    $clonedUuids = $clonedVolumes->pluck('uuid')->toArray();
    $originalUuids = [$volume1->uuid, $volume2->uuid];

    // All cloned UUIDs should be unique and different from originals
    expect($clonedUuids)->each->not->toBeIn($originalUuids);
    expect(array_unique($clonedUuids))->toHaveCount(2);
});
