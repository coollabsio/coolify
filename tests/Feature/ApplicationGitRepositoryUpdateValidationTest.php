<?php

use App\Livewire\Project\Application\Source;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('git-repository-update-test', ['*'])->plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
        'git_repository' => 'git@github.com:org/repo.git',
        'git_branch' => 'main',
        'private_key_id' => $privateKey->id,
    ]);
});

function patchApplicationGitRepository(mixed $gitRepository)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->patchJson('/api/v1/applications/'.test()->application->uuid, ['git_repository' => $gitRepository]);
}

test('PATCH rejects a git repository that git would read as an option', function (string $gitRepository) {
    patchApplicationGitRepository($gitRepository)
        ->assertUnprocessable()
        ->assertInvalid(['git_repository']);

    expect($this->application->refresh()->git_repository)->toBe('git@github.com:org/repo.git');
})->with([
    'upload-pack option' => ['--upload-pack=touch /tmp/pwned'],
    'option without value' => ['--upload-pack'],
    'short option path' => ['-u/x'],
    'absolute local path' => ['/root/.ssh'],
    'shell characters' => ['git@github.com:org/repo.git;id'],
]);

test('PATCH accepts a valid git repository URL', function () {
    patchApplicationGitRepository('git@github.com:org/other-repo.git')->assertOk();

    expect($this->application->refresh()->git_repository)->toBe('git@github.com:org/other-repo.git');
});

test('PATCH accepts the stored git repository without a new check', function () {
    $this->application->forceFill(['git_repository' => 'legacy value'])->saveQuietly();

    patchApplicationGitRepository('legacy value')->assertOk();
});

test('the Source form rejects a git repository that git would read as an option', function () {
    Livewire::actingAs($this->user)
        ->test(Source::class, ['application' => $this->application])
        ->set('gitRepository', '--upload-pack=touch /tmp/pwned')
        ->call('submit')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->application->refresh()->git_repository)->toBe('git@github.com:org/repo.git');
});

test('the deploy key ls-remote and clone commands end the git options before the repository', function () {
    $this->application->forceFill(['git_repository' => '--upload-pack=touch /tmp/pwned'])->saveQuietly();
    $application = $this->application->refresh();

    $lsRemote = collect($application->generateGitLsRemoteCommands('deploy-uuid', false)['commands'])->flatten()->implode("\n");
    $clone = collect($application->generateGitImportCommands('deploy-uuid', exec_in_docker: false)['commands'])->flatten()->implode("\n");

    expect($lsRemote)->toContain("git ls-remote -- '--upload-pack=touch /tmp/pwned'")
        ->and($clone)->toContain("-b 'main' -- '--upload-pack=touch /tmp/pwned'");
});
