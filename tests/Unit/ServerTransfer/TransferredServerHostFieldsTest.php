<?php

use App\Livewire\Server\CloudflareTunnel;
use App\Livewire\Server\PrivateKey\Show as PrivateKeyShow;
use App\Livewire\Server\Show;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A transferred server is an archive. Its host fields must not change, because "Enable management"
 * connects to that host, and nothing may call the cloud provider for it.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    Process::fake();
    Storage::fake('ssh-keys');
    Cache::flush();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'instance_timezone' => 'UTC', 'fqdn' => 'https://coolify.test']);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->otherKey = PrivateKey::factory()->create(['team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private']]);
    $this->cloudToken = CloudProviderToken::factory()->create(['team_id' => $this->team->id, 'provider' => 'hetzner']);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '203.0.113.10',
        'user' => 'root',
        'port' => 22,
        'hetzner_server_id' => 4242,
        'cloud_provider_token_id' => $this->cloudToken->id,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function archiveServerForHostFields(Server $server): Server
{
    app(ServerTransferClaimer::class)->markTransferred($server, targetInstanceUrl: 'https://target.test');

    return Server::query()->with('settings')->findOrFail($server->id);
}

function patchServerHostFields(object $test, Team $team, User $user, Server $server, array $payload): TestResponse
{
    app('auth')->forgetGuards();
    $token = $user->createToken('write-token', ['write']);
    $token->accessToken->forceFill(['team_id' => $team->id])->save();

    return $test->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken, 'Accept' => 'application/json'])
        ->patchJson('/api/v1/servers/'.$server->uuid, $payload);
}

test('the server page refuses a new host for an archive', function (string $field, mixed $value) {
    $server = archiveServerForHostFields($this->server);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->set($field, $value)
        ->call('submit')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    $server->refresh();
    expect($server->ip)->toBe('203.0.113.10')
        ->and($server->user)->toBe('root')
        ->and((int) $server->port)->toBe(22);
})->with([
    'ip' => ['ip', '198.51.100.9'],
    'user' => ['user', 'admin'],
    'port' => ['port', 2222],
]);

test('the server page still saves the name of an archive', function () {
    $server = archiveServerForHostFields($this->server);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->set('name', 'old-host')
        ->call('submit')
        ->assertDispatched('success');

    expect($server->fresh()->name)->toBe('old-host');
});

test('the server page still saves a new host for a managed server', function () {
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('ip', '198.51.100.9')
        ->set('port', 2222)
        ->call('submit')
        ->assertDispatched('success');

    expect($this->server->fresh()->ip)->toBe('198.51.100.9')
        ->and((int) $this->server->fresh()->port)->toBe(2222);
});

test('the private key page refuses a new key for an archive', function () {
    $server = archiveServerForHostFields($this->server);

    Livewire::test(PrivateKeyShow::class, ['server_uuid' => $server->uuid])
        ->call('setPrivateKey', $this->otherKey->id)
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($server->fresh()->private_key_id)->toBe($this->privateKey->id);
    Process::assertNothingRan();
});

test('the API refuses a new host or key for an archive', function (string $field) {
    $server = archiveServerForHostFields($this->server);
    $payload = [
        'ip' => '198.51.100.9',
        'user' => 'admin',
        'port' => 2222,
        'private_key_uuid' => $this->otherKey->uuid,
    ];

    patchServerHostFields($this, $this->team, $this->user, $server, [$field => $payload[$field]])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    $server->refresh();
    expect($server->ip)->toBe('203.0.113.10')
        ->and($server->user)->toBe('root')
        ->and((int) $server->port)->toBe(22)
        ->and($server->private_key_id)->toBe($this->privateKey->id);
})->with(['ip', 'user', 'port', 'private_key_uuid']);

