<?php

use App\Actions\Shared\CheckDomainDns;
use App\Livewire\Project\Application\Domains;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PurplePixie\PhpDns\DNSQuery;
use PurplePixie\PhpDns\DNSResult;
use PurplePixie\PhpDns\DNSTypes;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.store' => 'array']);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        [
            'is_dns_validation_enabled' => true,
            'custom_dns_servers' => '192.0.2.1',
        ]
    ));
});

function bindDnsAnswers(string $cnameTarget = '', string $aTarget = ''): void
{
    app()->bind(DNSQuery::class, function ($app, array $parameters) use ($cnameTarget, $aTarget) {
        return new class($parameters['server'], $cnameTarget, $aTarget) extends DNSQuery
        {
            public function __construct(
                private readonly string $dnsServer,
                private readonly string $cnameTarget,
                private readonly string $aTarget,
            ) {
                parent::__construct($dnsServer);
            }

            public function query(string $question, string $typeName = DNSTypes::NAME_A)
            {
                if (in_array($typeName, [DNSTypes::NAME_CNAME, 'CNAME'], true) && $this->cnameTarget !== '') {
                    return [new DNSResult($typeName, 1, 'IN', 60, $this->cnameTarget, $question, '', [])];
                }

                if ($typeName === DNSTypes::NAME_A && $this->aTarget !== '') {
                    return [new DNSResult($typeName, 1, 'IN', 60, $this->aTarget, $question, '', [])];
                }

                return [];
            }

            public function hasError(): bool
            {
                return false;
            }
        };
    });
}

function unsavedHttpTunnelServer(string $ip = '203.0.113.10'): Server
{
    $server = new Server(['ip' => $ip]);
    $server->id = 1;
    $server->setRelation('settings', new ServerSetting([
        'is_cloudflare_http_tunnel' => true,
        'cloudflare_http_tunnel_cname' => 'abcd1234.cfargotunnel.com',
    ]));

    return $server;
}

it('treats a CNAME to cfargotunnel.com as a successful Recheck', function () {
    bindDnsAnswers('abcd1234.cfargotunnel.com');

    $result = CheckDomainDns::run(
        ['app' => 'http://app.example.com'],
        unsavedHttpTunnelServer(),
        '203.0.113.10',
    );

    expect($result['app']['status'])->toBe('ok')
        ->and($result['app']['message'])->toContain('Cloudflare Tunnel')
        ->and($result['app']['expected_ip'])->toBe('abcd1234.cfargotunnel.com')
        ->and($result['app']['message'])->not->toContain('203.0.113.10');
});

it('does not treat an A record to the server public IP as success in tunnel mode', function () {
    bindDnsAnswers('', '203.0.113.10');

    $result = CheckDomainDns::run(
        ['app' => 'http://app.example.com'],
        unsavedHttpTunnelServer(),
        '203.0.113.10',
    );

    expect($result['app']['status'])->toBe('failed')
        ->and($result['app']['message'])->toContain('Cloudflare Tunnel')
        ->and($result['app']['message'])->not->toContain('203.0.113.10')
        ->and($result['app']['message'])->not->toContain('Required DNS record type A');
});

it('rejects sslip.io hostnames when HTTP tunnel mode is on', function () {
    $result = CheckDomainDns::run(
        ['app' => 'http://foo.203.0.113.10.sslip.io'],
        unsavedHttpTunnelServer(),
        '203.0.113.10',
    );

    expect($result['app']['status'])->toBe('failed')
        ->and($result['app']['message'])->toContain('Cloudflare Tunnel')
        ->and($result['app']['message'])->not->toContain('Required DNS record type A pointing to 203.0.113.10');
});

it('defaults new application domains to http and disables redirect-to-https on tunneled servers', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_dns_validation_enabled' => false]
    ));
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $keyId,
        'ip' => '203.0.113.10',
    ]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_cloudflare_http_tunnel' => true,
        'cloudflare_http_tunnel_cname' => 'abcd1234.cfargotunnel.com',
        'wildcard_domain' => 'http://example.com',
    ]);

    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Tunnel App',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => null,
        'build_pack' => 'nixpacks',
    ]);
    $application->settings()->update(['is_container_label_readonly_enabled' => true]);

    expect($application->settings()->value('is_force_https_enabled'))->toBeFalse();

    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->assertSet('newDomainParts.scheme', 'http')
        ->assertSet('isForceHttpsEnabled', false)
        ->set('newDomainParts.host', 'app.example.com')
        ->call('addDomain');

    expect($application->fresh()->fqdn)->toStartWith('http://app.example.com');
});

it('keeps the SSH-only helper string on the SSH section of the Cloudflare Tunnel page', function () {
    expect(file_get_contents(resource_path('views/livewire/server/cloudflare-tunnel.blade.php')))
        ->toContain('Proxy SSH traffic through Cloudflare so the server SSH port can remain closed.')
        ->toContain('Publish HTTP apps through Cloudflare Tunnel');
});
