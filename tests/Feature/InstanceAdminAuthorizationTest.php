<?php

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('instance admin can view and update a project from another team', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);

    $instanceAdmin = User::factory()->create();
    $rootTeam->members()->attach($instanceAdmin->id, ['role' => 'admin']);

    $targetTeam = Team::factory()->create();
    $project = Project::factory()->create([
        'team_id' => $targetTeam->id,
    ]);

    expect($instanceAdmin->teams()->whereKey($targetTeam->id)->exists())
        ->toBeFalse();

    expect(Gate::forUser($instanceAdmin)->allows('view', $project))
        ->toBeTrue()
        ->and(Gate::forUser($instanceAdmin)->allows('update', $project))
        ->toBeTrue();
});

test('regular user cannot access a project from another team', function () {
    $userTeam = Team::factory()->create();
    $user = User::factory()->create();
    $userTeam->members()->attach($user->id, ['role' => 'member']);

    $targetTeam = Team::factory()->create();
    $project = Project::factory()->create([
        'team_id' => $targetTeam->id,
    ]);

    expect(Gate::forUser($user)->allows('view', $project))
        ->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $project))
        ->toBeFalse();
});

test('instance admin can administer a team they do not belong to', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);

    $instanceAdmin = User::factory()->create();
    $rootTeam->members()->attach($instanceAdmin->id, ['role' => 'admin']);

    $targetTeam = Team::factory()->create();

    expect($instanceAdmin->teams()->whereKey($targetTeam->id)->exists())
        ->toBeFalse();

    expect(Gate::forUser($instanceAdmin)->allows('view', $targetTeam))
        ->toBeTrue()
        ->and(Gate::forUser($instanceAdmin)->allows('update', $targetTeam))
        ->toBeTrue();
});

test('instance admin gate bypass does not override read-only api token permissions', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);

    $instanceAdmin = User::factory()->create();
    $rootTeam->members()->attach($instanceAdmin->id, ['role' => 'admin']);

    $this->actingAs($instanceAdmin);
    session(['currentTeam' => $rootTeam]);

    $token = $instanceAdmin->createToken('read-only-token', ['read']);

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->postJson('/api/v1/projects', [
            'name' => 'Must Not Be Created',
        ]);

    expect($response->status())->not->toBe(201)
        ->and(Project::query()->where('name', 'Must Not Be Created')->exists())
        ->toBeFalse();
});
