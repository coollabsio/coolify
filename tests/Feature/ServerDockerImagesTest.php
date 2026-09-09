<?php

use App\Livewire\Server\DockerImages;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->withoutVite();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));
});

$nginxId = 'sha256:'.str_repeat('a', 64);
$danglingId = 'sha256:'.str_repeat('b', 64);

$fakeDocker = function (string $nginxId, string $danglingId) {
    Process::fake([
        '*docker image ls*' => Process::result(output: implode("\n", [
            json_encode(['Repository' => 'nginx', 'Tag' => '1.27', 'ID' => $nginxId, 'Size' => '52.5MB', 'CreatedSince' => '3 weeks ago']),
            json_encode(['Repository' => '<none>', 'Tag' => '<none>', 'ID' => $danglingId, 'Size' => '11MB', 'CreatedSince' => '2 days ago']),
        ])),
        '*docker ps -a*' => Process::result(output: "{$nginxId}#/web\n"),
        '*docker system df*' => Process::result(output: json_encode([
            'Type' => 'Images', 'TotalCount' => '2', 'Active' => '1', 'Size' => '63.5MB', 'Reclaimable' => '11MB (17%)',
        ])),
        '*docker image rm*' => Process::result(output: 'Deleted: '.$danglingId),
    ]);
};

it('renders the images page for a server', function () use ($fakeDocker, $nginxId, $danglingId) {
    $fakeDocker($nginxId, $danglingId);

    $this->get(route('server.docker-images', ['server_uuid' => $this->server->uuid]))
        ->assertOk()
        ->assertSee('Images');
});

it('lists images and marks the ones no container uses', function () use ($fakeDocker, $nginxId, $danglingId) {
    $fakeDocker($nginxId, $danglingId);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->assertSet('loaded', true)
        ->assertSet('images.0.repository', 'nginx')
        ->assertSet('images.0.reference', 'nginx:1.27')
        ->assertSet('images.0.short_id', str_repeat('a', 12))
        ->assertSet('images.0.size', '52.5MB')
        ->assertSet('images.0.containers', ['web'])
        ->assertSet('images.1.reference', $danglingId)
        ->assertSet('images.1.dangling', true)
        ->assertSet('images.1.containers', [])
        ->assertSet('usage.size', '63.5MB')
        ->assertSet('usage.reclaimable', '11MB (17%)')
        ->assertSee('Unused')
        ->assertSee('web');
});

it('deletes an unused image', function () use ($fakeDocker, $nginxId, $danglingId) {
    $fakeDocker($nginxId, $danglingId);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->call('delete', $danglingId)
        ->assertDispatched('success');

    Process::assertRan(fn ($process) => str_contains($process->command, "docker image rm '{$danglingId}'"));
});

it('refuses to delete an image that a container is using', function () use ($fakeDocker, $nginxId, $danglingId) {
    $fakeDocker($nginxId, $danglingId);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->call('delete', 'nginx:1.27')
        ->assertDispatched('error');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker image rm'));
});

it('filters images by the search term', function () use ($fakeDocker, $nginxId, $danglingId) {
    $fakeDocker($nginxId, $danglingId);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->set('search', 'nginx')
        ->assertSee('1.27')
        ->assertDontSee(str_repeat('b', 12));
});
