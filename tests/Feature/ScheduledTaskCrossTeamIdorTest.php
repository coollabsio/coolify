<?php

use App\Livewire\Project\Shared\ScheduledTask\Executions;
use App\Livewire\Project\Shared\ScheduledTask\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->attacker = User::factory()->create();
    $this->attackerTeam = Team::factory()->create();
    $this->attacker->teams()->attach($this->attackerTeam, ['role' => 'owner']);

    $this->victim = User::factory()->create();
    $this->victimTeam = Team::factory()->create();
    $this->victim->teams()->attach($this->victimTeam, ['role' => 'owner']);

    // Attacker team gets a real server/project/env/app so Show can mount + authorize
    $this->server = Server::factory()->create(['team_id' => $this->attackerTeam->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->attackerTeam->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->attacker);
    session(['currentTeam' => $this->attackerTeam]);
});

describe('ScheduledTask locked properties', function () {
    test('Show component task property has Locked attribute', function () {
        $property = new ReflectionProperty(Show::class, 'task');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('Show component resource property has Locked attribute', function () {
        $property = new ReflectionProperty(Show::class, 'resource');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('Executions component task property has Locked attribute', function () {
        $property = new ReflectionProperty(Executions::class, 'task');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('Executions component selected execution property has Locked attribute', function () {
        $property = new ReflectionProperty(Executions::class, 'selectedExecution');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });
});

describe('ScheduledTask cross-team access', function () {
    test('Executions rejects mounting another team task id', function () {
        $victimTask = ScheduledTask::factory()->create([
            'team_id' => $this->victimTeam->id,
            'command' => 'echo top-secret-victim-command',
        ]);

        Livewire::test(Executions::class, ['taskId' => $victimTask->id])
            ->assertStatus(404);
    });

    test('Show policy denies updating another team task', function () {
        $victimTask = ScheduledTask::factory()->create([
            'team_id' => $this->victimTeam->id,
            'name' => 'victim-task',
            'command' => 'echo original-victim-command',
        ]);

        expect(
            Gate::forUser($this->attacker)->allows('update', $victimTask)
        )->toBeFalse();
    });

    test('Show policy denies deleting another team task', function () {
        $victimTask = ScheduledTask::factory()->create([
            'team_id' => $this->victimTeam->id,
            'name' => 'victim-task',
            'command' => 'echo original-victim-command',
        ]);

        expect(
            Gate::forUser($this->attacker)->allows('delete', $victimTask)
        )->toBeFalse();
    });

    test('Show policy allows updating own team task', function () {
        $ownTask = ScheduledTask::factory()->create([
            'team_id' => $this->attackerTeam->id,
            'application_id' => $this->application->id,
            'name' => 'own-task',
            'command' => 'echo original-command',
        ]);

        expect(
            Gate::forUser($this->attacker)->allows('update', $ownTask)
        )->toBeTrue();
    });
});
