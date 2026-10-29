<?php

use App\Enums\ServerRole;
use App\Livewire\Project\New\GiteaPrivateRepository;
use App\Livewire\Project\New\GithubPrivateRepository;
use App\Livewire\Project\New\GitlabPrivateRepository;
use App\Livewire\Project\New\PublicGitRepository;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GiteaApp;
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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'dest-flows',
        'network' => 'coolify-flows',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function repositoryFlowTarget(Testable $component): Testable
{
    return $component
        ->set('parameters', ['project_uuid' => test()->project->uuid, 'environment_uuid' => test()->environment->uuid])
        ->set('query', ['destination' => test()->destination->uuid]);
}

function publicRepositoryFlow(): Testable
{
    return repositoryFlowTarget(Livewire::test(PublicGitRepository::class, ['type' => 'public']))
        ->set('repository_url', 'https://github.com/acme/web')
        ->set('git_repository', 'https://github.com/acme/web')
        ->set('git_branch', 'main');
}

function githubAppRepositoryFlow(): Testable
{
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pemKey);
    $githubApp = GithubApp::create([
        'name' => 'GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_secret' => 'webhook-secret',
        'private_key_id' => PrivateKey::create(['name' => 'Key', 'private_key' => $pemKey, 'team_id' => test()->team->id])->id,
        'team_id' => test()->team->id,
        'is_system_wide' => false,
    ]);
    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/installations/67890/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        'https://api.github.com/installation/repositories*' => Http::response([
            'total_count' => 1,
            'repositories' => [['id' => 1, 'name' => 'web', 'owner' => ['login' => 'acme']]],
        ], 200),
    ]);

    return repositoryFlowTarget(Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app']))
        ->call('loadRepositories', $githubApp->id)
        ->set('selected_repository_owner', 'acme')
        ->set('selected_repository_repo', 'web')
        ->set('selected_branch_name', 'main');
}

function gitlabRepositoryFlow(): Testable
{
    $gitlabApp = GitlabApp::create([
        'name' => 'GitLab',
        'api_url' => 'https://1.1.1.1/api/v4',
        'html_url' => 'https://1.1.1.1',
        'custom_user' => 'git',
        'custom_port' => 22,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_token' => 'webhook-token',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => time() + 3600,
        'team_id' => test()->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
    Http::fake(['1.1.1.1/api/v4/*' => Http::response([['name' => 'main']], 200)]);

    return repositoryFlowTarget(Livewire::test(GitlabPrivateRepository::class, ['type' => 'private-gitlab-app']))
        ->set('gitlab_app_id', $gitlabApp->id)
        ->set('selected_project_id', 42)
        ->set('selected_repository_path', 'acme/web')
        ->set('selected_branch_name', 'main');
}

function giteaRepositoryFlow(): Testable
{
    // Http::GitSource() pins DNS before Http::fake() answers, so use an IP literal.
    $giteaApp = GiteaApp::create([
        'name' => 'Gitea',
        'api_url' => 'https://1.1.1.1/api/v1',
        'html_url' => 'https://1.1.1.1',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => time() + 3600,
        'team_id' => test()->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
    Http::fake([
        '1.1.1.1/api/v1/user/repos*' => Http::response([
            ['id' => 42, 'name' => 'web', 'full_name' => 'acme/web', 'default_branch' => 'main', 'html_url' => 'https://1.1.1.1/acme/web', 'owner' => ['login' => 'acme']],
        ], 200),
    ]);

    return repositoryFlowTarget(Livewire::test(GiteaPrivateRepository::class, ['type' => 'private-gitea-app']))
        ->call('loadRepositories', $giteaApp->id)
        ->set('selected_branch_name', 'main');
}

dataset('repository flows', [
    'public repository' => 'publicRepositoryFlow',
    'github app' => 'githubAppRepositoryFlow',
    'gitlab' => 'gitlabRepositoryFlow',
    'gitea' => 'giteaRepositoryFlow',
]);

test('repository flows keep the entered base directory', function (string $flow) {
    $flow()
        ->set('build_pack', 'dockerfile')
        ->set('base_directory', '/apps/api')
        ->call('submit');

    expect(Application::query()->sole()->base_directory)->toBe('/apps/api');
})->with('repository flows');

test('repository flows reject an invalid base directory', function (string $flow) {
    $flow()
        ->set('build_pack', 'dockerfile')
        ->set('base_directory', '/apps; rm -rf /')
        ->call('submit');

    expect(Application::query()->count())->toBe(0);
})->with('repository flows');

test('repository flows do not create an application on a build-only server', function (string $flow) {
    $this->server->settings->update(['server_role' => ServerRole::BUILD]);
    Server::flushIdentityMap();

    $flow()->call('submit');

    expect(Application::query()->count())->toBe(0);
})->with('repository flows');
