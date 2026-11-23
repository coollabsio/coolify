<?php

use App\Livewire\Security\PrivateKey\Show as PrivateKeyShow;
use App\Livewire\Source\Github\Change as GithubAppChange;
use App\Livewire\Storage\Form as StorageForm;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('ssh-keys');

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->team->members()->attach($this->member, ['role' => 'member']);
});

it('does not serialize a private key for a member who cannot update it', function () {
    $privateKeyValue = PrivateKey::generateNewKeyPair('ed25519')['private_key'];
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'private_key' => $privateKeyValue,
    ]);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(PrivateKeyShow::class, ['private_key_uuid' => $privateKey->uuid])
        ->assertSuccessful()
        ->assertSet('newPrivateKey', '', strict: true)
        ->assertDontSee($privateKeyValue)
        ->call('revealPrivateKey', 'password')
        ->assertReturned(null);
});

it('does not serialize a private key for an owner until it is revealed', function () {
    $privateKeyValue = PrivateKey::generateNewKeyPair('ed25519')['private_key'];
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'private_key' => $privateKeyValue,
    ]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(PrivateKeyShow::class, ['private_key_uuid' => $privateKey->uuid])
        ->assertSuccessful()
        ->assertSet('newPrivateKey', '', strict: true)
        ->assertDontSee($privateKeyValue)
        ->call('revealPrivateKey', 'password')
        ->assertReturned($privateKeyValue);
});

it('does not serialize GitHub App secrets for a member who cannot update it', function () {
    $githubApp = createGithubAppWithSecrets($this->team);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
        ->test(GithubAppChange::class)
        ->assertSuccessful()
        ->assertSet('clientSecret', null)
        ->assertSet('webhookSecret', null)
        ->assertDontSee('stored-client-secret')
        ->assertDontSee('stored-webhook-secret');
});

it('does not serialize secrets from a system-wide GitHub App for another team', function () {
    $githubApp = createGithubAppWithSecrets($this->team, isSystemWide: true);
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser, ['role' => 'owner']);

    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
        ->test(GithubAppChange::class)
        ->assertSuccessful()
        ->assertSet('clientSecret', null)
        ->assertSet('webhookSecret', null)
        ->assertDontSee('stored-client-secret')
        ->assertDontSee('stored-webhook-secret');
});

it('keeps GitHub App secrets available to an owner who can update it', function () {
    $githubApp = createGithubAppWithSecrets($this->team);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
        ->test(GithubAppChange::class)
        ->assertSuccessful()
        ->assertSet('clientSecret', 'stored-client-secret')
        ->assertSet('webhookSecret', 'stored-webhook-secret');
});

function createGithubAppWithSecrets(Team $team, bool $isSystemWide = false): GithubApp
{
    return GithubApp::query()->create([
        'team_id' => $team->id,
        'name' => 'security-test-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'client_secret' => 'stored-client-secret',
        'webhook_secret' => 'stored-webhook-secret',
        'is_system_wide' => $isSystemWide,
    ]);
}

function createS3StorageWithSecrets(Team $team): S3Storage
{
    return S3Storage::query()->create([
        'team_id' => $team->id,
        'name' => 'Backup storage',
        'region' => 'us-east-1',
        'key' => 'stored-access-key',
        'secret' => 'stored-secret-key',
        'bucket' => 'backups',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
    ]);
}

it('does not serialize S3 credentials for a member after any storage action', function () {
    $storage = createS3StorageWithSecrets($this->team);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(StorageForm::class, ['storage' => $storage])
        ->assertSet('key', '')
        ->assertSet('secret', '')
        ->assertSet('hasSavedSecret', false)
        ->call('submit')
        ->assertSet('key', '')
        ->assertSet('secret', '')
        ->assertDontSee('stored-access-key')
        ->assertDontSee('stored-secret-key');
});

it('lets an owner save S3 settings with the saved secret without sending it to the browser', function () {
    if (! defined('CURLOPT_RESOLVE')) {
        define('CURLOPT_RESOLVE', 10203);
    }
    $storage = createS3StorageWithSecrets($this->team);
    $storage->update(['endpoint' => 'https://1.1.1.1']);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->allows('files')->andReturn([]);
    Storage::shouldReceive('build')
        ->atLeast()->once()
        ->with(Mockery::on(fn (array $config): bool => $config['secret'] === 'stored-secret-key'))
        ->andReturn($disk);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(StorageForm::class, ['storage' => $storage])
        ->assertSet('key', 'stored-access-key')
        ->assertSet('secret', '')
        ->assertSet('hasSavedSecret', true)
        ->assertDontSee('stored-secret-key')
        ->set('name', 'Renamed storage')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('secret', '')
        ->assertDontSee('stored-secret-key');

    $storage->refresh();
    expect($storage->name)->toBe('Renamed storage')
        ->and($storage->secret)->toBe('stored-secret-key');
});

it('hides the S3 credential fields for a member after the session switches to a team they own', function () {
    $storage = createS3StorageWithSecrets($this->team);
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($this->member, ['role' => 'owner']);

    $this->actingAs($this->member);
    session(['currentTeam' => $ownTeam]);

    Livewire::test(StorageForm::class, ['storage' => $storage])
        ->assertSet('isPasswordHiddenForMember', true)
        ->assertSet('secret', '');
});
