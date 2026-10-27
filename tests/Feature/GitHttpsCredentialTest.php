<?php

use App\Livewire\Project\Application\Source;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    Storage::fake('ssh-keys');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'git_repository' => 'https://dev.azure.com/org/project/_git/app',
        'git_branch' => 'main',
    ]);
});

function gitHttpsCredentialRawPassword(Application $application): ?string
{
    return DB::table('applications')->where('id', $application->id)->value('git_https_password');
}

function gitHttpsCredentialApiToken(User $user, Team $team): string
{
    session(['currentTeam' => $team]);

    return $user->createToken('git-https-credential-test', ['root'])->plainTextToken;
}

test('an admin saves the HTTPS credential encrypted and the browser never gets the password', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(Source::class, ['application' => $this->application])
        ->set('gitHttpsUsername', 'deploy-bot')
        ->set('gitHttpsPassword', 'azure-test-value')
        ->call('submit')
        ->assertSet('gitHttpsPassword', null)
        ->assertSet('hasGitHttpsPassword', true);

    $application = $this->application->fresh();
    expect($application->git_https_username)->toBe('deploy-bot')
        ->and($application->git_https_password)->toBe('azure-test-value')
        ->and(gitHttpsCredentialRawPassword($application))->not->toContain('azure-test-value')
        ->and($component->html())->not->toContain('azure-test-value');

    $component->set('gitHttpsUsername', 'other-bot')->call('submit');
    expect($this->application->fresh()->git_https_password)->toBe('azure-test-value')
        ->and($this->application->fresh()->git_https_username)->toBe('other-bot');

    $component->call('removeGitHttpsCredential')->assertSet('hasGitHttpsPassword', false);
    expect($this->application->fresh()->git_https_password)->toBeNull()
        ->and($this->application->fresh()->git_https_username)->toBeNull();
});

test('a username without a password is rejected', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(Source::class, ['application' => $this->application])
        ->set('gitHttpsUsername', 'deploy-bot')
        ->call('submit')
        ->assertDispatched('error');

    expect($this->application->fresh()->git_https_username)->toBeNull();
});

test('a member of the application team cannot change the HTTPS credential after switching to a team they own', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'member']);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($user->id, ['role' => 'owner']);
    $this->application->forceFill(['git_https_password' => 'existing-token'])->save();
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(Source::class, ['application' => $this->application]);
    session(['currentTeam' => $otherTeam]);
    $component->set('gitHttpsPassword', 'attacker-token')->call('submit');
    $component->call('removeGitHttpsCredential');

    expect($this->application->fresh()->git_https_password)->toBe('existing-token');
});

test('deployment logs never contain the HTTPS credential', function () {
    $this->application->forceFill(['git_https_username' => 'deploy-bot', 'git_https_password' => 'log-canary-token'])->save();
    $application = $this->application->fresh();
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
    ]);
    ['commands' => $command] = $application->generateGitLsRemoteCommands('deploy-uuid');

    $deployment->addLogEntry(message: 'remote said log-canary-token', command: $command);

    $logs = (string) $deployment->fresh()->logs;
    expect($command)->toContain($application->gitHttpsCredentialBasicToken())
        ->and($logs)->not->toContain('log-canary-token')
        ->and($logs)->not->toContain($application->gitHttpsCredentialBasicToken());
});

test('the API creates an application with an HTTPS credential and never returns the password', function () {
    $token = gitHttpsCredentialApiToken($this->owner, $this->team);

    $response = $this->withToken($token)->postJson('/api/v1/applications/public', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'git_repository' => 'https://git.example.com/org/private.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'autogenerate_domain' => false,
        'git_https_username' => 'deploy-bot',
        'git_https_password' => 'api-created-token',
    ])->assertCreated();

    $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();
    expect($application->git_https_password)->toBe('api-created-token')
        ->and($application->gitHttpsCredentialConfigOption())->not->toBeNull();

    $this->withToken($token)->getJson("/api/v1/applications/{$application->uuid}")
        ->assertOk()
        ->assertJsonPath('git_https_username', 'deploy-bot')
        ->assertJsonMissingPath('git_https_password')
        ->assertDontSee('api-created-token');
});

test('the API rejects an HTTPS credential where it is not used', function (string $endpoint, array $payload) {
    $token = gitHttpsCredentialApiToken($this->owner, $this->team);

    $this->withToken($token)->postJson($endpoint, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'autogenerate_domain' => false,
        ...$payload,
    ])->assertUnprocessable()->assertJsonValidationErrors('git_https_password');
})->with([
    'github.com' => ['/api/v1/applications/public', ['git_repository' => 'https://github.com/org/app', 'git_https_password' => 'token']],
    'username only' => ['/api/v1/applications/public', ['git_repository' => 'https://git.example.com/org/app.git', 'git_https_username' => 'deploy-bot']],
    'deploy key endpoint' => ['/api/v1/applications/private-deploy-key', ['git_repository' => 'git@git.example.com:org/app.git', 'git_https_password' => 'token']],
]);

test('the API updates and removes the HTTPS credential', function () {
    $token = gitHttpsCredentialApiToken($this->owner, $this->team);

    $this->withToken($token)->patchJson("/api/v1/applications/{$this->application->uuid}", [
        'git_https_username' => 'deploy-bot',
        'git_https_password' => 'updated-token',
    ])->assertOk()->assertDontSee('updated-token');
    expect($this->application->fresh()->git_https_password)->toBe('updated-token');

    $this->withToken($token)->patchJson("/api/v1/applications/{$this->application->uuid}", [
        'git_https_password' => null,
    ])->assertOk();
    expect($this->application->fresh()->git_https_password)->toBeNull()
        ->and($this->application->fresh()->git_https_username)->toBeNull();
});

test('the API rejects an HTTPS credential for an application with a deploy key', function () {
    $token = gitHttpsCredentialApiToken($this->owner, $this->team);
    $this->application->forceFill(['private_key_id' => 0])->save();

    $this->withToken($token)->patchJson("/api/v1/applications/{$this->application->uuid}", [
        'git_https_password' => 'token',
    ])->assertUnprocessable()->assertJsonValidationErrors('git_https_password');
    expect($this->application->fresh()->git_https_password)->toBeNull();
});

test('a token of another team cannot set the HTTPS credential', function () {
    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
    $token = gitHttpsCredentialApiToken($otherUser, $otherTeam);

    $this->withToken($token)->patchJson("/api/v1/applications/{$this->application->uuid}", [
        'git_https_password' => 'attacker-token',
    ])->assertNotFound();
    expect($this->application->fresh()->git_https_password)->toBeNull();
});
