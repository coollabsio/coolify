<?php

use App\Livewire\Project\New\GitlabPrivateRepository;
use App\Livewire\Source\Gitlab\Change;
use App\Models\Application;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => null,
        'public_ipv4' => null,
        'public_ipv6' => null,
    ]);

    $this->gitlabApp = GitlabApp::create([
        'name' => 'Self-hosted GitLab',
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_token' => 'secret-webhook-token',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => time() + 3600,
        'redirect_uri' => 'https://coolify.example.com/webhooks/source/gitlab/redirect',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
});

describe('GitLab App authorization', function () {
    test('team member cannot update a gitlab app via instantSave', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->set('isSystemWide', true)
            ->call('instantSave')
            ->assertDispatched('error');

        expect($this->gitlabApp->refresh()->is_system_wide)->toBeFalse();
    });

    test('team owner can update a gitlab app via instantSave', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->set('isSystemWide', true)
            ->call('instantSave')
            ->assertDispatched('success');

        expect($this->gitlabApp->refresh()->is_system_wide)->toBeTrue();
    });

    test('team member cannot create an application from a private gitlab repository', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        $applicationsBefore = Application::count();

        // Avoid setting selected_project_id — its updated* hook loads branches and is unrelated to this auth check.
        Livewire::test(GitlabPrivateRepository::class, ['type' => 'private-gitlab-app'])
            ->set('selected_repository_path', 'group/repo')
            ->set('selected_branch_name', 'main')
            ->set('selected_gitlab_app_id', $this->gitlabApp->id)
            ->set('gitlab_app_id', $this->gitlabApp->id)
            ->call('submit')
            ->assertDispatched('error');

        expect(Application::count())->toBe($applicationsBefore);
    });
});
