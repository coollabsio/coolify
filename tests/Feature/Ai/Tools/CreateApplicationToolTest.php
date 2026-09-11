<?php

use App\Ai\Tools\CreateApplication;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
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

function imageAppArgs(): array
{
    return [
        'type' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80', 'name' => 'ai-nginx',
        'project_uuid' => test()->project->uuid, 'server_uuid' => test()->server->uuid, 'environment_name' => 'production',
    ];
}

test('create_application is approvable and names the target', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $approval = (new CreateApplication)->shouldRequestApproval(new Request(imageAppArgs()));

    expect(new CreateApplication)->toBeInstanceOf(Approvable::class)
        ->and($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toContain('dockerimage')->toContain('ai-nginx')->toContain($this->server->uuid);
});

test('a member is denied before the approval card', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);

    expect(fn () => (new CreateApplication)->shouldRequestApproval(new Request(imageAppArgs())))->toThrow(AuthorizationException::class);
});

test('approved create_application creates the app for an admin', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $result = (string) (new CreateApplication)->handle(new Request(imageAppArgs()));

    $app = Application::where('name', 'ai-nginx')->first();
    expect($app)->not->toBeNull()->and($result)->toContain('Created dockerimage application')->toContain($app->uuid);
});

test('a member cannot execute handle', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);

    expect(fn () => (new CreateApplication)->handle(new Request(imageAppArgs())))->toThrow(AuthorizationException::class);
    expect(Application::count())->toBe(0);
});

test('handle validates per-type fields and reports placement errors', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $missing = (string) (new CreateApplication)->handle(new Request(['type' => 'public', 'project_uuid' => $this->project->uuid, 'server_uuid' => $this->server->uuid, 'environment_name' => 'production']));
    expect($missing)->toContain('git_repository');

    $foreignProject = Project::factory()->create(['team_id' => Team::factory()->create()->id]);
    $foreign = (string) (new CreateApplication)->handle(new Request(array_merge(imageAppArgs(), ['project_uuid' => $foreignProject->uuid])));
    expect($foreign)->toContain('was not found in this team')->and(Application::count())->toBe(0);
});
