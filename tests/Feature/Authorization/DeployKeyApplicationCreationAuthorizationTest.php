<?php

use App\Livewire\Project\New\GithubPrivateRepositoryDeployKey;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    InstanceSettings::query()->forceCreate(['id' => 0]);

    $this->rootTeam = Team::query()->forceCreate(['id' => 0, 'name' => 'Root Team']);
    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => 'admin']);
    $this->team->members()->attach($this->member, ['role' => 'member']);

    $this->privateKey = PrivateKey::withoutEvents(fn () => PrivateKey::factory()->create(['uuid' => (string) Str::uuid(), 'team_id' => $this->team->id]));
    $this->otherPrivateKey = PrivateKey::withoutEvents(fn () => PrivateKey::factory()->create(['uuid' => (string) Str::uuid(), 'team_id' => $this->otherTeam->id]));
    $this->rootPrivateKey = PrivateKey::withoutEvents(fn () => PrivateKey::factory()->create(['uuid' => (string) Str::uuid(), 'team_id' => 0]));
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function actAsDeployKeyUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

function deployKeyComponent(): Testable
{
    return Livewire::test(GithubPrivateRepositoryDeployKey::class, ['type' => 'private-deploy-key'])
        ->set('parameters', [
            'project_uuid' => test()->project->uuid,
            'environment_uuid' => test()->environment->uuid,
        ])
        ->set('query', ['destination' => test()->destination->uuid]);
}

function deployKeyApiPayload(PrivateKey $privateKey): array
{
    return [
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'server_uuid' => test()->server->uuid,
        'private_key_uuid' => $privateKey->uuid,
        'git_repository' => 'git@attacker.example:owner/repository.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'autogenerate_domain' => false,
    ];
}

test('admin can select and create an application with a key owned by the current team', function () {
    actAsDeployKeyUser($this->admin, $this->team);

    deployKeyComponent()
        ->call('setPrivateKey', $this->privateKey->id)
        ->assertSet('private_key_id', $this->privateKey->id)
        ->set('repository_url', 'git@example.com:owner/repository.git')
        ->set('branch', 'main')
        ->call('submit');

    expect(Application::query()->sole()->private_key_id)->toBe($this->privateKey->id);
});

test('member cannot invoke the deploy key selection action directly', function () {
    actAsDeployKeyUser($this->member, $this->team);
    $this->withoutExceptionHandling();

    expect(fn () => deployKeyComponent()->call('setPrivateKey', $this->privateKey->id))
        ->toThrow(AuthorizationException::class);

    expect(Application::query()->count())->toBe(0);
});

test('deploy key selection rejects foreign ids uuids missing ids and root team keys', function (mixed $key): void {
    actAsDeployKeyUser($this->admin, $this->team);

    expect(fn () => deployKeyComponent()->call('setPrivateKey', value($key)))
        ->toThrow(ModelNotFoundException::class);

    expect(Application::query()->count())->toBe(0);
})->with([
    'another team id' => fn () => test()->otherPrivateKey->id,
    'another team uuid' => fn () => test()->otherPrivateKey->uuid,
    'missing id' => 999999,
    'missing uuid' => '00000000-0000-0000-0000-000000000000',
    'root team key' => fn () => test()->rootPrivateKey->id,
]);

test('submit rejects a foreign key injected through hydrated component state without side effects', function () {
    actAsDeployKeyUser($this->admin, $this->team);

    expect(fn () => deployKeyComponent()
        ->set('private_key_id', $this->otherPrivateKey->id)
        ->set('repository_url', 'git@attacker.example:owner/repository.git')
        ->set('branch', 'main')
        ->call('submit'))
        ->toThrow(ModelNotFoundException::class);

    expect(Application::query()->count())->toBe(0)
        ->and(Storage::disk('ssh-keys')->allFiles())->toBeEmpty();
});

test('private deploy key api accepts a current team key and rejects foreign and root team keys', function () {
    actAsDeployKeyUser($this->admin, $this->team);
    $token = $this->admin->createToken('deploy-key-test', ['*'])->plainTextToken;
    auth()->logout();

    $this->withToken($token)
        ->postJson('/api/v1/applications/private-deploy-key', deployKeyApiPayload($this->privateKey))
        ->assertCreated();

    foreach ([$this->otherPrivateKey, $this->rootPrivateKey] as $unownedKey) {
        $this->withToken($token)
            ->postJson('/api/v1/applications/private-deploy-key', deployKeyApiPayload($unownedKey))
            ->assertNotFound();
    }

    expect(Application::query()->count())->toBe(1)
        ->and(Application::query()->sole()->private_key_id)->toBe($this->privateKey->id);
});

test('member cannot create a private deploy key application through the api', function () {
    actAsDeployKeyUser($this->member, $this->team);
    $token = $this->member->createToken('deploy-key-member-test', ['*'])->plainTextToken;
    auth()->logout();

    $this->withToken($token)
        ->postJson('/api/v1/applications/private-deploy-key', deployKeyApiPayload($this->privateKey))
        ->assertForbidden();

    expect(Application::query()->count())->toBe(0);
});
