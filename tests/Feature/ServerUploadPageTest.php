<?php

use App\Livewire\Server\Upload as ServerUpload;
use App\Livewire\Terminal\FileImport;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->team = Team::factory()->create([
        'show_boarding' => false,
    ]);

    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Key',
        'private_key' => <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY,
    ]);

    $this->server = Server::factory()->create([
        'name' => 'Upload Target Server',
        'ip' => 'coolify-upload-page-host',
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_terminal_enabled' => true,
    ]);
});

it('shows the upload link on the server page for owners with terminal access', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $response = $this->get(route('server.show', [
        'server_uuid' => $this->server->uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee('Upload');
    $response->assertSee(route('server.upload', [
        'server_uuid' => $this->server->uuid,
    ]), false);
});

it('renders the dedicated server upload page with the terminal upload component preselected to the current server', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $response = $this->get(route('server.upload', [
        'server_uuid' => $this->server->uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSeeLivewire(ServerUpload::class);
    $response->assertSeeLivewire(FileImport::class);
    $response->assertSee('Upload a file to this server or one of its running containers.');
    $response->assertSee('Upload Target Server');

    Livewire::test(ServerUpload::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('server.uuid', $this->server->uuid)
        ->assertSet('servers.0.uuid', $this->server->uuid);
});

it('forbids server upload pages for team members without terminal access', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->get(route('server.upload', [
        'server_uuid' => $this->server->uuid,
    ]))->assertForbidden();
});
