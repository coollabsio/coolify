<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function registerTeamSwitchTestRoutesBeforeCatchAll(array $actions): void
{
    $routes = new RouteCollection;
    foreach ($actions as $uri => $action) {
        $routes->add(Route::middleware(['web', 'auth'])->get($uri, $action));
    }
    foreach (app('router')->getRoutes() as $route) {
        $routes->add($route);
    }
    app('router')->setRoutes($routes);
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->firstTeam = $this->user->teams()->first();
    $this->secondTeam = Team::factory()->create();
    $this->secondTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->thirdTeam = Team::factory()->create();
    $this->thirdTeam->members()->attach($this->user->id, ['role' => 'owner']);
    Team::query()->update(['show_boarding' => false]);
    $this->user->forceFill(['current_team_id' => $this->firstTeam->id])->saveQuietly();

    registerTeamSwitchTestRoutesBeforeCatchAll([
        '_test/team-switch-during-request' => function () {
            // The team switcher saves the session while this request still runs.
            $switchRequestSession = clone session()->driver();
            $switchRequestSession->start();
            $switchRequestSession->put('currentTeam', $this->secondTeam);
            $switchRequestSession->save();
            $this->user->forceFill(['current_team_id' => $this->secondTeam->id])->saveQuietly();

            return 'ok';
        },
        '_test/switch-to-second-team' => function () {
            $otherRequestSession = clone session()->driver();
            $otherRequestSession->start();
            $otherRequestSession->put('currentTeam', $this->thirdTeam);
            $otherRequestSession->save();

            refreshSession($this->secondTeam);

            return 'ok';
        },
        '_test/current-team' => fn () => 'team:'.currentTeam()->id,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->firstTeam]);
});

test('a request that was in flight during a team switch does not restore the previous team', function () {
    $this->get('/_test/team-switch-during-request')->assertOk();

    $this->get('/_test/current-team')->assertOk()->assertSee('team:'.$this->secondTeam->id);
    expect($this->user->fresh()->current_team_id)->toBe($this->secondTeam->id);
});

test('a request that switches the team keeps the team it selected over a concurrent change', function () {
    $this->get('/_test/switch-to-second-team')->assertOk();

    $this->get('/_test/current-team')->assertOk()->assertSee('team:'.$this->secondTeam->id);
    expect($this->user->fresh()->current_team_id)->toBe($this->secondTeam->id);
});