test('the API accepts unchanged host fields and other changes for an archive', function () {
    $server = archiveServerForHostFields($this->server);

    patchServerHostFields($this, $this->team, $this->user, $server, [
        'name' => 'old-host',
        'ip' => '203.0.113.10',
        'user' => 'root',
        'port' => 22,
        'private_key_uuid' => $this->privateKey->uuid,
    ])->assertCreated();

    expect($server->fresh()->name)->toBe('old-host');
});

test('the API still changes the host of a managed server', function () {
    patchServerHostFields($this, $this->team, $this->user, $this->server, ['ip' => '198.51.100.9', 'port' => 2222])
        ->assertCreated();

    expect($this->server->fresh()->ip)->toBe('198.51.100.9')
        ->and((int) $this->server->fresh()->port)->toBe(2222);
});

test('enable management uses the server validation limit on Cloud', function () {
    config()->set('constants.coolify.self_hosted', false);
    instanceSettings()->forceFill(['server_validation_max_failures_per_team' => 1])->save();
    $this->team->forceFill(['custom_server_limit' => 5])->save();
    $server = archiveServerForHostFields($this->server);
    Server::throttleValidation($this->team->id, '192.0.2.1');

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->call('enableManagement')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    $server->refresh();
    expect($server->isTransferredAway())->toBeTrue()
        ->and((bool) $server->settings->force_disabled)->toBeTrue();
    Process::assertNothingRan();
});

test('the cloud provider state of an archive is not refreshed', function () {
    Http::fake();
    $this->server->forceFill([
        'ip' => '1.2.3.4',
        'hetzner_server_status' => 'running',
        'vultr_instance_id' => 'vultr-1',
        'vultr_instance_status' => 'active',
        'digitalocean_droplet_id' => 77,
        'digitalocean_droplet_status' => 'active',
        'hostinger_virtual_machine_id' => 88,
        'hostinger_virtual_machine_status' => 'running',
    ])->save();
    $server = archiveServerForHostFields($this->server);

    expect($server->refreshHetznerState())->toBe('running')
        ->and($server->refreshVultrState())->toBe('active')
        ->and($server->refreshDigitalOceanState())->toBe('active')
        ->and($server->refreshHostingerState())->toBe('running')
        ->and($server->backfillPlaceholderIp('198.51.100.9'))->toBeFalse()
        ->and($server->fresh()->ip)->toBe('1.2.3.4');
    Http::assertNothingSent();
});

test('a member cannot make the server page call the cloud provider for an archive', function () {
    Http::fake();
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->server->forceFill(['vultr_instance_id' => 'vultr-1', 'digitalocean_droplet_id' => 77, 'hostinger_virtual_machine_id' => 88])->save();
    $server = archiveServerForHostFields($this->server);
    $this->actingAs($member);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->call('checkHetznerServerStatus', true)
        ->call('checkVultrInstanceStatus', true)
        ->call('checkDigitalOceanDropletStatus', true)
        ->call('checkHostingerVirtualMachineStatus', true);

    Http::assertNothingSent();
});

test('the server page does not search or link a cloud server for an archive', function () {
    Http::fake();
    $server = archiveServerForHostFields($this->server);
    $server->forceFill(['hetzner_server_id' => null, 'cloud_provider_token_id' => null])->save();

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->set('selectedHetznerTokenId', $this->cloudToken->id)
        ->call('searchHetznerServer')
        ->set('manualHetznerServerId', 9999)
        ->call('searchHetznerServerById')
        ->set('matchedHetznerServer', ['id' => 9999])
        ->call('linkToHetzner');

    expect($server->fresh()->hetzner_server_id)->toBeNull();
    Http::assertNothingSent();
});

test('the Cloudflare tunnel of an archive cannot be turned on', function () {
    $archive = archiveServerForHostFields($this->server);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $archive->uuid])
        ->call('manualCloudflareConfig')
        ->assertDispatched('error');

    expect((bool) $archive->settings->fresh()->is_cloudflare_tunnel)->toBeFalse();
});
