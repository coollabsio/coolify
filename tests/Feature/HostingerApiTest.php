<?php

use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    InstanceSettings::query()->whereKey(0)->delete();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;
    $this->hostingerToken = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hostinger',
        'token' => 'hostinger-api-token',
    ]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

it('gets Hostinger data centers', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/data-centers' => Http::response([
            ['id' => 19, 'city' => 'Amsterdam', 'location' => 'nl'],
        ]),
    ]);

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/data-centers?cloud_provider_token_id='.$this->hostingerToken->uuid)
        ->assertSuccessful()
        ->assertJsonFragment(['id' => 19, 'city' => 'Amsterdam']);
});

it('gets Hostinger SSH keys and post-install scripts', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/public-keys' => Http::response([
            'data' => [['id' => 42, 'name' => 'Operations']],
        ]),
        'https://developers.hostinger.com/api/vps/v1/post-install-scripts' => Http::response([
            'data' => [['id' => 73, 'name' => 'Bootstrap Coolify']],
        ]),
    ]);

    $query = '?cloud_provider_token_id='.$this->hostingerToken->uuid;

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/ssh-keys'.$query)
        ->assertSuccessful()
        ->assertJsonFragment(['id' => 42, 'name' => 'Operations']);

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/post-install-scripts'.$query)
        ->assertSuccessful()
        ->assertJsonFragment(['id' => 73, 'name' => 'Bootstrap Coolify']);
});

it('gets only Hostinger KVM plans through the API', function () {
    Http::fake([
        'https://developers.hostinger.com/api/billing/v1/catalog*' => Http::response([
            ['id' => 'hostingercom-vps-kvm1', 'name' => 'KVM 1', 'prices' => []],
            ['id' => 'hostingercom-vps-kvmminecraftalex', 'name' => 'Game Panel 1', 'prices' => []],
        ]),
    ]);

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/catalog?cloud_provider_token_id='.$this->hostingerToken->uuid)
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => 'hostingercom-vps-kvm1'])
        ->assertJsonMissing(['id' => 'hostingercom-vps-kvmminecraftalex']);
});

it('returns 403 with the Hostinger message when Hostinger denies a provider list', function (int $hostingerStatus) {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/post-install-scripts' => Http::response([
            'message' => '[VPS:2000] Unauthorized',
        ], $hostingerStatus),
    ]);

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/post-install-scripts?cloud_provider_token_id='.$this->hostingerToken->uuid)
        ->assertForbidden()
        ->assertExactJson(['message' => 'Hostinger denied access to post-install scripts: [VPS:2000] Unauthorized']);
})->with([401, 403]);

it('returns 429 when Hostinger rate limits a provider list', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/templates' => Http::response([
            'message' => 'Too many requests.',
        ], 429, ['Retry-After' => '30']),
    ]);

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/templates?cloud_provider_token_id='.$this->hostingerToken->uuid)
        ->assertStatus(429)
        ->assertHeader('Retry-After', '30')
        ->assertExactJson(['message' => 'Rate limit exceeded. Please try again later.']);
});

it('returns a generic error when a Hostinger provider list fails', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/data-centers' => Http::response([
            'message' => 'Invalid request',
        ], 422),
    ]);

    $this->withToken($this->bearerToken)
        ->getJson('/api/v1/hostinger/data-centers?cloud_provider_token_id='.$this->hostingerToken->uuid)
        ->assertServerError()
        ->assertExactJson(['message' => 'Failed to fetch Hostinger data centers.']);
});

