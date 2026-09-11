<?php

use App\Ai\Tools\CreateDatabase;
use App\Ai\Tools\CreateEnvironment;
use App\Ai\Tools\CreateProject;
use App\Ai\Tools\CreateService;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
});

function dbArgs(): array
{
    return [
        'type' => 'postgresql',
        'project_uuid' => test()->project->uuid,
        'server_uuid' => test()->server->uuid,
        'environment_name' => 'production',
        'name' => 'ai-pg',
    ];
}

test('all create tools are approvable', function () {
    expect(new CreateDatabase)->toBeInstanceOf(Approvable::class)
        ->and(new CreateService)->toBeInstanceOf(Approvable::class)
        ->and(new CreateProject)->toBeInstanceOf(Approvable::class)
        ->and(new CreateEnvironment)->toBeInstanceOf(Approvable::class);
});

test('create_database requires approval naming the target for an admin', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $approval = (new CreateDatabase)->shouldRequestApproval(new Request(dbArgs()));

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toContain('postgresql')
        ->and($approval->reason)->toContain($this->server->uuid);
});

test('create_database denies a member before any approval card', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);

    expect(fn () => (new CreateDatabase)->shouldRequestApproval(new Request(dbArgs())))
        ->toThrow(AuthorizationException::class);
});

test('approved create_database creates a postgres and audits for an admin', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $result = (string) (new CreateDatabase)->handle(new Request(dbArgs()));

    expect($result)->toContain('Created postgresql database');
    $db = StandalonePostgresql::where('name', 'ai-pg')->first();
    expect($db)->not->toBeNull()
        ->and($result)->toContain($db->uuid);
});

test('a member cannot execute create_database handle', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);

    expect(fn () => (new CreateDatabase)->handle(new Request(dbArgs())))
        ->toThrow(AuthorizationException::class);
    expect(StandalonePostgresql::count())->toBe(0);
});

test('cross-team placement resolves to not found and creates nothing', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);

    $result = (string) (new CreateDatabase)->handle(new Request([
        'type' => 'postgresql',
        'project_uuid' => $otherProject->uuid,
        'server_uuid' => $this->server->uuid,
        'environment_name' => 'production',
    ]));

    expect($result)->toContain('was not found in this team')
        ->and(StandalonePostgresql::count())->toBe(0);
});

test('approved create_project creates a project for an admin', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $result = (string) (new CreateProject)->handle(new Request(['name' => 'ai-proj']));

    expect($result)->toContain('Created project')
        ->and(Project::where('name', 'ai-proj')->where('team_id', $this->team->id)->exists())->toBeTrue();
});
