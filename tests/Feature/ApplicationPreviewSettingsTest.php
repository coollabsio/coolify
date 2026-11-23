<?php

use App\Livewire\Project\Application\Previews;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

it('toggles preview deployments from the section action', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->call('togglePreviewDeployments')
        ->assertSet('isPreviewDeploymentsEnabled', true);

    expect($this->application->fresh()->settings->is_preview_deployments_enabled)->toBeTrue();
});

it('does not load GitHub pull requests for GitLab sources', function () {
    $gitlabApp = GitlabApp::create([
        'name' => 'Self-hosted GitLab',
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'team_id' => $this->team->id,
    ]);
    $this->application->update(['source_id' => $gitlabApp->id, 'source_type' => GitlabApp::class]);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertDontSee('Load pull requests')
        ->call('load_prs')
        ->assertSet('rate_limit_remaining', 0)
        ->assertDispatched('error');
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

it('saves the docker tag of the remaining preview after another preview is deleted', function () {
    $this->application->update(['build_pack' => 'dockerimage']);
    $previewA = ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 2, 'pull_request_html_url' => '', 'docker_registry_image_tag' => 'tag-a']);
    $previewB = ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 1, 'pull_request_html_url' => '', 'docker_registry_image_tag' => 'tag-b']);
    Queue::fake();

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->call('delete', 2)
        ->call('save_preview', $previewB->id)
        ->assertHasNoErrors();

    expect($previewB->fresh()->docker_registry_image_tag)->toBe('tag-b');
});

it('rejects docker tags that are not valid image tags', function (string $method) {
    $this->application->update(['build_pack' => 'dockerimage']);
    $preview = ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 7, 'pull_request_html_url' => '', 'docker_registry_image_tag' => 'pr-7']);
    $invalidTag = "it's not a tag";

    $component = Livewire::test(Previews::class, ['application' => $this->application]);
    match ($method) {
        'save_preview' => $component->set("previewDockerTags.{$preview->id}", $invalidTag)->call('save_preview', $preview->id),
        'add' => $component->call('add', 7, null, $invalidTag),
        'deploy' => $component->call('deploy', 7, null, false, $invalidTag),
    };

    expect($preview->fresh()->docker_registry_image_tag)->toBe('pr-7');
})->with(['save_preview', 'add', 'deploy']);

it('rejects preview pull request URLs that are not http or https', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->call('add', 9, 'ftp://git.example.com/pull/9');

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 9)->exists())->toBeFalse();
});

it('renders preview actions without stored values and hides non-http pull request links', function () {
    $this->application->update(['build_pack' => 'dockerimage']);
    ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'ftp://git.example.com/pull/7',
        'docker_registry_image_tag' => "it's-stored",
    ]);

    $html = $this->get(route('project.application.preview-deployments', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]))->assertOk()->getContent();

    expect($html)->toContain('wire:click="deploy(7)"')
        ->not->toMatch('/wire:click="[^"]*stored/')
        ->not->toContain('href="ftp:');
});

it('deploys a docker image preview with its stored tag when no tag is passed', function () {
    Queue::fake();
    $this->application->update(['build_pack' => 'dockerimage']);
    $component = Livewire::test(Previews::class, ['application' => $this->application])
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ]);
    ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 7, 'pull_request_html_url' => '', 'docker_registry_image_tag' => 'pr-7']);

    $component->call('deploy', 7)
        ->assertRedirect();

    expect(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 7)->value('docker_registry_image_tag'))->toBe('pr-7');
});

it('asks GitHub whether a pull request added from the list is a branch of the repository', function (int $headRepositoryId, bool $isSameRepository) {
    Queue::fake();
    $githubApp = GithubApp::create([
        'name' => 'Public GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'team_id' => $this->team->id,
    ]);
    $this->application->update([
        'build_pack' => 'dockercompose',
        'git_repository' => 'example/repo',
        'source_id' => $githubApp->id,
        'source_type' => GithubApp::class,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx\n",
    ]);
    Http::fake([
        'https://api.github.com/repos/example/repo/pulls/7' => Http::response([
            'number' => 7,
            'head' => ['repo' => ['id' => $headRepositoryId]],
            'base' => ['repo' => ['id' => 100]],
        ]),
    ]);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ])
        ->call('add', 7, 'https://github.com/example/repo/pull/7');

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 7)->value('is_same_repository'))->toBe($isSameRepository);
})->with([
    'branch of the repository' => [100, true],
    'fork' => [200, false],
]);
