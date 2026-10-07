<?php

use App\Livewire\Server\PrivateKey\Show;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
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

    Config::set('cache.default', 'array');
    Storage::fake('ssh-keys');

    $this->currentPrivateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->currentPrivateKey->id,
    ]);
});

test('server private key page shows highlighted add dropdown actions', function () {
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Add')
        ->assertSee('Generate ED25519')
        ->assertSee('Generate RSA')
        ->assertSee('Add manually')
        ->assertSee('Check connection');
});

test('generating a server private key stores it and refreshes the current view', function () {
    $component = Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('generatePrivateKey', 'ed25519')
        ->assertNoRedirect()
        ->assertDispatched('success');

    $privateKey = PrivateKey::query()
        ->where('id', '!=', $this->currentPrivateKey->id)
        ->firstOrFail();

    expect($privateKey->team_id)->toBe($this->team->id)
        ->and($privateKey->public_key)->toStartWith('ssh-ed25519');

    $component
        ->assertDispatched('copyPublicKeyToClipboard', publicKey: $privateKey->public_key)
        ->assertSee($privateKey->name);
});

test('server private key cards include a copy public key button', function () {
    $keyData = PrivateKey::generateNewKeyPair('rsa');

    $alternativeKey = PrivateKey::createAndStore([
        'team_id' => $this->team->id,
        'name' => 'Alternative SSH Key',
        'description' => 'Created by test',
        'private_key' => $keyData['private_key'],
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Copy public key')
        ->assertSee('Alternative SSH Key')
        ->assertSeeHtml('copyPublicKeyToClipboard('.Js::from($alternativeKey->public_key)->toHtml().')');
});

describe('after the user switches the session team', function () {
    beforeEach(function () {
        Process::fake();
        Storage::fake('ssh-mux');

        $this->otherTeam = Team::factory()->create();
        $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);
        $this->otherTeamKey = PrivateKey::factory()->create([
            'team_id' => $this->otherTeam->id,
            'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
        ]);
        $this->sameTeamKey = PrivateKey::factory()->create([
            'team_id' => $this->team->id,
            'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
        ]);

        $this->component = Livewire::test(Show::class, ['server_uuid' => $this->server->uuid]);
        session(['currentTeam' => $this->otherTeam]);
        Once::flush();
    });

    test('lists only keys of the server team', function () {
        $keyIds = $this->component->call('generatePrivateKey', 'ed25519')->get('privateKeys')->pluck('id');

        expect($keyIds)->toContain($this->sameTeamKey->id)
            ->not->toContain($this->otherTeamKey->id);
    });

    test('generates the key in the server team', function () {
        $this->component->call('generatePrivateKey', 'ed25519');

        expect(PrivateKey::query()->latest('id')->firstOrFail()->team_id)->toBe($this->team->id);
    });

    test('rejects a key of the session team', function () {
        $this->component->call('setPrivateKey', $this->otherTeamKey->id)
            ->assertDispatched('error', 'You are not allowed to use this private key.');

        expect($this->server->fresh()->private_key_id)->toBe($this->currentPrivateKey->id);
    });

    test('accepts a key of the server team', function () {
        $this->component->call('setPrivateKey', $this->sameTeamKey->id)
            ->assertDispatched('success');

        expect($this->server->fresh()->private_key_id)->toBe($this->sameTeamKey->id);
    });
});
