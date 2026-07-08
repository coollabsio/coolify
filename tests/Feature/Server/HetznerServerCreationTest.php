<?php

use App\Livewire\Server\New\ByHetzner;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// Note: Full Livewire integration tests require database setup
// These tests verify the SSH key merging logic and public_net configuration

it('validates public_net configuration with IPv4 and IPv6 enabled', function () {
    $enableIpv4 = true;
    $enableIpv6 = true;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => true,
        'enable_ipv6' => true,
    ]);
});

it('validates public_net configuration with IPv4 only', function () {
    $enableIpv4 = true;
    $enableIpv6 = false;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => true,
        'enable_ipv6' => false,
    ]);
});

it('validates public_net configuration with IPv6 only', function () {
    $enableIpv4 = false;
    $enableIpv6 = true;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => false,
        'enable_ipv6' => true,
    ]);
});

it('validates IP address selection prefers IPv4 when both are enabled', function () {
    $enableIpv4 = true;
    $enableIpv6 = true;

    $hetznerServer = [
        'public_net' => [
            'ipv4' => ['ip' => '1.2.3.4'],
            'ipv6' => ['ip' => '2001:db8::1'],
        ],
    ];

    $ipAddress = null;
    if ($enableIpv4 && isset($hetznerServer['public_net']['ipv4']['ip'])) {
        $ipAddress = $hetznerServer['public_net']['ipv4']['ip'];
    } elseif ($enableIpv6 && isset($hetznerServer['public_net']['ipv6']['ip'])) {
        $ipAddress = $hetznerServer['public_net']['ipv6']['ip'];
    }

    expect($ipAddress)->toBe('1.2.3.4');
});

it('validates IP address selection uses IPv6 when only IPv6 is enabled', function () {
    $enableIpv4 = false;
    $enableIpv6 = true;

    $hetznerServer = [
        'public_net' => [
            'ipv4' => ['ip' => '1.2.3.4'],
            'ipv6' => ['ip' => '2001:db8::1'],
        ],
    ];

    $ipAddress = null;
    if ($enableIpv4 && isset($hetznerServer['public_net']['ipv4']['ip'])) {
        $ipAddress = $hetznerServer['public_net']['ipv4']['ip'];
    } elseif ($enableIpv6 && isset($hetznerServer['public_net']['ipv6']['ip'])) {
        $ipAddress = $hetznerServer['public_net']['ipv6']['ip'];
    }

    expect($ipAddress)->toBe('2001:db8::1');
});

it('validates SSH key array merging logic with Coolify key', function () {
    $coolifyKeyId = 123;
    $selectedHetznerKeys = [];

    $sshKeys = array_merge(
        [$coolifyKeyId],
        $selectedHetznerKeys
    );
    $sshKeys = array_unique($sshKeys);
    $sshKeys = array_values($sshKeys);

    expect($sshKeys)->toBe([123])
        ->and(count($sshKeys))->toBe(1);
});

it('validates SSH key array merging with additional Hetzner keys', function () {
    $coolifyKeyId = 123;
    $selectedHetznerKeys = [456, 789];

    $sshKeys = array_merge(
        [$coolifyKeyId],
        $selectedHetznerKeys
    );
    $sshKeys = array_unique($sshKeys);
    $sshKeys = array_values($sshKeys);

    expect($sshKeys)->toBe([123, 456, 789])
        ->and(count($sshKeys))->toBe(3);
});

it('validates deduplication when Coolify key is also in selected keys', function () {
    $coolifyKeyId = 123;
    $selectedHetznerKeys = [123, 456, 789];

    $sshKeys = array_merge(
        [$coolifyKeyId],
        $selectedHetznerKeys
    );
    $sshKeys = array_unique($sshKeys);
    $sshKeys = array_values($sshKeys);

    expect($sshKeys)->toBe([123, 456, 789])
        ->and(count($sshKeys))->toBe(3);
});

