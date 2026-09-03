<?php

use App\Livewire\Security\PrivateKey\Show as PrivateKeyShow;
use App\Livewire\Source\Github\Change as GithubAppChange;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
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
        ->assertSet('privateKeyValue', '')
        ->assertDontSee($privateKeyValue);
});

it('keeps a private key available to an owner who can update it', function () {
    $privateKeyValue = PrivateKey::generateNewKeyPair('ed25519')['private_key'];
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'private_key' => $privateKeyValue,
    ]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(PrivateKeyShow::class, ['private_key_uuid' => $privateKey->uuid])
        ->assertSuccessful()
        ->assertSet('privateKeyValue', $privateKeyValue);
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
