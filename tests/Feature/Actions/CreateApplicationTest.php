<?php

use App\Actions\Application\CreateApplication;
use App\Actions\Application\LoadComposeFile;
use App\Actions\Shared\ResolveResourcePlacement;
use App\Enums\ProxyTypes;
use App\Exceptions\DomainAlreadyInUseException;
use App\Exceptions\GithubAppNotFoundException;
use App\Exceptions\GitRepositoryNotAccessibleException;
use App\Exceptions\PrivateKeyNotFoundException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function appPlacement(): array
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $placement = ResolveResourcePlacement::run($team->id, $project->uuid, 'production', null, $server->uuid, null);

    return [$placement, $team];
}

function seedPublicGithubSource(): void
{
    GithubApp::unguarded(fn () => GithubApp::create([
        'id' => 0, 'name' => 'Public GitHub', 'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com', 'is_public' => true, 'team_id' => 0,
    ]));
}

function rsaPrivateKeyPem(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return $pem;
}

it('creates a dockerimage application with normalized image and placeholders', function () {
    Queue::fake();
    [$placement] = appPlacement();

    $app = CreateApplication::run($placement, 'dockerimage', [
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'alpine',
        'ports_exposes' => '80',
        'name' => 'edge',
    ]);

    expect($app)->toBeInstanceOf(Application::class)
        ->and($app->build_pack)->toBe('dockerimage')
        ->and($app->docker_registry_image_name)->toBe('nginx')
        ->and($app->docker_registry_image_tag)->toBe('alpine')
        ->and($app->git_repository)->toBe('coollabsio/coolify')
        ->and($app->environment_id)->toBe($placement->environment->id)
        ->and($app->destination_id)->toBe($placement->destination->id)
        ->and(ApplicationDeploymentQueue::count())->toBe(0);
});

it('queues a deployment when instant_deploy is true', function () {
    Queue::fake();
    [$placement] = appPlacement();

    $app = CreateApplication::run($placement, 'dockerimage', [
        'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80',
    ], true);

    expect(ApplicationDeploymentQueue::where('application_id', $app->id)->exists())->toBeTrue();
});

it('reads the port from the decoded Dockerfile', function () {
    Queue::fake();
    [$placement] = appPlacement();

    $app = CreateApplication::run($placement, 'dockerfile', [
        'dockerfile' => "FROM nginx\nEXPOSE 3000\n",
    ]);

    expect($app->build_pack)->toBe('dockerfile')
        ->and((string) $app->ports_exposes)->toBe('3000')
        ->and($app->name)->toStartWith('dockerfile-');
});

it('stores public github.com repositories against the public GitHub source', function () {
    Queue::fake();
    seedPublicGithubSource();
    [$placement] = appPlacement();

    $app = CreateApplication::run($placement, 'public', [
        'git_repository' => 'https://github.com/coollabsio/coolify-examples',
        'git_branch' => 'main', 'build_pack' => 'nixpacks', 'ports_exposes' => '3000',
    ]);

    expect($app->source_type)->toBe(GithubApp::class)
        ->and($app->source_id)->toBe(0)
        ->and($app->git_repository)->toBe('coollabsio/coolify-examples')
        ->and($app->name)->not->toBeEmpty();
});

it('dispatches LoadComposeFile for dockercompose without instant deploy', function () {
    Queue::fake();
    [$placement] = appPlacement();

    CreateApplication::run($placement, 'public', [
        'git_repository' => 'https://gitlab.com/acme/app', 'git_branch' => 'main', 'build_pack' => 'dockercompose',
    ]);

    LoadComposeFile::assertPushed();
});

