<?php

use App\Livewire\Source\Gitea\Change;
use App\Livewire\Source\Gitea\Create;
use App\Models\GiteaApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->giteaApp = GiteaApp::create([
        'name' => 'Team Gitea',
        'api_url' => 'https://gitea.com/api/v1',
        'html_url' => 'https://gitea.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
});

function joinRootTeam(User $user): void
{
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $user->load('teams');
}

describe('team admin outside the root team', function () {
    test('cannot make a source system wide with instantSave', function () {
        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->set('isSystemWide', true)
            ->call('instantSave');

        expect($this->giteaApp->refresh()->is_system_wide)->toBeFalse();
    });

    test('cannot make a source system wide with submit', function () {
        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->set('isSystemWide', true)
            ->call('submit');

        expect($this->giteaApp->refresh()->is_system_wide)->toBeFalse();
    });

    test('cannot create a system wide source', function () {
        Livewire::test(Create::class)
            ->set('name', 'sneaky-gitea')
            ->set('is_system_wide', true)
            ->call('createGiteaApp');

        expect(GiteaApp::where('name', 'sneaky-gitea')->exists())->toBeFalse();
    });

    test('can still save a team source that stays team scoped', function () {
        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->set('name', 'Renamed Gitea')
            ->call('submit')
            ->assertDispatched('success');

        expect($this->giteaApp->refresh()->name)->toBe('Renamed Gitea');
    });
});

describe('root team member', function () {
    beforeEach(fn () => joinRootTeam($this->user));

    test('can make a source system wide with instantSave', function () {
        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->set('isSystemWide', true)
            ->call('instantSave')
            ->assertDispatched('success');

        expect($this->giteaApp->refresh()->is_system_wide)->toBeTrue();
    });

    test('can create a system wide source', function () {
        Livewire::test(Create::class)
            ->set('name', 'shared-gitea')
            ->set('is_system_wide', true)
            ->call('createGiteaApp');

        expect(GiteaApp::where('name', 'shared-gitea')->value('is_system_wide'))->toBeTrue();
    });
});
