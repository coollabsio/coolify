<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\V4\UiMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('app.maintenance.driver', 'file');
    Config::set('cache.default', 'array');
    Config::set('session.driver', 'array');

    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->saveQuietly();
    }
});

/**
 * @return array{0: User, 1: Team}
 */
function setupNextUiUser(): array
{
    $team = Team::factory()->create(['show_boarding' => false]);
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $user->teams()->attach($team, ['role' => 'owner']);

    return [$user, $team];
}

it('defaults to the classic livewire dashboard', function () {
    [$user, $team] = setupNextUiUser();

    Project::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Classic Project',
        'team_id' => $team->id,
    ]);

    $this->withoutVite();

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Classic Project')
        ->assertSee('Try Next UI')
        ->assertDontSee('id="v4-app"', false);
});

it('serves the inertia next dashboard when ui mode is next', function () {
    [$user, $team] = setupNextUiUser();

    Project::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Next Project',
        'description' => 'Shared data source',
        'team_id' => $team->id,
    ]);

    $this->withoutVite();

    $this->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            UiMode::SESSION_KEY => UiMode::Next->value,
        ])
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('id="v4-app"', false)
        ->assertSee('resources/js/v4/app.tsx', false)
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('projects', 1)
            ->where('projects.0.name', 'Next Project')
            ->where('permissions.createProject', true)
            ->has('links.uiMode')
            ->missing('links.profileAppearance')
            ->where('uiMode', UiMode::Next->value));
});

it('switches ui mode via post and redirects to the dashboard', function () {
    [$user, $team] = setupNextUiUser();

    $this->withoutVite();

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('ui.mode'), ['mode' => UiMode::Next->value])
        ->assertRedirect(route('dashboard'));

    expect(session(UiMode::SESSION_KEY))->toBe(UiMode::Next->value);

    $this->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            UiMode::SESSION_KEY => UiMode::Next->value,
        ])
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('id="v4-app"', false);

    $this->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            UiMode::SESSION_KEY => UiMode::Next->value,
        ])
        ->post(route('ui.mode'), ['mode' => UiMode::Classic->value])
        ->assertRedirect(route('dashboard'));

    expect(session(UiMode::SESSION_KEY))->toBe(UiMode::Classic->value);
});

it('forces a full window location when switching ui mode from an inertia request', function () {
    [$user, $team] = setupNextUiUser();

    $this->withoutVite();

    $this->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            UiMode::SESSION_KEY => UiMode::Next->value,
        ])
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->post(route('ui.mode'), ['mode' => UiMode::Classic->value])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('dashboard'));

    expect(session(UiMode::SESSION_KEY))->toBe(UiMode::Classic->value);
});

it('rejects invalid ui modes', function () {
    [$user, $team] = setupNextUiUser();

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->from(route('profile.appearance'))
        ->post(route('ui.mode'), ['mode' => 'invalid'])
        ->assertSessionHasErrors('mode');
});
