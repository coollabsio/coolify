<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;

/**
 * A team owner with one server, one project, and one environment.
 *
 * @return array{team: Team, user: User, server: Server, destination: StandaloneDocker, project: Project, environment: Environment}
 */
function projectConfigFixture(string $role = 'owner'): array
{
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.driver' => 'file',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id, 'name' => 'prod-1']);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $team->id, 'name' => 'acme']);
    $environment = Environment::firstOrCreate(['project_id' => $project->id, 'name' => 'production']);

    return compact('team', 'user', 'server', 'destination', 'project', 'environment');
}
