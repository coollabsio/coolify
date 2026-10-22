<?php

use App\Livewire\Server\Show;
use App\Livewire\Server\TransferFinish;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A claim writes the owner marker on the host over SSH.
    Process::fake();
    Storage::fake('ssh-keys');
    config(['app.env' => 'production']);
    $this->withoutVite();
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

test('server transferred to another instance shows where it was transferred', function () {
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Transferred to another instance')
        ->assertSee('https://coolify-b.test')
        ->assertSee('Enable management');
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
        ->call('enableManagement')
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
        ->call('enableManagement')
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
        ->call('enableManagement')
        ->assertDispatched('success');

    $this->server->refresh();

    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('claimed')
        ->and((bool) $this->server->settings->force_disabled)->toBeFalse();
});

test('claiming a server fails without changes when the cloud team is over its server limit', function () {
    config()->set('constants.coolify.self_hosted', false);
    $this->team->forceFill(['custom_server_limit' => 1])->save();
    Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    markServerTransferredAway($this->server);

    expect(fn () => app(ServerTransferClaimer::class)->claim($this->server))
        ->toThrow(RuntimeException::class, 'over its server limit');

    $this->server->refresh();

    expect($this->server->isTransferredAway())->toBeTrue()
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue();
    Process::assertNothingRan();
});

test('team members cannot take management of a transferred server', function () {
    markServerTransferredAway($this->server);
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('enableManagement')
        ->assertForbidden();

    expect($this->server->fresh()->isTransferredAway())->toBeTrue();
});

test('a managed server has no management button and cannot be disabled from the server page', function () {
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertDontSee('Enable management')
        ->assertDontSee('Disable management')
        ->call('enableManagement')
        ->assertNotDispatched('success');

    expect(data_get($this->server->fresh()->server_metadata, 'transfer.status'))->toBeNull();
});

test('server validation button is hidden while the server is transferred away', function () {
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSeeHtml('wire:click.prevent="validateServer"');

    app(ServerTransferClaimer::class)->markTransferred($this->server->fresh(), targetInstanceUrl: 'https://coolify-b.test');

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertDontSeeHtml('wire:click.prevent="validateServer"');
});

test('taking back a transferred server fails without changes when the host cannot be reached', function () {
    markServerTransferredAway($this->server);
    Process::fake(['*' => Process::result(errorOutput: 'Connection refused', exitCode: 1)]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('enableManagement')
        ->assertNotDispatched('success');

    $this->server->refresh();

    expect($this->server->isTransferredAway())->toBeTrue()
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue();
});

test('after a take back the server page shows the finish list until the user finishes the transfer', function () {
    markServerTransferredAway($this->server);
    Application::factory()->create([
        'name' => 'shop',
        'destination_id' => $this->server->standaloneDockers()->first()->id,
        'destination_type' => StandaloneDocker::class,
        'git_repository' => 'https://github.com/coollabsio/coolify',
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('enableManagement')
        ->assertDispatched('transferFinishStarted');

    Livewire::test(TransferFinish::class, ['server' => $this->server->fresh()])
        ->assertSee('Finish the transfer')
        ->assertSee('Manual webhook of shop')
        ->call('finishTransfer')
        ->assertDontSee('Finish the transfer');
    expect(data_get($this->server->fresh()->server_metadata, 'transfer.finish.finished_at'))->not->toBeNull();
});
