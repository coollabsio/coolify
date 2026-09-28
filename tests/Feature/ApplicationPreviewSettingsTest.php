<?php

use App\Livewire\Project\Application\Previews;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        [
            'id' => 0,
            'is_dns_validation_enabled' => false,
        ]
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $key = PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'ip' => '203.0.113.10',
    ]);

    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Preview App',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => null,
        'redirect' => 'both',
        'build_pack' => 'nixpacks',
    ]);

    $this->application->settings()->update([
        'is_container_label_readonly_enabled' => true,
    ]);
});

it('shows preview settings and persists changes independently of preview inputs', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('PR deployment access')
        ->assertSet('isPreviewDeploymentsEnabled', false)
        ->set('manualPullRequestId', -1)
        ->set('isPreviewDeploymentsEnabled', true)
        ->set('isPrDeploymentsPublicEnabled', true)
        ->call('savePreviewSettings')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->application->fresh()->settings)
        ->is_preview_deployments_enabled->toBeTrue()
        ->is_pr_deployments_public_enabled->toBeTrue();

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertSet('isPreviewDeploymentsEnabled', true)
        ->assertSet('isPrDeploymentsPublicEnabled', true)
        ->set('isPreviewDeploymentsEnabled', false)
        ->call('savePreviewSettings');

    expect($this->application->fresh()->settings->is_preview_deployments_enabled)->toBeFalse();
});

it('renders preview deployment enablement as a section action', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('Enable preview deployments')
        ->assertDontSeeHtml('id="isPreviewDeploymentsEnabled"')
        ->call('togglePreviewDeployments')
        ->assertSee('Disable preview deployments')
        ->assertSet('isPreviewDeploymentsEnabled', true);

    expect($this->application->fresh()->settings->is_preview_deployments_enabled)->toBeTrue();
});

it('renders GitHub pull requests in a modal opened from the preview settings', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));
    $sidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($view)
        ->toContain('<x-modal-input title="Pull requests"')
        ->toContain(':isLarge="true"')
        ->toContain('wire:click="load_prs"')
        ->not->toContain('id="preview-pull-requests-section"')
        ->and(strpos($view, '<x-modal-input title="Pull requests"'))
        ->toBeLessThan(strpos($view, '<livewire:project.application.preview.form'));

    expect($sidebar)->not->toContain("['id' => 'preview-pull-requests-section', 'label' => 'Pull requests']");
});

it('stores inferred Git provider metadata when the UI configures a preview', function () {
    $githubApp = GithubApp::create([
        'name' => 'preview-settings-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'team_id' => $this->team->id,
    ]);
    $this->application->source()->associate($githubApp);
    $this->application->git_repository = 'example/repository';
    $this->application->save();

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ])
        ->call('add', 66, 'https://github.com/example/repository/pull/66')
        ->assertDispatched('success');

    $preview = $this->application->previews()->where('pull_request_id', 66)->firstOrFail();

    expect($preview->git_type)->toBe('github')
        ->and($preview->pull_request_html_url)->toBe('https://github.com/example/repository/pull/66');
});

it('does not show git preview settings for non-git applications', function (string $buildPack, ?string $dockerfile) {
    $this->application->update(['build_pack' => $buildPack, 'dockerfile' => $dockerfile]);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertDontSee('PR deployment access');
})->with([['dockerimage', null], ['dockerfile', 'FROM nginx']]);

it('denies preview setting changes without application update permission', function (string $role, bool $otherTeam) {
    $user = User::factory()->create();
    $team = $otherTeam ? Team::factory()->create() : $this->team;
    $team->members()->attach($user->id, ['role' => $role]);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(Previews::class, ['application' => $this->application])
        ->set('isPreviewDeploymentsEnabled', true)
        ->set('isPrDeploymentsPublicEnabled', true)
        ->call('savePreviewSettings')
        ->assertForbidden();

    expect($this->application->fresh()->settings)
        ->is_preview_deployments_enabled->toBeFalse()
        ->is_pr_deployments_public_enabled->toBeFalse();
})->with([['member', false], ['owner', true]]);

it('removes preview settings from Advanced including its persistence path', function () {
    expect(file_get_contents(resource_path('views/livewire/project/application/advanced.blade.php')))
        ->not->toContain('isPreviewDeploymentsEnabled', 'isPrDeploymentsPublicEnabled');
    expect(file_get_contents(app_path('Livewire/Project/Application/Advanced.php')))
        ->not->toContain('is_preview_deployments_enabled', 'is_pr_deployments_public_enabled');
});

it('regenerates the preview domain when the UI adds a preview again', function () {
    $this->application->update([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{random}}.{{domain}}',
    ]);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 66,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/66',
        'fqdn' => 'https://previous.example.com',
    ]);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ])
        ->call('add', 66, 'https://github.com/example/repository/pull/66')
        ->assertDispatched('success');

    expect($preview->refresh()->fqdn)
        ->not->toBe('https://previous.example.com')
        ->and($preview->fqdn)->toEndWith('.example.com');
});

it('keeps the preview domain when a duplicate UI deployment is skipped', function () {
    Queue::fake();

    $this->application->update([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{random}}.{{domain}}',
    ]);
    ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 66,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/66',
        'git_type' => 'github',
        'fqdn' => 'https://stable.example.com',
    ]);

    $parameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ];

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->set('parameters', $parameters)
        ->call('deploy', 66)
        ->assertHasNoErrors();

    $queuedFqdn = $this->application->previews()->where('pull_request_id', 66)->value('fqdn');

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->set('parameters', $parameters)
        ->call('deploy', 66)
        ->assertHasNoErrors()
        ->assertDispatched('success', 'Deployment skipped', 'Deployment already queued for this commit.');

    expect($this->application->previews()->where('pull_request_id', 66)->value('fqdn'))->toBe($queuedFqdn)
        ->and($this->application->deployment_queue()->count())->toBe(1);
});
