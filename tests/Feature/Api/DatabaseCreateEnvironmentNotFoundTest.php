<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 404 when the environment does not exist on database create', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $token = $user->createToken('t', ['*'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/databases/postgresql', [
        'project_uuid' => $project->uuid, 'server_uuid' => $server->uuid, 'environment_name' => 'missing',
    ])->assertStatus(404);
});
