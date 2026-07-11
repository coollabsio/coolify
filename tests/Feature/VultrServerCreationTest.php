<?php

use App\Livewire\Server\New\ByVultr;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->vultrToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'vultr',
        'token' => 'test-vultr-token',
        'name' => 'Test Vultr Token',
    ]);

    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Private Key',
        'description' => 'Test private key',
        'private_key' => vultrLivewireTestPrivateKey(),
    ]);
});

function vultrLivewireTestPrivateKey(): string
{
    return <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
}

function vultrLivewireTestPublicKey(): string
{
    return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68';
}

it('creates a Vultr server through the Livewire flow', function () {
    Http::fake([
        'https://api.vultr.com/v2/regions*' => Http::response([
            'regions' => [
                ['id' => 'ewr', 'city' => 'New Jersey', 'country' => 'US'],
            ],
            'meta' => ['links' => ['next' => null]],
        ], 200),
        'https://api.vultr.com/v2/plans*' => Http::response([
            'plans' => [
                ['id' => 'vc2-1c-1gb', 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 25, 'monthly_cost' => 6, 'locations' => ['ewr']],
            ],
            'meta' => ['links' => ['next' => null]],
        ], 200),
        'https://api.vultr.com/v2/os*' => Http::response([
            'os' => [
                ['id' => 2284, 'name' => 'Ubuntu 24.04 LTS x64'],
            ],
            'meta' => ['links' => ['next' => null]],
        ], 200),
        'https://api.vultr.com/v2/ssh-keys' => Http::response([
            'ssh_key' => ['id' => 'key-1', 'ssh_key' => vultrLivewireTestPublicKey()],
        ], 201),
        'https://api.vultr.com/v2/ssh-keys*' => Http::response([
            'ssh_keys' => [],
            'meta' => ['links' => ['next' => null]],
        ], 200),
        'https://api.vultr.com/v2/instances' => Http::response([
            'instance' => [
                'id' => 'instance-1',
                'label' => 'test-vultr-server',
                'main_ip' => '1.2.3.4',
                'v6_main_ip' => '2001:db8::1',
                'status' => 'pending',
            ],
        ], 202),
    ]);

    Livewire::test(ByVultr::class, ['selectedTokenUuid' => $this->vultrToken->uuid])
        ->assertSet('current_step', 2)
        ->set('server_name', 'test-vultr-server')
        ->set('selected_region', 'ewr')
        ->set('selected_plan', 'vc2-1c-1gb')
        ->set('selected_os_id', 2284)
        ->set('private_key_id', $this->privateKey->id)
        ->set('enable_ipv6', true)
        ->set('cloud_init_script', "#cloud-config\npackages:\n  - curl")
        ->call('submit');

    $this->assertDatabaseHas('servers', [
        'name' => 'test-vultr-server',
        'ip' => '1.2.3.4',
        'team_id' => $this->team->id,
        'cloud_provider_token_id' => $this->vultrToken->id,
        'vultr_instance_id' => 'instance-1',
        'vultr_instance_status' => 'pending',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.vultr.com/v2/instances'
            && $request['region'] === 'ewr'
            && $request['plan'] === 'vc2-1c-1gb'
            && $request['os_id'] === 2284
            && $request['sshkey_id'] === ['key-1']
            && $request['user_data'] === base64_encode("#cloud-config\npackages:\n  - curl");
    });
});

it('requires IPv6 when public IPv4 is disabled', function () {
    Livewire::test(ByVultr::class)
        ->set('selected_token_id', $this->vultrToken->id)
        ->set('current_step', 2)
        ->set('server_name', 'test-vultr-server')
        ->set('selected_region', 'ewr')
        ->set('selected_plan', 'vc2-1c-1gb')
        ->set('selected_os_id', 2284)
        ->set('private_key_id', $this->privateKey->id)
        ->set('enable_ipv6', false)
        ->set('disable_public_ipv4', true)
        ->call('submit')
        ->assertHasErrors(['enable_ipv6']);
});

it('uses the shared dropdown UI for advanced Vultr options', function () {
    Livewire::test(ByVultr::class)
        ->set('current_step', 2)
        ->assertSee('Advanced Vultr options')
        ->assertSeeHtml('dropdownOpen')
        ->assertSeeHtml('x-ref="panel"')
        ->assertSee('Additional SSH Keys (from Vultr)')
        ->assertSee('Network Configuration')
        ->assertSee('Cloud-Init Script');
});

it('renders only the full width buy button at the bottom of the Vultr form', function () {
    Livewire::test(ByVultr::class)
        ->set('current_step', 2)
        ->assertDontSee('wire:click="previousStep"', false)
        ->assertSeeHtml('class="button w-full"')
        ->assertSee('Buy & Create Server', false);
});
