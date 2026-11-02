<?php

use App\Models\GiteaApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->giteaApp = GiteaApp::create([
        'name' => 'Team Gitea',
        'organization' => 'acme',
        'api_url' => 'https://gitea.com/api/v1',
        'html_url' => 'https://gitea.com',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
});

test('team sources include gitea apps and count them as deletion blockers', function () {
    expect($this->team->sources()->pluck('uuid'))->toContain($this->giteaApp->uuid)
        ->and($this->team->deletionBlockers())->toMatchArray(['sources' => 1]);
});

test('sources page lists gitea apps with a link to their settings', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->get(route('source.all'))
        ->assertOk()
        ->assertSee('New Gitea App')
        ->assertSee('Team Gitea')
        ->assertSee('Gitea · acme')
        ->assertSee(route('source.gitea.show', ['gitea_app_uuid' => $this->giteaApp->uuid]), false);
});

test('deleting a team transfers system-wide gitea apps and removes team gitea apps', function () {
    $systemWide = GiteaApp::create([
        'name' => 'Shared Gitea',
        'api_url' => 'https://gitea.com/api/v1',
        'html_url' => 'https://gitea.com',
        'team_id' => $this->team->id,
        'is_system_wide' => true,
        'is_public' => false,
    ]);

    $this->team->delete();

    expect($systemWide->refresh()->team_id)->toBe(0)
        ->and(GiteaApp::find($this->giteaApp->id))->toBeNull();
});
