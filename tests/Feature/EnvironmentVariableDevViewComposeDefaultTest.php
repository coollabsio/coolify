<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
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
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n    environment:\n      - 'BACKUP_KEEP=\${BACKUP_KEEP:-3}'\n      - 'LOG_LEVEL=\${LOG_LEVEL:-info}'\n      - 'APP_NAME=\${APP_NAME}'\n",
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ])->fresh();
    EnvironmentVariable::query()->delete();

    foreach (['BACKUP_KEEP' => '', 'LOG_LEVEL' => 'debug', 'APP_NAME' => ''] as $key => $value) {
        EnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'resourceable_type' => Application::class,
            'resourceable_id' => $this->application->id,
        ]);
    }

    $this->devView = fn () => Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('switch');
});

it('shows the compose default above an empty variable and keeps it empty when saved', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $component = ($this->devView)();

    expect($component->get('variables'))->toBe("# Compose default: 3\nBACKUP_KEEP=\nLOG_LEVEL=debug\nAPP_NAME=");

    $component->call('submit')->assertNotDispatched('error');

    expect($this->application->environment_variables()->where('key', 'BACKUP_KEEP')->value('value'))->toBe('');
});

it('does not show compose defaults to a member', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(($this->devView)()->get('variables'))->not->toContain('Compose default');
});
