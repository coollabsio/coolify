<?php

use App\Actions\Server\ConfigureCloudflared;
use App\Livewire\Security\IntegrationTokenEditor;
use App\Livewire\Security\IntegrationTokenForm;
use App\Livewire\Server\CloudflareTunnel;
use App\Models\DnsProviderZone;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\CloudflareTokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const CF_TUNNEL_API = '/client/v4/accounts/acc-1/cfd_tunnel';
const CF_TUNNEL_DNS = '/client/v4/zones/zone-1/dns_records';

beforeEach(function () {
    $this->withoutDefer();
    $this->withoutVite();
    Server::flushIdentityMap();
    Queue::fake();
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'port' => 2222]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);

    $this->token = IntegrationToken::factory()->for($this->team)->create([
        'name' => 'Cloudflare tunnel', 'token' => 'cf-api-secret', 'capabilities' => ['dns', 'tunnel'],
    ]);
    DnsProviderZone::factory()->for($this->token)->create([
        'provider_zone_id' => 'zone-1', 'name' => 'example.com', 'account_id' => 'acc-1',
    ]);
    $this->tunnelName = "coolify-{$this->server->uuid}";
});

afterEach(function () {
    Server::flushIdentityMap();
});

/**
 * Fakes Cloudflare by "METHOD path" routes; any other request fails the test.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeCloudflareTunnelApi(array $routes): void
{
    Http::fake(function (Request $request) use ($routes) {
        $key = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
        if (! array_key_exists($key, $routes)) {
            throw new RuntimeException("Unexpected Cloudflare request: {$key}");
        }

        return $routes[$key];
    });
}

function newCloudflareTunnelRoutes(array $overrides = []): array
{
    return array_merge([
        'GET '.CF_TUNNEL_API => Http::response(['success' => true, 'result' => []]),
        'POST '.CF_TUNNEL_API => Http::response(['success' => true, 'result' => ['id' => 'tun-1', 'token' => 'tunnel-secret']]),
        'PUT '.CF_TUNNEL_API.'/tun-1/configurations' => Http::response(['success' => true, 'result' => []]),
        'GET '.CF_TUNNEL_DNS => Http::response(['success' => true, 'result' => []]),
        'POST '.CF_TUNNEL_DNS => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
    ], $overrides);
}

function fakeCloudflaredActivity(string $expectedTunnelToken): void
{
    $activity = Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]);
    ConfigureCloudflared::shouldRun()->once()
        ->withArgs(fn (Server $server, string $tunnelToken, string $hostname) => $tunnelToken === $expectedTunnelToken && $hostname === 'ssh.example.com')
        ->andReturn($activity);
}

test('a new tunnel is created, routed to the server ssh port, and gets a proxied cname', function () {
    fakeCloudflareTunnelApi(newCloudflareTunnelRoutes());
    fakeCloudflaredActivity('tunnel-secret');

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('cloudflare_integration_token_id', $this->token->id)
        ->set('api_ssh_domain', 'https://SSH.example.com/')
        ->call('apiCloudflareConfig')
        ->assertHasNoErrors()
        ->assertDispatched('activityMonitor');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), CF_TUNNEL_API)
        && $request['name'] === $this->tunnelName && $request['config_src'] === 'cloudflare'
        && $request->hasHeader('Authorization', 'Bearer cf-api-secret'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['config']['ingress'] === [
            ['hostname' => 'ssh.example.com', 'service' => 'ssh://localhost:2222'],
            ['service' => 'http_status:404'],
        ]);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), CF_TUNNEL_DNS)
        && $request['type'] === 'CNAME' && $request['content'] === 'tun-1.cfargotunnel.com' && $request['proxied'] === true);

    $settings = $this->server->settings->fresh();
    $record = ManagedDnsRecord::query()->sole();
    expect($settings->cloudflare_tunnel_id)->toBe('tun-1')
        ->and($settings->cloudflare_tunnel_account_id)->toBe('acc-1')
        ->and($settings->cloudflare_tunnel_integration_token_id)->toBe($this->token->id)
        ->and($record->owned)->toBeTrue()
        ->and($record->references()->sole()->resource_id)->toBe($this->server->id);
});

test('a re-run reuses the existing tunnel and its dns record', function () {
    $record = ManagedDnsRecord::factory()->create([
        'team_id' => $this->team->id, 'integration_token_id' => $this->token->id,
        'dns_provider_zone_id' => DnsProviderZone::query()->sole()->id, 'provider_record_id' => 'rec-1',
        'type' => 'CNAME', 'name' => 'ssh.example.com', 'content' => 'tun-1.cfargotunnel.com', 'owned' => true,
    ]);
    fakeCloudflareTunnelApi(newCloudflareTunnelRoutes([
        'GET '.CF_TUNNEL_API => Http::response(['success' => true, 'result' => [['id' => 'tun-1', 'name' => $this->tunnelName]]]),
        'GET '.CF_TUNNEL_API.'/tun-1/token' => Http::response(['success' => true, 'result' => 'tunnel-secret-again']),
        'GET '.CF_TUNNEL_DNS => Http::response(['success' => true, 'result' => [[
            'id' => 'rec-1', 'type' => 'CNAME', 'name' => 'ssh.example.com', 'content' => 'tun-1.cfargotunnel.com',
            'proxied' => true, 'comment' => $record->ownershipComment(),
        ]]]),
    ]));
    fakeCloudflaredActivity('tunnel-secret-again');

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('cloudflare_integration_token_id', $this->token->id)
        ->set('api_ssh_domain', 'ssh.example.com')
        ->call('apiCloudflareConfig')
        ->assertHasNoErrors();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    expect($record->fresh()->owned)->toBeTrue()
        ->and($this->server->settings->fresh()->cloudflare_tunnel_id)->toBe('tun-1');
});

test('an existing dns record is replaced only after confirmation', function () {
    fakeCloudflareTunnelApi(newCloudflareTunnelRoutes([
        'GET '.CF_TUNNEL_DNS => Http::response(['success' => true, 'result' => [[
            'id' => 'rec-old', 'type' => 'A', 'name' => 'ssh.example.com', 'content' => '203.0.113.10', 'proxied' => false,
        ]]]),
        'PATCH '.CF_TUNNEL_DNS.'/rec-old' => Http::response(['success' => true, 'result' => ['id' => 'rec-old']]),
        'DELETE '.CF_TUNNEL_API.'/tun-1' => Http::response(['success' => true, 'result' => ['id' => 'tun-1']]),
    ]));

    $component = Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('cloudflare_integration_token_id', $this->token->id)
        ->set('api_ssh_domain', 'ssh.example.com')
        ->call('apiCloudflareConfig')
        ->assertSet('dnsConflict.current', '203.0.113.10')
        ->assertSee('ssh.example.com already points to 203.0.113.10.');

    Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['PATCH', 'POST'], true) && str_contains($request->url(), 'dns_records'));
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), CF_TUNNEL_API.'/tun-1'));
    expect($this->server->settings->fresh()->cloudflare_tunnel_id)->toBeNull();

    fakeCloudflaredActivity('tunnel-secret');
    $component->call('replaceDnsAndConfigure')->assertSet('dnsConflict', null);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->data() === ['content' => 'tun-1.cfargotunnel.com', 'type' => 'CNAME', 'proxied' => true]);
    expect($this->server->settings->fresh()->cloudflare_tunnel_id)->toBe('tun-1');
});

test('a token added from the tunnel page is selected when it is the only tunnel token', function () {
    $this->token->update(['capabilities' => ['dns']]);

    $component = Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Add Cloudflare token');

    $this->token->update(['capabilities' => ['dns', 'tunnel']]);

    $component->dispatch('integrationTokenAdded')
        ->assertSet('cloudflare_integration_token_id', $this->token->id)
        ->assertDontSee('Add Cloudflare token');
});

test('a missing tunnel permission is reported and nothing is saved', function () {
    fakeCloudflareTunnelApi(newCloudflareTunnelRoutes([
        'GET '.CF_TUNNEL_API => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403),
    ]));
    ConfigureCloudflared::shouldRun()->never();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('cloudflare_integration_token_id', $this->token->id)
        ->set('api_ssh_domain', 'ssh.example.com')
        ->call('apiCloudflareConfig')
        ->assertDispatched('error', 'The Cloudflare token needs the Account → Cloudflare Tunnel → Edit permission.');

    expect($this->server->settings->fresh()->cloudflare_tunnel_id)->toBeNull()
        ->and(ManagedDnsRecord::query()->count())->toBe(0);
});

test('a failed dns step deletes only a tunnel that the same run created', function (bool $tunnelExists, bool $deleted) {
    fakeCloudflareTunnelApi(newCloudflareTunnelRoutes([
        'GET '.CF_TUNNEL_API => Http::response(['success' => true, 'result' => $tunnelExists ? [['id' => 'tun-1', 'name' => $this->tunnelName]] : []]),
        'GET '.CF_TUNNEL_API.'/tun-1/token' => Http::response(['success' => true, 'result' => 'tunnel-secret']),
        'POST '.CF_TUNNEL_DNS => Http::response(['success' => false], 403),
        'DELETE '.CF_TUNNEL_API.'/tun-1' => Http::response(['success' => true, 'result' => ['id' => 'tun-1']]),
    ]));
    ConfigureCloudflared::shouldRun()->never();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('cloudflare_integration_token_id', $this->token->id)
        ->set('api_ssh_domain', 'ssh.example.com')
        ->call('apiCloudflareConfig')
        ->assertDispatched('error', 'The Cloudflare token needs the Zone → DNS → Edit permission.');

    $deleteSent = fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), CF_TUNNEL_API.'/tun-1');
    $deleted ? Http::assertSent($deleteSent) : Http::assertNotSent($deleteSent);
    expect($this->server->settings->fresh()->cloudflare_tunnel_id)->toBeNull();
})->with([
    'new tunnel' => [false, true],
    'existing tunnel' => [true, false],
]);

describe('disable', function () {
    beforeEach(function () {
        Process::fake();
        Sleep::fake();
        Storage::fake('ssh-keys');
        Storage::fake('ssh-mux');
        $this->server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id]);
        $this->server->settings->update([
            'is_cloudflare_tunnel' => true, 'cloudflare_tunnel_id' => 'tun-1',
            'cloudflare_tunnel_account_id' => 'acc-1', 'cloudflare_tunnel_integration_token_id' => $this->token->id,
        ]);
        $this->record = ManagedDnsRecord::factory()->create([
            'team_id' => $this->team->id, 'integration_token_id' => $this->token->id,
            'dns_provider_zone_id' => DnsProviderZone::query()->sole()->id, 'provider_record_id' => 'rec-1',
            'type' => 'CNAME', 'name' => 'ssh.example.com', 'content' => 'tun-1.cfargotunnel.com', 'owned' => true,
        ]);
        $this->record->addReference($this->server);
        $this->server->update(['ip' => 'ssh.example.com', 'ip_previous' => '203.0.113.20']);
    });

    test('deletes the tunnel and the owned dns record', function () {
        fakeCloudflareTunnelApi([
            'DELETE '.CF_TUNNEL_API.'/tun-1/connections' => Http::response(['success' => true, 'result' => null]),
            'DELETE '.CF_TUNNEL_API.'/tun-1' => Http::response(['success' => true, 'result' => ['id' => 'tun-1']]),
            'GET '.CF_TUNNEL_DNS.'/rec-1' => Http::response(['success' => true, 'result' => [
                'id' => 'rec-1', 'type' => 'CNAME', 'name' => 'ssh.example.com', 'content' => 'tun-1.cfargotunnel.com',
                'comment' => $this->record->ownershipComment(),
            ]]),
            'DELETE '.CF_TUNNEL_DNS.'/rec-1' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleCloudflareTunnels')
            ->assertNotDispatched('warning');

        // Detached, so stopping cloudflared does not cut the SSH session that runs through the tunnel.
        Process::assertRan(fn ($process) => str_contains($process->command, "nohup setsid sh -c 'sleep 2; docker rm -f coolify-cloudflared' > /dev/null 2>&1 < /dev/null &"));
        Sleep::assertNeverSlept();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), CF_TUNNEL_API.'/tun-1'));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), CF_TUNNEL_DNS.'/rec-1'));

        $settings = $this->server->settings->fresh();
        expect($settings->is_cloudflare_tunnel)->toBeFalsy()
            ->and($settings->cloudflare_tunnel_id)->toBeNull()
            ->and($settings->cloudflare_tunnel_integration_token_id)->toBeNull()
            ->and(ManagedDnsRecord::query()->count())->toBe(0);
    });

    test('still disables locally and warns when cloudflare fails', function () {
        fakeCloudflareTunnelApi([
            'DELETE '.CF_TUNNEL_API.'/tun-1/connections' => Http::response([], 500),
            'DELETE '.CF_TUNNEL_API.'/tun-1' => Http::response([], 500),
            'GET '.CF_TUNNEL_DNS.'/rec-1' => Http::response([], 500),
        ]);

        Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleCloudflareTunnels')
            ->assertDispatched('warning', "Cloudflare could not delete the tunnel {$this->tunnelName}. Delete it in Cloudflare.")
            ->assertDispatched('warning', 'The DNS record ssh.example.com could not be deleted. Delete it in Cloudflare.');

        $settings = $this->server->settings->fresh();
        expect($settings->is_cloudflare_tunnel)->toBeFalsy()
            ->and($settings->cloudflare_tunnel_id)->toBeNull();
        Sleep::assertSleptTimes(4);
    });

    test('waits until the stopping connector disconnects before the tunnel is deleted', function () {
        $deletes = 0;
        Http::fake(function (Request $request) use (&$deletes) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() === 'DELETE' && $path === CF_TUNNEL_API.'/tun-1' => ++$deletes === 1
                    ? Http::response(['success' => false, 'errors' => [['code' => 1022, 'message' => 'Cannot delete tunnel because it has active connections']]], 400)
                    : Http::response(['success' => true, 'result' => ['id' => 'tun-1']]),
                $request->method() === 'GET' && $path === CF_TUNNEL_DNS.'/rec-1' => Http::response(['success' => true, 'result' => [
                    'id' => 'rec-1', 'type' => 'CNAME', 'name' => 'ssh.example.com', 'content' => 'tun-1.cfargotunnel.com',
                    'comment' => $this->record->ownershipComment(),
                ]]),
                default => Http::response(['success' => true, 'result' => null]),
            };
        });

        Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleCloudflareTunnels')
            ->assertNotDispatched('warning');

        expect($deletes)->toBe(2);
        Sleep::assertSleptTimes(1);
    });

    test('stops cloudflared detached for a non-root ssh user', function () {
        $this->server->update(['user' => 'ubuntu']);
        Http::fake(['*' => Http::response(['success' => true, 'result' => null])]);

        Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleCloudflareTunnels');

        Process::assertRan(fn ($process) => str_contains($process->command,
            "sudo bash -c 'nohup setsid sh -c '\\''sleep 2; docker rm -f coolify-cloudflared'\\'' > /dev/null 2>&1 < /dev/null &'"));
    });

    test('a tunnel that coolify did not create is disabled without cloudflare calls', function () {
        Http::fake();
        $this->server->settings->update(['cloudflare_tunnel_id' => null, 'cloudflare_tunnel_account_id' => null]);

        Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleCloudflareTunnels');

        Http::assertNothingSent();
        expect($this->server->settings->fresh()->is_cloudflare_tunnel)->toBeFalsy();
    });
});

describe('authorization', function () {
    test('members without update rights cannot start the setup', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member);
        Http::fake();

        Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
            ->set('cloudflare_integration_token_id', $this->token->id)
            ->set('api_ssh_domain', 'ssh.example.com')
            ->call('apiCloudflareConfig')
            ->assertForbidden();

        Http::assertNothingSent();
    });

    test('a token of another team is rejected', function () {
        $otherToken = IntegrationToken::factory()->create(['capabilities' => ['dns', 'tunnel']]);
        Http::fake();

        Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
            ->set('cloudflare_integration_token_id', $otherToken->id)
            ->set('api_ssh_domain', 'ssh.example.com')
            ->call('apiCloudflareConfig')
            ->assertHasErrors('cloudflare_integration_token_id');

        Http::assertNothingSent();
    });

    test('a token without the tunnel capability is not listed or accepted', function () {
        $this->token->update(['capabilities' => ['dns']]);
        Http::fake();

        Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
            ->assertSee('Enable the Cloudflare Tunnel capability on a Cloudflare token')
            ->set('cloudflare_integration_token_id', $this->token->id)
            ->set('api_ssh_domain', 'ssh.example.com')
            ->call('apiCloudflareConfig')
            ->assertHasErrors('cloudflare_integration_token_id');

        Http::assertNothingSent();
    });

    test('the server team decides the tokens after the session team changes', function () {
        $admin = User::factory()->create();
        $this->team->members()->attach($admin->id, ['role' => 'admin']);
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($admin->id, ['role' => 'owner']);
        $otherTeamToken = IntegrationToken::factory()->for($otherTeam)->create(['capabilities' => ['dns', 'tunnel']]);
        $this->actingAs($admin);

        $component = Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid]);
        session(['currentTeam' => $otherTeam]);

        fakeCloudflareTunnelApi(newCloudflareTunnelRoutes());
        $component->set('cloudflare_integration_token_id', $otherTeamToken->id)
            ->set('api_ssh_domain', 'ssh.example.com')
            ->call('apiCloudflareConfig')
            ->assertHasErrors('cloudflare_integration_token_id');
        Http::assertNothingSent();

        fakeCloudflaredActivity('tunnel-secret');
        $component->set('cloudflare_integration_token_id', $this->token->id)
            ->call('apiCloudflareConfig')
            ->assertHasNoErrors();

        expect($this->server->settings->fresh()->cloudflare_tunnel_id)->toBe('tun-1');
    });
});

describe('tunnel capability', function () {
    test('the validator checks tunnel access of the zone account', function (int $tunnelStatus, bool $valid) {
        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['result' => ['status' => 'active']]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => [['id' => 'zone-1', 'account' => ['id' => 'acc-1']]]]),
            'api.cloudflare.com/client/v4/accounts/acc-1/cfd_tunnel*' => Http::response(['success' => $valid], $tunnelStatus),
        ]);

        expect(app(CloudflareTokenValidator::class)->validate('cf-api-secret', ['dns', 'tunnel']))->toBe($valid);
    })->with([
        'permitted' => [200, true],
        'missing permission' => [403, false],
    ]);

    test('selecting the tunnel capability also selects dns', function () {
        Livewire::test(IntegrationTokenForm::class)
            ->set('capabilities', [])
            ->set('capabilities', ['tunnel'])
            ->assertSet('capabilities', ['tunnel', 'dns']);

        $this->token->update(['capabilities' => ['dns']]);
        Livewire::test(IntegrationTokenEditor::class, ['integration_token_uuid' => $this->token->uuid])
            ->set('capabilities', ['tunnel'])
            ->assertSet('capabilities', ['tunnel', 'dns']);
    });
});

describe('cloudflare token template url', function () {
    $permissionsOf = function (string $url): array {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return json_decode($query['permissionGroupKeys'], true);
    };

    test('a dns token asks for dns edit and zone read', function () use ($permissionsOf) {
        expect($permissionsOf(IntegrationToken::cloudflareTokenTemplateUrl(['dns'])))->toBe([
            ['key' => 'dns', 'type' => 'edit'],
            ['key' => 'zone', 'type' => 'read'],
        ]);
    });

    test('a tunnel token also asks for cloudflare tunnel edit', function () use ($permissionsOf) {
        expect($permissionsOf(IntegrationToken::cloudflareTokenTemplateUrl(['dns', 'tunnel'])))
            ->toContain(['key' => 'argotunnel', 'type' => 'edit']);
    });
});
