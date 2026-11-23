<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All as EnvironmentVariables;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->team->members()->attach($this->member, ['role' => 'member']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n    environment:\n      DB_PASSWORD: \${DB_PASSWORD:-hunter2}\n",
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ])->fresh();

    $this->environmentVariable = EnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'stored-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);
});

function mountComposeDefaultComponents(): array
{
    $all = Livewire::test(EnvironmentVariables::class, ['resource' => test()->application])
        ->call('loadEnvironmentVariables');

    $show = Livewire::test(Show::class, [
        'env' => test()->environmentVariable,
        'type' => 'application',
        'composeInfo' => dockerComposeEnvironmentVariableInfo(test()->application->docker_compose_raw, 'DB_PASSWORD'),
    ]);

    return [$all, $show];
}

it('hides compose defaults from a member', function (bool $sessionOnOtherTeam) {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    if ($sessionOnOtherTeam) {
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($this->member, ['role' => 'owner']);
        session(['currentTeam' => $otherTeam]);
    }

    [$all, $show] = mountComposeDefaultComponents();

    expect($all->instance()->environmentVariablePageRows->first()['composeInfo'])->toBe([
        'services' => ['app'],
        'default' => null,
        'operator' => ':-',
        'required' => false,
    ])
        ->and($show->get('composeInfo')['default'])->toBeNull()
        ->and($show->get('composeInfo')['services'])->toBe(['app']);

    foreach ([$all, $show] as $component) {
        expect(json_encode($component->snapshot).$component->html())->not->toContain('hunter2');
    }
})->with([
    'session on resource team' => false,
    'session switched to another team' => true,
]);

it('shows compose defaults to an owner', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    [$all, $show] = mountComposeDefaultComponents();

    expect($all->instance()->environmentVariablePageRows->first()['composeInfo']['default'])->toBe('hunter2')
        ->and($all->html())->toContain('hunter2');
    $show->assertSet('composeInfo.default', 'hunter2')
        ->assertSee('Compose default');
});

it('does not let a member apply the compose default', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class, [
        'env' => $this->environmentVariable,
        'type' => 'application',
        'composeInfo' => dockerComposeEnvironmentVariableInfo($this->application->docker_compose_raw, 'DB_PASSWORD'),
    ])->call('useComposeDefault')->assertForbidden();

    expect($this->environmentVariable->fresh()->value)->toBe('stored-value');
});
