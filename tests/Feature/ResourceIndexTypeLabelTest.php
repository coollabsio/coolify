<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::create([
            'name' => 'default',
            'network' => 'coolify',
            'server_id' => $this->server->id,
        ]);

    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'coolLabs',
    ]);
    $this->environment = $this->project->environments()->firstOrFail();
});

/**
 * @js() embeds JSON with unicode-escaped quotes (\u0022). Match that encoding.
 */
function assertJsPayloadContains(string $html, string $needle): void
{
    $escaped = str_replace('"', '\u0022', $needle);
    expect($html)->toContain($escaped);
}

function assertJsPayloadDoesNotContain(string $html, string $needle): void
{
    $escaped = str_replace('"', '\u0022', $needle);
    expect($html)->not->toContain($escaped);
}

test('resource index type labels use category names not engine names for databases', function () {
    $mysql = StandaloneMysql::create([
        'name' => 'mysql-database-uprlcxnoukmrxge65gdrgwqm',
        'mysql_root_password' => 'password',
        'mysql_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'exited:unhealthy',
    ]);

    StandalonePostgresql::create([
        'name' => 'postgresql-database-test',
        'postgres_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'exited:unhealthy',
    ]);

    Application::create([
        'name' => 'docker-image-test',
        'fqdn' => 'https://example.com',
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'dockerimage',
        'ports_exposes' => '80',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Service::create([
        'name' => 'actualbudget-test',
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n",
        'docker_compose' => "services:\n  app:\n    image: nginx\n",
        'service_type' => 'actualbudget',
    ]);

    $response = $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]));

    $response->assertSuccessful();
    $html = $response->getContent();

    // Category labels match Application / Service — not engine-specific names.
    assertJsPayloadContains($html, '"type":"database"');
    assertJsPayloadContains($html, '"typeLabel":"Database"');
    assertJsPayloadContains($html, '"type":"application"');
    assertJsPayloadContains($html, '"typeLabel":"Application"');
    assertJsPayloadContains($html, '"type":"service"');
    assertJsPayloadContains($html, '"typeLabel":"Service"');
    assertJsPayloadDoesNotContain($html, '"typeLabel":"MySQL"');
    assertJsPayloadDoesNotContain($html, '"typeLabel":"PostgreSQL"');

    expect($html)->toContain($mysql->name);
});