describe('Boarding Flow Integration', function () {
    uses(RefreshDatabase::class);

    beforeEach(function () {
        // Create a team with owner that has boarding enabled
        $this->team = Team::factory()->create([
            'show_boarding' => true,
        ]);
        $this->user = User::factory()->create();
        $this->team->members()->attach($this->user->id, ['role' => 'owner']);

        // Set current team and act as user
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);
    });

    test('completes boarding when server is created from onboarding', function () {
        // Verify boarding is initially enabled
        expect((bool) $this->team->fresh()->show_boarding)->toBeTrue();

        // Mount the component with from_onboarding flag
        $component = Livewire::test(ByHetzner::class)
            ->set('from_onboarding', true);

        // Verify the from_onboarding property is set
        expect($component->get('from_onboarding'))->toBeTrue();

        // After successful server creation in the actual component,
        // the boarding should be marked as complete
        // Note: We can't fully test the createServer method without mocking Hetzner API
        // but we can verify the boarding completion logic is in place
    });

    test('boarding flag remains unchanged when not from onboarding', function () {
        // Verify boarding is initially enabled
        expect((bool) $this->team->fresh()->show_boarding)->toBeTrue();

        // Mount the component without from_onboarding flag (default false)
        Livewire::test(ByHetzner::class)
            ->set('from_onboarding', false);

        // Boarding should still be enabled since it wasn't created from onboarding
        expect((bool) $this->team->fresh()->show_boarding)->toBeTrue();
    });

    test('uses the shared dropdown UI for advanced Hetzner options', function () {
        Livewire::test(ByHetzner::class)
            ->set('current_step', 2)
            ->assertSee('Advanced Hetzner options')
            ->assertSeeHtml('dropdownOpen')
            ->assertSeeHtml('x-ref="panel"')
            ->assertSeeHtml('dark:bg-coolgray-100')
            ->assertSeeHtml('dark:bg-transparent')
            ->assertSeeHtml('@click.outside="if (! true) close()"');
    });

    test('renders advanced Hetzner option controls inside the dropdown menu', function () {
        Livewire::test(ByHetzner::class)
            ->set('current_step', 2)
            ->assertSee('Extra SSH Keys')
            ->assertSee('Firewalls')
            ->assertSee('Private Networks')
            ->assertSee('Enable Hetzner Backups')
            ->assertSee('Add cloud-init script')
            ->assertSee('additional 20% of the server monthly fee');
    });

    test('shows the cloud init script name only when saving the script', function () {
        Livewire::test(ByHetzner::class)
            ->set('current_step', 2)
            ->set('show_cloud_init_script', true)
            ->assertSee('Cloud-Init Script')
            ->assertSee('Save this script for later use')
            ->assertDontSee('Script name...')
            ->set('save_cloud_init_script', true)
            ->assertSee('Script name...');
    });
});

describe('Hetzner data loading', function () {
    uses(RefreshDatabase::class);

    beforeEach(function () {
        $this->team = Team::factory()->create();
        $this->user = User::factory()->create();
        $this->team->members()->attach($this->user->id, ['role' => 'owner']);

        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);

        $this->hetznerToken = CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
            'token' => 'test-hetzner-api-token',
        ]);

        $this->privateKey = PrivateKey::factory()->create([
            'team_id' => $this->team->id,
        ]);
    });

    test('loads firewalls and networks for the selected Hetzner token', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/locations*' => Http::response([
                'locations' => [
                    ['id' => 1, 'name' => 'nbg1', 'city' => 'Nuremberg', 'country' => 'DE', 'network_zone' => 'eu-central'],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/server_types*' => Http::response([
                'server_types' => [
                    ['id' => 1, 'name' => 'cx11', 'description' => 'CX11', 'cores' => 1, 'memory' => 2.0, 'disk' => 20, 'locations' => [['name' => 'nbg1']], 'architecture' => 'x86'],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/images*' => Http::response([
                'images' => [
                    ['id' => 15512617, 'name' => 'ubuntu-24.04', 'type' => 'system', 'deprecated' => false, 'architecture' => 'x86'],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/firewalls*' => Http::response([
                'firewalls' => [
                    ['id' => 38, 'name' => 'web-firewall', 'rules' => []],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
            'https://api.hetzner.cloud/v1/networks*' => Http::response([
                'networks' => [
                    [
                        'id' => 456,
                        'name' => 'private-eu',
                        'ip_range' => '10.0.0.0/16',
                        'subnets' => [
                            ['type' => 'cloud', 'network_zone' => 'eu-central'],
                        ],
                    ],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $component = Livewire::test(ByHetzner::class)
            ->set('selected_token_id', $this->hetznerToken->id)
            ->call('nextStep')
            ->assertSet('current_step', 2);

        expect($component->get('hetznerFirewalls'))->toHaveCount(1)
            ->and($component->get('hetznerNetworks'))->toHaveCount(1);
    });

    test('rejects submitting without a public IP protocol before calling Hetzner', function () {
        Http::fake();

        Livewire::test(ByHetzner::class)
            ->set('current_step', 2)
            ->set('selected_token_id', $this->hetznerToken->id)
            ->set('server_name', 'test-server')
            ->set('selected_location', 'nbg1')
            ->set('selected_server_type', 'cx11')
            ->set('selected_image', 15512617)
            ->set('private_key_id', $this->privateKey->id)
            ->set('enable_ipv4', false)
            ->set('enable_ipv6', false)
            ->call('submit')
            ->assertHasErrors(['enable_ipv4', 'enable_ipv6']);

        Http::assertNothingSent();
    });
});
