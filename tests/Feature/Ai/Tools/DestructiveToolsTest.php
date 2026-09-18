<?php

use App\Actions\Server\RunCommand;
use App\Ai\Tools\DeleteResource;
use App\Ai\Tools\DeleteServer;
use App\Ai\Tools\RunServerCommand;
use App\Ai\Tools\UpsertEnvironmentVariable;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
});

test('all destructive tools are structurally approvable', function () {
    expect(new DeleteServer)->toBeInstanceOf(Approvable::class)
        ->and(new RunServerCommand)->toBeInstanceOf(Approvable::class)
        ->and(new DeleteResource)->toBeInstanceOf(Approvable::class)
        ->and(new UpsertEnvironmentVariable)->toBeInstanceOf(Approvable::class);
});

test('delete-server requires approval naming the target for an admin', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $approval = (new DeleteServer)->shouldRequestApproval(
        new Request(['server_uuid' => $this->server->uuid])
    );

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toContain($this->server->uuid);
});

test('delete-server denies a member before any approval card', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);

    expect(fn () => (new DeleteServer)->shouldRequestApproval(
        new Request(['server_uuid' => $this->server->uuid])
    ))->toThrow(AuthorizationException::class);
});

test('run-command requires approval naming the command for an admin', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $approval = (new RunServerCommand)->shouldRequestApproval(
        new Request(['server_uuid' => $this->server->uuid, 'command' => 'rm -rf /tmp/x'])
    );

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toContain('rm -rf /tmp/x');
});

test('a foreign uuid is not resolvable and does not gate', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $approval = (new DeleteServer)->shouldRequestApproval(
        new Request(['server_uuid' => 'foreign-uuid'])
    );

    expect($approval)->toBeNull();
});

test('approved delete-server soft-deletes the server for an admin', function () {
    Queue::fake();
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $result = (string) (new DeleteServer)->handle(
        new Request(['server_uuid' => $this->server->uuid])
    );

    expect($result)->toContain('deleted')
        ->and(Server::find($this->server->id))->toBeNull();
});

test('a member cannot execute delete-server handle', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);

    expect(fn () => (new DeleteServer)->handle(
        new Request(['server_uuid' => $this->server->uuid])
    ))->toThrow(AuthorizationException::class);

    expect(Server::find($this->server->id))->not->toBeNull();
});

test('approved run-command executes the action for an admin', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    RunCommand::shouldRun()
        ->once()
        ->andReturn('ok');

    $result = (string) (new RunServerCommand)->handle(
        new Request(['server_uuid' => $this->server->uuid, 'command' => 'uptime'])
    );

    expect($result)->toContain('uptime');
});
