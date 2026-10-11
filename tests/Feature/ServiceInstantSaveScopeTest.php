<?php

use App\Livewire\Project\Service\EditCompose;
use App\Livewire\Project\Service\StackForm;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::create(['id' => 0]);

    $team = Team::factory()->create();
    $this->team = $team;
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $this->service = Service::factory()->create([
        'name' => 'original-service',
        'description' => 'Original description',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n",
        'docker_compose' => "services:\n  app:\n    image: nginx:alpine\n",
        'connect_to_docker_network' => false,
        'is_container_label_escape_enabled' => false,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $project->environments()->firstOrFail()->id,
    ]);
});

test('network instant save does not persist pending compose or service details', function () {
    Livewire::test(StackForm::class, ['service' => $this->service])
        ->set('dockerComposeRaw', 'services: [invalid')
        ->set('dockerCompose', 'invalid generated compose')
        ->set('name', 'pending-name')
        ->set('description', 'Pending description')
        ->set('connectToDockerNetwork', true)
        ->call('instantSave')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $stored = $this->service->fresh();
    expect($stored->connect_to_docker_network)->toBeTruthy()
        ->and($stored->docker_compose_raw)->toBe("services:\n  app:\n    image: nginx:alpine\n")
        ->and($stored->docker_compose)->toBe("services:\n  app:\n    image: nginx:alpine\n")
        ->and($stored->name)->toBe('original-service')
        ->and($stored->description)->toBe('Original description');
});

test('label escape instant save does not persist pending compose', function () {
    Livewire::test(EditCompose::class, ['serviceId' => $this->service->id])
        ->set('dockerComposeRaw', 'services: [invalid')
        ->set('dockerCompose', 'invalid generated compose')
        ->set('isContainerLabelEscapeEnabled', true)
        ->call('instantSave')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $stored = $this->service->fresh();
    expect($stored->is_container_label_escape_enabled)->toBeTruthy()
        ->and($stored->docker_compose_raw)->toBe("services:\n  app:\n    image: nginx:alpine\n")
        ->and($stored->docker_compose)->toBe("services:\n  app:\n    image: nginx:alpine\n");
});

test('members cannot instant save service switches', function (string $component, string $property) {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);

    $parameters = $component === StackForm::class
        ? ['service' => $this->service]
        : ['serviceId' => $this->service->id];

    Livewire::test($component, $parameters)
        ->set($property, true)
        ->call('instantSave')
        ->assertNotDispatched('success');

    $stored = $this->service->fresh();
    expect($stored->connect_to_docker_network)->toBeFalsy()
        ->and($stored->is_container_label_escape_enabled)->toBeFalsy();
})->with([
    'network attachment' => [StackForm::class, 'connectToDockerNetwork'],
    'label escaping' => [EditCompose::class, 'isContainerLabelEscapeEnabled'],
]);
