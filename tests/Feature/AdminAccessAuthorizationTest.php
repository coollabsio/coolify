<?php

use App\Livewire\Admin\Index as AdminIndex;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('unauthenticated user cannot access admin route', function () {
    $response = $this->get('/admin');

    $response->assertRedirect('/login');
});

test('authenticated non-root user gets 403 on admin page', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    Livewire::test(AdminIndex::class)
        ->assertForbidden();
});

test('root user can access admin page in cloud mode', function () {
    config()->set('constants.coolify.self_hosted', false);

    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $rootUser = User::factory()->create(['id' => 0]);
    $rootTeam->members()->attach($rootUser->id, ['role' => 'admin']);

    $this->actingAs($rootUser);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(AdminIndex::class)
        ->assertOk();
});

test('root user gets 403 on admin page in self-hosted non-dev mode', function () {
    config()->set('constants.coolify.self_hosted', true);
    config()->set('app.env', 'production');

    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $rootUser = User::factory()->create(['id' => 0]);
    $rootTeam->members()->attach($rootUser->id, ['role' => 'admin']);

    $this->actingAs($rootUser);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(AdminIndex::class)
        ->assertForbidden();
});

test('submitSearch requires admin authorization', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    Livewire::test(AdminIndex::class)
        ->assertForbidden();
});

test('switchUser requires root user id 0', function () {
    config()->set('constants.coolify.self_hosted', false);

    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $rootUser = User::factory()->create(['id' => 0]);
    $rootTeam->members()->attach($rootUser->id, ['role' => 'admin']);

    $targetUser = User::factory()->create();
    $targetTeam = Team::factory()->create();
    $targetTeam->members()->attach($targetUser->id, ['role' => 'admin']);

    $this->actingAs($rootUser);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(AdminIndex::class)
        ->assertOk()
        ->call('switchUser', $targetUser->id)
        ->assertRedirect();
});

test('switchUser rejects non-root user', function () {
    config()->set('constants.coolify.self_hosted', false);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);

    // Must set impersonating session to bypass mount() check
    $this->actingAs($user);
    session([
        'currentTeam' => ['id' => $team->id],
        'impersonating' => true,
    ]);

    Livewire::test(AdminIndex::class)
        ->call('switchUser', 999)
        ->assertForbidden();
});

test('admin route has auth middleware applied', function () {
    $route = collect(app('router')->getRoutes()->getRoutesByName())
        ->get('admin.index');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain('auth');
});
