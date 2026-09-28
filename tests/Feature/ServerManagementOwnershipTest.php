<?php

use App\Livewire\Server\Show;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);
    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => 'https://coolify-a.test',
    ]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

test('owner can stop managing a server from this instance', function () {
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('toggleManagement')
        ->assertDispatched('success');

    $this->server->refresh();

    expect($this->server->isTransferredAway())->toBeTrue()
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue()
        ->and((bool) $this->server->settings->is_sentinel_enabled)->toBeFalse();
});

test('owner can take management of a transferred server', function () {
    $this->server->server_metadata = [
        'transfer' => ['status' => 'transferred'],
    ];
    $this->server->save();
    $this->server->settings->forceFill([
        'force_disabled' => true,
        'is_sentinel_enabled' => false,
    ])->save();

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('toggleManagement')
        ->assertDispatched('success');

    $this->server->refresh();

    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('claimed')
        ->and((bool) $this->server->settings->force_disabled)->toBeFalse()
        ->and((bool) $this->server->settings->is_sentinel_enabled)->toBeTrue()
        ->and((string) $this->server->settings->sentinel_custom_url)->toBe('https://coolify-a.test');
});

function markServerTransferredAway(Server $server): void
{
    $server->server_metadata = [
        'transfer' => ['status' => 'transferred'],
    ];
    $server->save();
    $server->settings->forceFill([
        'force_disabled' => true,
        'is_sentinel_enabled' => false,
    ])->save();
}

test('cloud team over its server limit cannot take management of a transferred server', function () {
    config()->set('constants.coolify.self_hosted', false);
    $this->team->forceFill(['custom_server_limit' => 1])->save();
    Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    markServerTransferredAway($this->server);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('toggleManagement')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    $this->server->refresh();

    expect($this->server->isTransferredAway())->toBeTrue()
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue();
});

test('cloud team within its server limit can take management of a transferred server', function () {
    config()->set('constants.coolify.self_hosted', false);
    $this->team->forceFill(['custom_server_limit' => 1])->save();
    markServerTransferredAway($this->server);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('toggleManagement')
        ->assertDispatched('success');

    $this->server->refresh();

    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('claimed')
        ->and((bool) $this->server->settings->force_disabled)->toBeFalse();
});

test('claiming a server keeps it force disabled when the cloud team is over its server limit', function () {
    config()->set('constants.coolify.self_hosted', false);
    $this->team->forceFill(['custom_server_limit' => 1])->save();
    Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    markServerTransferredAway($this->server);

    app(ServerTransferClaimer::class)->claim($this->server, writeRemote: false);

    $this->server->refresh();

    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('claimed')
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue();
});

test('team members cannot change server management ownership', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('toggleManagement')
        ->assertForbidden();

    expect($this->server->fresh()->isTransferredAway())->toBeFalse();
});

test('server overview shows a management button that matches the ownership state', function () {
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Disable management')
        ->assertDontSee('Enable management')
        ->assertDontSeeHtml('wire:confirm')
        ->call('toggleManagement', '')
        ->assertSee('Enable management')
        ->assertDontSee('Disable management');
});

test('management ownership is not available outside development', function () {
    config(['app.env' => 'production']);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertDontSee('Disable management')
        ->call('toggleManagement')
        ->assertNotFound();

    expect($this->server->fresh()->isTransferredAway())->toBeFalse();
});
