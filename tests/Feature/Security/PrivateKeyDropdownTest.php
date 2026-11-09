<?php

use App\Livewire\Security\PrivateKey\Index;
use App\Livewire\Security\PrivateKey\Show;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    Log::spy();
    Config::set('cache.default', 'array');
    Storage::fake('ssh-keys');
});

test('generating a private key from the index stores it without redirecting to a detail page', function () {
    $component = Livewire::test(Index::class)
        ->call('generatePrivateKey', 'ed25519');

    $privateKey = PrivateKey::query()->firstOrFail();

    expect($privateKey->team_id)->toBe($this->team->id)
        ->and($privateKey->public_key)->toStartWith('ssh-ed25519');

    $component
        ->assertDispatched('success')
        ->assertNoRedirect();
});

test('saving a private key from its modal editor closes the modal', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    Livewire::test(Show::class, [
        'private_key_uuid' => $privateKey->uuid,
    ])->set('name', 'Updated SSH key')
        ->call('changePrivateKey')
        ->assertDispatched('privateKeyUpdated')
        ->assertNoRedirect();

    expect($privateKey->fresh()->name)->toBe('Updated SSH key');
});

test('private key editor lists the github app that uses the key', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'is_git_related' => true,
    ]);
    $githubApp = GithubApp::create([
        'name' => 'Key user GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'private_key_id' => $privateKey->id,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);

    Livewire::test(Show::class, ['private_key_uuid' => $privateKey->uuid])
        ->assertSee('Key user GitHub App')
        ->assertSee(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]), false)
        ->assertDontSee('Used by GitHub App');
});

test('used private key editor disables delete with an explanation', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    Livewire::test(Show::class, ['private_key_uuid' => $privateKey->uuid])
        ->assertSee('This private key is currently used by a server, application, or Git app and cannot be deleted.', false)
        ->assertSee('disabled', false);
});

test('used private key delete action keeps the key and shows an error', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    Livewire::test(Show::class, ['private_key_uuid' => $privateKey->uuid])
        ->call('delete')
        ->assertDispatched('error');

    expect($privateKey->fresh())->not->toBeNull();
});