it('links a private deploy key and rejects unknown keys', function () {
    Queue::fake();
    [$placement, $team] = appPlacement();
    $key = PrivateKey::factory()->create(['team_id' => $team->id, 'is_git_related' => true]);

    $app = CreateApplication::run($placement, 'private-deploy-key', [
        'git_repository' => 'git@gitlab.com:acme/app.git', 'git_branch' => 'main',
        'build_pack' => 'nixpacks', 'ports_exposes' => '3000', 'private_key_uuid' => $key->uuid,
    ]);
    expect($app->private_key_id)->toBe($key->id);

    expect(fn () => CreateApplication::run($placement, 'private-deploy-key', [
        'git_repository' => 'git@gitlab.com:acme/app.git', 'git_branch' => 'main',
        'build_pack' => 'nixpacks', 'ports_exposes' => '3000', 'private_key_uuid' => 'missing',
    ]))->toThrow(PrivateKeyNotFoundException::class);
});

it('verifies repository access for a GitHub app and stores the repository id', function () {
    Queue::fake();
    [$placement, $team] = appPlacement();
    $key = PrivateKey::factory()->create(['team_id' => $team->id, 'private_key' => rsaPrivateKeyPem()]);
    $ghApp = GithubApp::unguarded(fn () => GithubApp::create([
        'name' => 'acme-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'team_id' => $team->id, 'app_id' => 1, 'installation_id' => 2, 'client_id' => 'c', 'client_secret' => 's',
        'webhook_secret' => 'w', 'private_key_id' => $key->id,
    ]));
    Http::fake([
        'api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_x'], 201),
        'api.github.com/repos/acme/app' => Http::response(['id' => 4242], 200),
        'api.github.com/repos/acme/nope' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $app = CreateApplication::run($placement, 'private-gh-app', [
        'git_repository' => 'acme/app', 'git_branch' => 'main', 'build_pack' => 'nixpacks',
        'ports_exposes' => '3000', 'github_app_uuid' => $ghApp->uuid,
    ]);
    expect($app->repository_project_id)->toBe(4242)
        ->and($app->source_id)->toBe($ghApp->id);

    expect(fn () => CreateApplication::run($placement, 'private-gh-app', [
        'git_repository' => 'acme/nope', 'git_branch' => 'main', 'build_pack' => 'nixpacks',
        'ports_exposes' => '3000', 'github_app_uuid' => $ghApp->uuid,
    ]))->toThrow(GitRepositoryNotAccessibleException::class);

    expect(fn () => CreateApplication::run($placement, 'private-gh-app', [
        'git_repository' => 'acme/app', 'git_branch' => 'main', 'build_pack' => 'nixpacks',
        'ports_exposes' => '3000', 'github_app_uuid' => 'missing',
    ]))->toThrow(GithubAppNotFoundException::class);
});

it('throws DomainAlreadyInUseException unless force_domain_override', function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    [$placement] = appPlacement();
    $placement->server->proxy = ['type' => ProxyTypes::TRAEFIK->value];
    $placement->server->save();

    CreateApplication::run($placement, 'dockerimage', [
        'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80', 'domains' => 'https://app.example.com',
    ]);

    expect(fn () => CreateApplication::run($placement, 'dockerimage', [
        'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80', 'domains' => 'https://app.example.com',
    ]))->toThrow(DomainAlreadyInUseException::class);

    $forced = CreateApplication::run($placement, 'dockerimage', [
        'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80',
        'domains' => 'https://app.example.com', 'force_domain_override' => true,
    ]);
    expect($forced->fqdn)->toBe('https://app.example.com');
});

it('applies the boolean flags and settings fields for every type', function () {
    Queue::fake();
    [$placement] = appPlacement();

    $app = CreateApplication::run($placement, 'dockerimage', [
        'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80',
        'is_static' => true, 'use_build_secrets' => true, 'is_gzip_enabled' => false,
    ]);

    expect($app->settings->is_static)->toBeTrue()
        ->and($app->settings->use_build_secrets)->toBeTrue()
        ->and($app->settings->is_gzip_enabled)->toBeFalse();
});

it('rejects unknown types', function () {
    [$placement] = appPlacement();

    CreateApplication::run($placement, 'nope', []);
})->throws(InvalidArgumentException::class);