it('creates a Hostinger VPS server through the API', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'order' => ['id' => 2957086, 'status' => 'completed'],
            'virtual_machine' => [
                'id' => 17923,
                'state' => 'creating',
                'ipv4' => [['address' => '203.0.113.10']],
            ],
        ]),
    ]);

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/servers/hostinger', [
            'cloud_provider_token_id' => $this->hostingerToken->uuid,
            'item_id' => 'hostingercom-vps-kvm2-usd-1m',
            'data_center_id' => 19,
            'template_id' => 1130,
            'name' => 'api-hostinger.example.com',
            'private_key_uuid' => $this->privateKey->uuid,
            'enable_backups' => true,
        ])
        ->assertCreated()
        ->assertJsonFragment([
            'hostinger_virtual_machine_id' => 17923,
            'ip' => '203.0.113.10',
        ]);

    $this->assertDatabaseHas('servers', [
        'team_id' => $this->team->id,
        'hostinger_virtual_machine_id' => 17923,
        'ip' => '203.0.113.10',
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://developers.hostinger.com/api/vps/v1/virtual-machines'
        && $request['setup']['enable_backups'] === true);
});

it('turns paid Hostinger backups off when the API request does not enable them', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'virtual_machine' => [
                'id' => 17923,
                'state' => 'creating',
                'ipv4' => [['address' => '203.0.113.10']],
            ],
        ]),
    ]);

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/servers/hostinger', [
            'cloud_provider_token_uuid' => $this->hostingerToken->uuid,
            'item_id' => 'hostingercom-vps-kvm1-usd-1m',
            'data_center_id' => 19,
            'template_id' => 1130,
            'private_key_uuid' => $this->privateKey->uuid,
        ])
        ->assertCreated();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://developers.hostinger.com/api/vps/v1/virtual-machines'
        && $request['setup']['enable_backups'] === false);
});

it('keeps a charged Hostinger VPS when the purchase returns an error through the API', function () {
    $listRequests = 0;
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/2008976/setup' => Http::response([
            'id' => 2008976,
            'state' => 'creating',
            'ipv4' => [['address' => '72.61.180.163']],
        ]),
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => function ($request) use (&$listRequests) {
            if ($request->method() === 'POST') {
                return Http::response(['message' => '[VPS:2004] Wrong hostname FQDN format'], 422);
            }

            return Http::response($listRequests++ === 0 ? [] : [['id' => 2008976, 'state' => 'initial']]);
        },
    ]);

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/servers/hostinger', [
            'cloud_provider_token_id' => $this->hostingerToken->uuid,
            'item_id' => 'hostingercom-vps-kvm1-usd-1m',
            'data_center_id' => 19,
            'template_id' => 1077,
            'private_key_uuid' => $this->privateKey->uuid,
        ])
        ->assertCreated()
        ->assertJsonFragment([
            'hostinger_virtual_machine_id' => 2008976,
            'ip' => '72.61.180.163',
        ]);

    $this->assertDatabaseHas('servers', [
        'team_id' => $this->team->id,
        'hostinger_virtual_machine_id' => 2008976,
        'ip' => '72.61.180.163',
    ]);
});

it('reports a Hostinger order that is waiting for payment through the API', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'id' => 2957086,
            'status' => 'payment_initiated',
        ], 202),
    ]);

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/servers/hostinger', [
            'cloud_provider_token_id' => $this->hostingerToken->uuid,
            'item_id' => 'hostingercom-vps-kvm2-usd-1m',
            'data_center_id' => 19,
            'template_id' => 1130,
            'private_key_uuid' => $this->privateKey->uuid,
        ])
        ->assertStatus(202)
        ->assertJsonFragment(['message' => 'Hostinger order 2957086 is waiting for payment. Finish the VPS setup in hPanel.']);

    $this->assertDatabaseMissing('servers', ['team_id' => $this->team->id]);
});

it('accepts Hostinger cloud provider tokens through the API', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([]),
    ]);

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/cloud-tokens', [
            'provider' => 'hostinger',
            'token' => 'another-hostinger-token',
            'name' => 'Another Hostinger Token',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('cloud_provider_tokens', [
        'team_id' => $this->team->id,
        'provider' => 'hostinger',
        'name' => 'Another Hostinger Token',
    ]);
});
