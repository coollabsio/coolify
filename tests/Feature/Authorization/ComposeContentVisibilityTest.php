<?php

use App\Livewire\Project\Application\General as ApplicationGeneral;
use App\Livewire\Project\Service\EditCompose;
use App\Livewire\Project\Service\StackForm;
use App\Models\Application;
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

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->team->members()->attach($this->member, ['role' => 'member']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environmentId = $project->environments()->firstOrFail()->id;

    $this->composeRaw = "services:\n  app:\n    image: nginx:alpine\n    environment:\n      TOKEN: stored-raw-compose-value\n";
    $this->composeGenerated = "services:\n  app:\n    image: nginx:alpine\n    environment:\n      TOKEN: stored-generated-compose-value\n";

    $this->service = Service::factory()->create([
        'docker_compose_raw' => $this->composeRaw,
        'docker_compose' => $this->composeGenerated,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environmentId,
    ]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $this->composeRaw,
        'docker_compose' => $this->composeGenerated,
        'environment_id' => $environmentId,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ])->fresh();
});

function composeComponents(): array
{
    return [
        'application' => fn () => Livewire::test(ApplicationGeneral::class, ['application' => test()->application]),
        'service' => fn () => Livewire::test(StackForm::class, ['service' => test()->service]),
        'service editor' => fn () => Livewire::test(EditCompose::class, ['serviceId' => test()->service->id]),
    ];
}

it('keeps compose content out of member state', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    foreach (composeComponents() as $mountComponent) {
        $component = $mountComponent()
            ->assertSet('dockerComposeRaw', null)
            ->assertSet('dockerCompose', null);

        expect(json_encode($component->snapshot).$component->html())
            ->not->toContain('stored-raw-compose-value')
            ->not->toContain('stored-generated-compose-value');
    }
});

it('keeps compose content available to an owner', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    foreach (composeComponents() as $mountComponent) {
        $component = $mountComponent()->assertSet('dockerComposeRaw', $this->composeRaw);

        expect($component->get('dockerCompose'))->toContain('TOKEN:');
    }
});
