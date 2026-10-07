<?php

use App\Livewire\Project\New\GithubPrivateRepository;
use App\Livewire\Project\New\GithubPrivateRepositoryDeployKey;
use App\Livewire\Project\New\PublicGitRepository;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\RepositoryDetector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);
});

test('members cannot start scans in any creation flow', function (string $componentClass) {
    $this->team->members()->attach($this->user->id, ['role' => 'member']);
    $component = new $componentClass;
    expect(fn () => $component->detectRepository())->toThrow(AuthorizationException::class);
})->with([PublicGitRepository::class, GithubPrivateRepository::class, GithubPrivateRepositoryDeployKey::class]);

test('owners cannot use scan base directory traversal', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $component = new PublicGitRepository;
    $component->git_repository = 'https://github.com/test/repo';
    $component->base_directory = '/../outside';
    expect(fn () => $component->detectRepository())->toThrow(ValidationException::class);
});

test('scan does not run on a destination from another team', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $otherServer = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $otherDestination = $otherServer->standaloneDockers()->firstOrFail();

    Livewire\Livewire::test(RepositoryScanStateTestComponent::class)
        ->set('git_repository', 'https://github.com/test/repo')
        ->set('query', ['destination' => $otherDestination->uuid])
        ->call('detectRepository')
        ->assertDispatched('error', 'Destination not found.')
        ->assertSet('detectionRan', false);
});

test('environment import stores valid keys and skips malformed keys', function () {
    $application = Application::factory()->create(['build_pack' => 'dockerfile']);
    $component = new class extends PublicGitRepository
    {
        public function importInto(Application $application): void
        {
            $this->importDetectedEnvironmentVariables($application);
        }
    };
    $component->envImported = true;
    $component->envExampleVars = ['VALID_KEY' => 'value', 'invalid.key' => 'ignored', '1BAD' => 'ignored'];
    $component->importInto($application);
    expect($application->environment_variables()->pluck('value', 'key')->all())->toBe(['VALID_KEY' => 'value']);
});

class RepositoryScanStateTestComponent extends PublicGitRepository
{
    public function mount() {}

    public function render()
    {
        return '<div></div>';
    }
}

test('clients cannot change the detected Dockerfile allowlist', function () {
    expect(fn () => Livewire\Livewire::test(RepositoryScanStateTestComponent::class)
        ->set('detectedDockerfiles', ['../../outside/Dockerfile']))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('deploy key scans clone with the key, like a deployment', function () {
    $application = (new Application)->forceFill(['git_repository' => 'git@github.com:test/private.git', 'git_branch' => 'main', 'git_commit_sha' => 'HEAD', 'private_key_id' => 5]);
    $application->setRelation('settings', (new ApplicationSetting)->forceFill(['is_git_shallow_clone_enabled' => true, 'is_git_submodules_enabled' => false]));
    $application->setRelation('source', null);
    $application->setRelation('private_key', (new PrivateKey)->forceFill(['private_key' => 'test-key']));
    $detector = new RepositoryDetector($application, '/', new Server);

    $clone = (new ReflectionMethod($detector, 'scanCommands'))->invoke($detector, 'scan-test')->get(3);

    expect($clone)->toStartWith('sh -c ')
        ->toContain(base64_encode('test-key'))
        ->toContain('-i /root/.ssh/id_rsa_coolify_scan-test')
        ->toContain('git@github.com:test/private.git');
});
