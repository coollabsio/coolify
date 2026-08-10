<?php

use App\Livewire\Server\Create;
use App\Livewire\Server\CreatePage;
use App\Livewire\Server\Delete;
use App\Livewire\Server\New\ByHostinger;
use App\Livewire\Server\Show;
use App\Livewire\Server\ValidateAndInstall;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
    ]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hostinger',
        'token' => 'hostinger-token',
        'name' => 'Production Hostinger',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('offers Hostinger as a server provider', function () {
    Livewire::test(Create::class)
        ->assertSee('Hostinger')
        ->assertSee(route('server.create.type', ['type' => 'hostinger']), false);

    Livewire::test(CreatePage::class, ['type' => 'hostinger'])
        ->assertSet('title', 'Hostinger')
        ->assertSet('tokenProvider', 'hostinger');
});

it('uses the current cloud provider UI and Hostinger affiliate link', function () {
    Livewire::test(ByHostinger::class)
        ->assertSee('Hostinger account')
        ->assertSee('https://www.hostinger.com/vps/coolify-hosting?ref=coolify.io', false)
        ->assertSee("through Coolify's affiliate link.", false);

    Livewire::test(ByHostinger::class, ['selectedTokenUuid' => $this->token->uuid])
        ->set('loading_data', false)
        ->assertSee('Hostinger server')
        ->assertSee('Advanced options')
        ->assertSee('Buy and create');
});

it('purchases a Hostinger VPS and creates the linked Coolify server', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/data-centers' => Http::response([
            ['id' => 19, 'name' => 'nl-ams', 'city' => 'Amsterdam', 'location' => 'nl'],
        ]),
        'https://developers.hostinger.com/api/vps/v1/templates' => Http::response([
            ['id' => 1130, 'name' => 'Ubuntu 24.04 LTS'],
        ]),
        'https://developers.hostinger.com/api/billing/v1/catalog*' => Http::response([
            [
                'id' => 'hostingercom-vps-kvm2',
                'name' => 'KVM 2',
                'category' => 'VPS',
                'prices' => [
                    [
                        'id' => 'hostingercom-vps-kvm2-usd-1m',
                        'currency' => 'USD',
                        'price' => 1799,
                        'first_period_price' => 899,
                        'period' => 1,
                        'period_unit' => 'month',
                    ],
                ],
            ],
        ]),
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'order' => ['id' => 2957086, 'status' => 'completed'],
            'virtual_machine' => [
                'id' => 17923,
                'hostname' => 'coolify-hostinger.example.com',
                'state' => 'creating',
                'ipv4' => [['address' => '203.0.113.10']],
            ],
        ]),
    ]);

    Livewire::test(ByHostinger::class, ['selectedTokenUuid' => $this->token->uuid])
        ->call('loadHostingerData')
        ->set('server_name', 'coolify-hostinger.example.com')
        ->set('selected_data_center_id', 19)
        ->set('selected_template_id', 1130)
        ->set('selected_price_id', 'hostingercom-vps-kvm2-usd-1m')
        ->set('private_key_id', $this->privateKey->id)
        ->set('enable_backups', true)
        ->call('submit')
        ->assertHasNoErrors();

    $server = Server::query()->where('hostinger_virtual_machine_id', 17923)->firstOrFail();

    expect($server->name)->toBe('coolify-hostinger.example.com')
        ->and($server->ip)->toBe('203.0.113.10')
        ->and($server->hostinger_virtual_machine_status)->toBe('creating')
        ->and($server->cloud_provider_token_id)->toBe($this->token->id);

    Http::assertSent(fn ($request) => $request->url() === 'https://developers.hostinger.com/api/vps/v1/virtual-machines'
        && $request['item_id'] === 'hostingercom-vps-kvm2-usd-1m'
        && $request['setup']['hostname'] === 'coolify-hostinger.example.com'
        && $request['setup']['public_key']['key'] === $this->privateKey->getPublicKey());
});

it('revalidates the selected Hostinger price before making a purchase', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/data-centers' => Http::response([
            ['id' => 19, 'city' => 'Amsterdam'],
        ]),
        'https://developers.hostinger.com/api/vps/v1/templates' => Http::response([
            ['id' => 1130, 'name' => 'Ubuntu 24.04 LTS'],
        ]),
        'https://developers.hostinger.com/api/billing/v1/catalog*' => Http::response([
            [
                'id' => 'hostingercom-vps-kvm2',
                'name' => 'KVM 2',
                'prices' => [['id' => 'hostingercom-vps-kvm2-usd-1m']],
            ],
        ]),
    ]);

    Livewire::test(ByHostinger::class, ['selectedTokenUuid' => $this->token->uuid])
        ->call('loadHostingerData')
        ->set('server_name', 'coolify-hostinger.example.com')
        ->set('selected_data_center_id', 19)
        ->set('selected_template_id', 1130)
        ->set('selected_price_id', 'tampered-price-id')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasErrors('selected_price_id');

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('starts a stopped Hostinger VPS from the server page', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'cloud_provider_token_id' => $this->token->id,
        'hostinger_virtual_machine_id' => 17923,
        'hostinger_virtual_machine_status' => 'stopped',
    ]);

    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/17923/start' => Http::response([
            'id' => 456,
            'state' => 'starting',
        ]),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->call('startHostingerVirtualMachine')
        ->assertSet('hostingerVirtualMachineStatus', 'starting')
        ->assertDispatched('success');

    expect($server->fresh()->hostinger_virtual_machine_status)->toBe('starting');
});

it('does not validate a stopped Hostinger VPS over SSH', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'cloud_provider_token_id' => $this->token->id,
        'hostinger_virtual_machine_id' => 17923,
        'hostinger_virtual_machine_status' => 'stopped',
    ]);

    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/17923' => Http::response([
            'id' => 17923,
            'state' => 'stopped',
            'ipv4' => [['address' => $server->ip]],
        ]),
    ]);

    Livewire::test(ValidateAndInstall::class, ['server' => $server])
        ->call('validateConnection')
        ->assertSet('error', 'Hostinger VPS is stopped. Power it on before validating.');
});

it('warns that deleting from Coolify does not cancel the Hostinger VPS', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'cloud_provider_token_id' => $this->token->id,
        'hostinger_virtual_machine_id' => 17923,
    ]);

    Livewire::test(Delete::class, ['server_uuid' => $server->uuid])
        ->assertSee('The Hostinger VPS and its subscription will not be deleted or cancelled.');
});
