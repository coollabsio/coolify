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
    test('empty gitlab app state is inset from the section edges', function () {
        $view = file_get_contents(resource_path('views/livewire/project/new/gitlab-private-repository.blade.php'));

        expect($view)->toContain("<div class=\"application-settings-section-body\">\n                <x-empty title=\"No GitLab Apps\"");
    });

    test('unrelated users cannot inspect system-wide source secrets in the component payload', function () {
        $otherTeam = Team::factory()->create();
        $systemWideSource = GitlabApp::create([
            'name' => 'Shared GitLab',
            'api_url' => 'https://gitlab.example.com/api/v4',
            'html_url' => 'https://gitlab.example.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'client_id' => 'shared-client-id',
            'client_secret' => 'shared-client-secret',
            'webhook_token' => 'shared-webhook-token',
            'access_token' => 'shared-access-token',
            'refresh_token' => 'shared-refresh-token',
            'expires_at' => time() + 3600,
            'team_id' => $otherTeam->id,
            'is_system_wide' => true,
            'is_public' => false,
        ]);

        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        $component = Livewire::withQueryParams(['gitlab_app_uuid' => $systemWideSource->uuid])
            ->test(Change::class)
            ->assertSet('clientSecretInput', null)
            ->assertSet('webhookToken', null);

        expect($component->html())
            ->not->toContain('shared-client-secret')
            ->not->toContain('shared-webhook-token');
    });

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

    test('instantSave rejects unsafe GitLab URLs', function (string $url) {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->set('htmlUrl', $url)
            ->set('apiUrl', $url.'/api/v4')
            ->set('isSystemWide', true)
            ->call('instantSave')
            ->assertDispatched('success');

        $this->gitlabApp->refresh();

        expect($this->gitlabApp->html_url)->toBe('https://gitlab.example.com')
            ->and($this->gitlabApp->api_url)->toBe('https://gitlab.example.com/api/v4')
            ->and($this->gitlabApp->is_system_wide)->toBeTrue();
    })->with([
        'private address' => 'http://10.0.0.1',
        'loopback address' => 'http://127.0.0.1',
        'metadata service address' => 'http://169.254.169.254',
    ]);

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

    test('team owner can delete a gitlab app without type error', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        $gitlabAppId = $this->gitlabApp->id;

        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->call('delete')
            ->assertRedirect(route('source.all'));

        expect(GitlabApp::find($gitlabAppId))->toBeNull();
    });

    test('delete policy is safe when team_id becomes null after model is removed', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        // Reproduce the post-delete Livewire re-render path: @can('delete') runs while
        // the in-memory model may have a null team_id (TypeError in isAdminOfTeam).
        $orphaned = new GitlabApp([
            'name' => 'Orphaned',
            'api_url' => 'https://gitlab.example.com/api/v4',
            'html_url' => 'https://gitlab.example.com',
            'is_system_wide' => false,
            'team_id' => null,
        ]);

        expect(fn () => $this->owner->can('delete', $orphaned))->not->toThrow(TypeError::class);
        expect($this->owner->can('delete', $orphaned))->toBeFalse();
        expect(fn () => $this->owner->can('update', $orphaned))->not->toThrow(TypeError::class);
        expect($this->owner->can('update', $orphaned))->toBeFalse();
    });

});
