<?php

use App\Http\Kernel;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Http\Middleware\V5\EnsureCurrentTeam;
use App\Http\Middleware\V5\HandleInertiaRequests;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('registers the v5 dashboard route', function () {
    expect(Route::has('v5.dashboard'))->toBeTrue()
        ->and(Route::has('v5.home'))->toBeFalse()
        ->and(Route::has('v5.selection.update'))->toBeTrue()
        ->and(Route::has('v5.clusters.index'))->toBeTrue()
        ->and(Route::has('v5.clusters.show'))->toBeTrue()
        ->and(Route::has('v5.clusters.store'))->toBeTrue()
        ->and(Route::has('v5.clusters.destroy'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.store'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.update'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.check'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.coold-logs'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.corrosion-tables'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.bootstrap'))->toBeTrue()
        ->and(Route::has('v5.clusters.servers.destroy'))->toBeTrue()
        ->and(Route::has('v5.applications.nginx'))->toBeTrue()
        ->and(Route::has('v5.applications.refresh'))->toBeTrue()
        ->and(Route::has('v5.applications.position'))->toBeTrue()
        ->and(Route::has('v5.applications.ingress'))->toBeTrue()
        ->and(Route::has('v5.caddy-ingresses.position'))->toBeTrue()
        ->and(Route::has('v5.applications.destroy'))->toBeTrue()
        ->and(Route::has('v5.resource-connections.store'))->toBeTrue()
        ->and(Route::has('v5.resource-connections.update'))->toBeTrue()
        ->and(Route::has('v5.resource-connections.destroy'))->toBeTrue()
        ->and(Route::has('v5.realtime-test'))->toBeTrue()
        ->and(Route::has('v5.realtime-test.broadcast'))->toBeTrue()
        ->and(Route::has('v5.coolify.version'))->toBeFalse()
        ->and(Route::has('v5.coolify.bootstrap'))->toBeFalse();
});

it('uses separated v5 middleware groups', function () {
    $kernel = app(Kernel::class);
    $reflection = new ReflectionClass($kernel);
    $property = $reflection->getProperty('middlewareGroups');
    $property->setAccessible(true);
    $groups = $property->getValue($kernel);

    expect($groups)->toHaveKey('v5.web')
        ->and($groups)->toHaveKey('v5.authenticated')
        ->and($groups['v5.web'])->toContain(HandleInertiaRequests::class)
        ->and($groups['v5.web'])->not->toContain(CheckForcePasswordReset::class)
        ->and($groups['v5.web'])->not->toContain(DecideWhatToDoWithUser::class)
        ->and($groups['v5.authenticated'])->toContain('auth')
        ->and($groups['v5.authenticated'])->toContain('verified')
        ->and($groups['v5.authenticated'])->toContain(EnsureCurrentTeam::class);
});

it('redirects guests to the shared login', function () {
    $this->get('/v5')
        ->assertRedirect('/login');
});

it('selects a shared team when the session has no current team', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Auto Selected Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'admin']);

    $this
        ->actingAs($user)
        ->get('/v5')
        ->assertSuccessful()
        ->assertSessionHas('currentTeam')
        ->assertDontSee('Auto Selected Team')
        ->assertDontSee('admin');
});
