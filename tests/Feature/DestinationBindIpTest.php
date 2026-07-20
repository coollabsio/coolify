<?php

use App\Jobs\RestartProxyJob;
use App\Livewire\Destination\New\Docker as NewDocker;
use App\Livewire\Destination\Show as DestinationShow;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.10',
    ]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'dest-'.fake()->unique()->word(),
        'network' => 'coolify-'.fake()->unique()->word(),
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('Destination Show bind_ip', function () {
    test('saves a valid bind_ip and dispatches RestartProxyJob', function () {
        Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destination->uuid])
            ->set('bindIp', '192.168.1.50')
            ->call('submit');

        expect($this->destination->fresh()->bind_ip)->toBe('192.168.1.50');
        Queue::assertPushed(RestartProxyJob::class);
    });

    test('saves bind_ip as null when cleared and triggers proxy restart', function () {
        $this->destination->update(['bind_ip' => '192.168.1.50']);

        Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destination->uuid])
            ->set('bindIp', '')
            ->call('submit');

        expect($this->destination->fresh()->bind_ip)->toBeNull();
        Queue::assertPushed(RestartProxyJob::class);
    });

    test('does not dispatch RestartProxyJob when bind_ip is unchanged', function () {
        $this->destination->update(['bind_ip' => '192.168.1.50']);

        Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destination->uuid])
            ->set('bindIp', '192.168.1.50')
            ->call('submit');

        Queue::assertNotPushed(RestartProxyJob::class);
    });

    test('rejects bind_ip equal to server ip', function () {
        Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destination->uuid])
            ->set('bindIp', $this->server->ip)
            ->call('submit')
            ->assertDispatched('error');

        expect($this->destination->fresh()->bind_ip)->toBeNull();
        Queue::assertNotPushed(RestartProxyJob::class);
    });

    test('rejects bind_ip already used by another destination on the same server', function () {
        StandaloneDocker::factory()->create([
            'server_id' => $this->server->id,
            'name' => 'other-'.fake()->unique()->word(),
            'network' => 'coolify-other-'.fake()->unique()->word(),
            'bind_ip' => '10.0.0.5',
        ]);

        Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destination->uuid])
            ->set('bindIp', '10.0.0.5')
            ->call('submit')
            ->assertDispatched('error');

        expect($this->destination->fresh()->bind_ip)->toBeNull();
    });

    test('rejects a malformed bind_ip', function () {
        Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destination->uuid])
            ->set('bindIp', 'not-an-ip!!!')
            ->call('submit')
            ->assertDispatched('error');

        expect($this->destination->fresh()->bind_ip)->toBeNull();
    });
});

describe('Destination New/Docker bind_ip', function () {
    test('creates destination with bind_ip and dispatches RestartProxyJob', function () {
        Livewire::test(NewDocker::class, ['server_id' => (string) $this->server->id])
            ->set('name', 'new-dest')
            ->set('network', 'coolify-new-dest')
            ->set('bindIp', '192.168.1.77')
            ->call('submit');

        $created = StandaloneDocker::where('network', 'coolify-new-dest')->first();
        expect($created)->not->toBeNull();
        expect($created->bind_ip)->toBe('192.168.1.77');
        Queue::assertPushed(RestartProxyJob::class);
    });

    test('creates destination without bind_ip and does not dispatch RestartProxyJob', function () {
        Livewire::test(NewDocker::class, ['server_id' => (string) $this->server->id])
            ->set('name', 'new-dest-plain')
            ->set('network', 'coolify-new-dest-plain')
            ->call('submit');

        $created = StandaloneDocker::where('network', 'coolify-new-dest-plain')->first();
        expect($created)->not->toBeNull();
        expect($created->bind_ip)->toBeNull();
        Queue::assertNotPushed(RestartProxyJob::class);
    });

    test('rejects bind_ip equal to server ip on creation', function () {
        Livewire::test(NewDocker::class, ['server_id' => (string) $this->server->id])
            ->set('name', 'rejected-dest')
            ->set('network', 'coolify-rejected-dest')
            ->set('bindIp', $this->server->ip)
            ->call('submit')
            ->assertDispatched('error');

        expect(StandaloneDocker::where('network', 'coolify-rejected-dest')->exists())->toBeFalse();
        Queue::assertNotPushed(RestartProxyJob::class);
    });

    test('rejects duplicate bind_ip on the same server', function () {
        StandaloneDocker::factory()->create([
            'server_id' => $this->server->id,
            'name' => 'first-dest',
            'network' => 'coolify-first',
            'bind_ip' => '10.0.0.99',
        ]);

        Livewire::test(NewDocker::class, ['server_id' => (string) $this->server->id])
            ->set('name', 'second-dest')
            ->set('network', 'coolify-second')
            ->set('bindIp', '10.0.0.99')
            ->call('submit')
            ->assertDispatched('error');

        expect(StandaloneDocker::where('network', 'coolify-second')->exists())->toBeFalse();
    });
});
