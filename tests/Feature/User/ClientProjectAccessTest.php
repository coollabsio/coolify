<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->client = User::factory()->create(['name' => 'Client User']);
    $this->client->is_client = true;
    $this->client->save();
    $this->team->members()->attach($this->client->id, ['role' => 'member']);

    $this->assignedProject = Project::create([
        'name' => 'Assigned Project',
        'team_id' => $this->team->id,
        'uuid' => (string) Str::uuid(),
    ]);

    $this->otherProject = Project::create([
        'name' => 'Other Project',
        'team_id' => $this->team->id,
        'uuid' => (string) Str::uuid(),
    ]);

    $this->client->assignedProjects()->attach($this->assignedProject->id);
});

describe('global scope on Project', function () {
    test('client only sees assigned projects via Project::query()', function () {
        $this->actingAs($this->client);
        session(['currentTeam' => $this->team]);

        $projects = Project::query()->get();

        expect($projects->pluck('id')->all())->toBe([$this->assignedProject->id]);
    });

    test('owner sees all team projects', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        $projects = Project::whereTeamId($this->team->id)->get();

        expect($projects->pluck('id')->all())->toContain($this->assignedProject->id, $this->otherProject->id);
    });

    test('client cannot fetch unassigned project by uuid', function () {
        $this->actingAs($this->client);
        session(['currentTeam' => $this->team]);

        $found = Project::where('uuid', $this->otherProject->uuid)->first();

        expect($found)->toBeNull();
    });

    test('client can fetch assigned project by uuid', function () {
        $this->actingAs($this->client);
        session(['currentTeam' => $this->team]);

        $found = Project::where('uuid', $this->assignedProject->uuid)->first();

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($this->assignedProject->id);
    });

    test('unauthenticated context (queue jobs, console) is unaffected by the scope', function () {
        // No actingAs — auth()->user() is null, scope must be a no-op.
        $projects = Project::whereTeamId($this->team->id)->get();

        expect($projects)->toHaveCount(2);
    });

    test('client without any assigned projects sees zero projects', function () {
        $this->client->assignedProjects()->detach();
        $this->actingAs($this->client);
        session(['currentTeam' => $this->team]);

        $projects = Project::query()->get();

        expect($projects)->toHaveCount(0);
    });
});

describe('global scope on Environment', function () {
    test('client only sees environments of assigned projects', function () {
        // Project::booted() auto-creates a 'production' environment per project.
        $assignedEnv = $this->assignedProject->environments()->first();
        $otherEnv = $this->otherProject->environments()->first();

        expect($assignedEnv)->not->toBeNull();
        expect($otherEnv)->not->toBeNull();

        $this->actingAs($this->client);
        session(['currentTeam' => $this->team]);

        $envs = Environment::query()->get();

        expect($envs->pluck('id')->all())->toBe([$assignedEnv->id]);
    });

    test('client cannot fetch environment of unassigned project by uuid', function () {
        $otherEnv = $this->otherProject->environments()->first();

        $this->actingAs($this->client);
        session(['currentTeam' => $this->team]);

        $found = Environment::where('uuid', $otherEnv->uuid)->first();

        expect($found)->toBeNull();
    });
});

describe('User model behavior', function () {
    test('isClient returns true for client users and false otherwise', function () {
        expect($this->client->isClient())->toBeTrue();
        expect($this->owner->isClient())->toBeFalse();
    });

    test('client user does not get an automatic personal team on create', function () {
        $newClient = new User;
        $newClient->name = 'Solo Client';
        $newClient->email = 'solo-client@example.com';
        $newClient->password = bcrypt('password');
        $newClient->is_client = true;
        $newClient->save();

        expect($newClient->teams)->toHaveCount(0);
    });

    test('non-client user still gets a personal team on create', function () {
        $newUser = User::factory()->create();

        expect($newUser->teams)->toHaveCount(1);
        expect($newUser->teams->first()->personal_team)->toBeTrue();
    });

    test('mass assignment cannot escalate is_client flag', function () {
        $user = User::factory()->create();

        // Try to flip is_client through fill — guarded should block it.
        $user->fill(['is_client' => true, 'name' => 'tampered']);
        $user->save();

        expect($user->fresh()->is_client)->toBeFalse();
    });

    test('mass assignment cannot escalate force_password_reset flag', function () {
        $user = User::factory()->create();

        $user->fill(['force_password_reset' => true]);
        $user->save();

        expect($user->fresh()->force_password_reset)->toBeFalse();
    });
});

describe('UserPolicy', function () {
    test('owner can create users', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        expect($this->owner->can('create', User::class))->toBeTrue();
    });

    test('client cannot create users', function () {
        $this->actingAs($this->client);
        session(['currentTeam' => $this->team]);

        expect($this->client->can('create', User::class))->toBeFalse();
    });

    test('owner cannot delete themselves', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        expect($this->owner->can('delete', $this->owner))->toBeFalse();
    });

    test('owner cannot delete another owner', function () {
        $secondOwner = User::factory()->create();
        $this->team->members()->attach($secondOwner->id, ['role' => 'owner']);

        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        expect($this->owner->can('delete', $secondOwner))->toBeFalse();
    });

    test('owner can delete a client user', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        expect($this->owner->can('delete', $this->client))->toBeTrue();
    });
});

describe('hard delete client user', function () {
    test('deleting a client removes the user but keeps the assigned project', function () {
        $clientId = $this->client->id;
        $projectId = $this->assignedProject->id;

        $this->client->assignedProjects()->detach();
        $this->client->delete();

        expect(User::find($clientId))->toBeNull();
        // Use withoutGlobalScopes() because the previously authenticated user
        // (now deleted) might leave stale auth state in the test container.
        expect(Project::withoutGlobalScopes()->find($projectId))->not->toBeNull();
    });

    test('deleting a client cascades the project_user pivot row', function () {
        $clientId = $this->client->id;

        $this->client->delete();

        $pivotCount = DB::table('project_user')->where('user_id', $clientId)->count();
        expect($pivotCount)->toBe(0);
    });
});
