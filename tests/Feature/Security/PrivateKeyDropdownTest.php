<?php

use App\Livewire\Security\PrivateKey\Create;
use App\Livewire\Security\PrivateKey\Index;
use App\Livewire\Security\PrivateKey\Show;
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

test('private key index shows highlighted add dropdown actions', function () {
    Livewire::test(Index::class)
        ->assertSee('+ Add')
        ->assertSee('Generate ED25519')
        ->assertSee('Generate RSA')
        ->assertSee('Add manually')
        ->assertDontSee('Manage your SSH keys for your servers and integrations.');
});

test('generating a private key from the index stores it and redirects to details', function () {
    $component = Livewire::test(Index::class)
        ->call('generatePrivateKey', 'ed25519');

    $privateKey = PrivateKey::query()->firstOrFail();

    expect($privateKey->team_id)->toBe($this->team->id)
        ->and($privateKey->public_key)->toStartWith('ssh-ed25519');

    $component->assertRedirect(route('security.private-key.show', [
        'private_key_uuid' => $privateKey->uuid,
    ]));
});

test('manual private key form does not expose key generation controls', function () {
    Livewire::test(Create::class)
        ->assertDontSee('Generate new ED25519 SSH Key')
        ->assertDontSee('Generate new RSA SSH Key');
});

test('private key details view reminds users to install the public key', function () {
    $view = file_get_contents(resource_path('views/livewire/security/private-key/show.blade.php'));

    expect($view)->toContain("ACTION REQUIRED: Copy the 'Public Key' to your server's ~/.ssh/authorized_keys file");
});

test('github app private key shows a highlighted badge under the title', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'is_git_related' => true,
    ]);

    $this->get(route('security.private-key.show', [
        'private_key_uuid' => $privateKey->uuid,
    ]))
        ->assertSuccessful()
        ->assertSee('Used by GitHub App')
        ->assertDontSee('Is used by a Git App?');
});

test('github app badge appears before the save button in the title row', function () {
    $view = file_get_contents(resource_path('views/livewire/security/private-key/show.blade.php'));

    $badgePosition = strpos($view, 'Used by GitHub App');
    $saveButtonPosition = strpos($view, '<x-forms.button canGate="update" :canResource="$private_key" type="submit">');

    expect($view)->toContain('<div class="flex items-center gap-2 pb-4">')
        ->and($view)->toContain('Used by GitHub App')
        ->and($view)->toContain('<x-forms.button canGate="update" :canResource="$private_key" type="submit">')
        ->and($badgePosition)->toBeLessThan($saveButtonPosition);
});

test('used private key details disable delete with an explanation', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    $this->get(route('security.private-key.show', [
        'private_key_uuid' => $privateKey->uuid,
    ]))
        ->assertSuccessful()
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
