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
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

$latestId = 'sha256:'.str_repeat('a', 64);
$oldId = 'sha256:'.str_repeat('b', 64);
$danglingId = 'sha256:'.str_repeat('c', 64);

$fakeDocker = function () use ($latestId, $oldId, $danglingId) {
    Process::fake([
        '*docker image ls*' => Process::result(output: implode("\n", [
            json_encode(['Repository' => 'nginx', 'Tag' => 'latest', 'ID' => $latestId, 'Size' => '52.5MB', 'CreatedSince' => '3 weeks ago']),
            json_encode(['Repository' => 'nginx', 'Tag' => '1.27', 'ID' => $oldId, 'Size' => '52.2MB', 'CreatedSince' => '2 months ago']),
            json_encode(['Repository' => '<none>', 'Tag' => '<none>', 'ID' => $danglingId, 'Size' => '11MB', 'CreatedSince' => '2 days ago']),
        ])),
        '*docker ps -a*' => Process::result(output: implode("\n", [
            json_encode(['Image' => 'nginx', 'Names' => '/coolify-web']),
            json_encode(['Image' => $danglingId, 'Names' => '/oneoff-worker']),
        ])),
        '*docker rmi*' => Process::result(output: 'Deleted'),
    ]);
};

it('renders the docker images page for a server', function () use ($fakeDocker) {
    $fakeDocker();

    $this->get(route('server.docker-images', ['server_uuid' => $this->server->uuid]))
        ->assertOk()
        ->assertSee('Docker images');
});

it('lists images and marks the ones no container uses', function () use ($fakeDocker, $danglingId) {
    $fakeDocker();

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->assertSet('loaded', true)
        ->assertSet('images.0.repository', 'nginx')
        ->assertSet('images.0.reference', 'nginx:latest')
        ->assertSet('images.0.usedBy', ['coolify-web'])
        ->assertSet('images.1.reference', 'nginx:1.27')
        ->assertSet('images.1.usedBy', [])
        ->assertSet('images.2.reference', $danglingId)
        ->assertSet('images.2.dangling', true)
        ->assertSee('Unused');
});

it('matches a container created without an explicit tag and one pinned to an image ID', function () use ($fakeDocker) {
    $fakeDocker();

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->assertSet('images.0.usedBy', ['coolify-web'])
        ->assertSet('images.1.usedBy', [])
        ->assertSet('images.2.usedBy', ['oneoff-worker']);
});

it('deletes an unused image', function () use ($fakeDocker, $oldId) {
    $fakeDocker();

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->call('delete', 'nginx:1.27')
        ->assertDispatched('success');

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker rmi') && str_contains($process->command, 'nginx:1.27'));
});

it('refuses to delete an image that a container is using', function () use ($fakeDocker) {
    $fakeDocker();

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->call('delete', 'nginx:latest')
        ->assertDispatched('error');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker rmi'));
});

it('refuses to delete an image reference that is not listed', function () use ($fakeDocker) {
    $fakeDocker();

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->call('delete', 'alpine:latest')
        ->assertDispatched('error');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker rmi'));
});

it('deletes all unused images at once', function () use ($fakeDocker, $danglingId) {
    $fakeDocker();

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->call('deleteUnused')
        ->assertDispatched('success');

    Process::assertRan(function ($process) use ($danglingId) {
        return str_contains($process->command, 'docker rmi')
            && str_contains($process->command, 'nginx:1.27')
            && ! str_contains($process->command, 'nginx:latest')
            && ! str_contains($process->command, $danglingId);
    });
});

it('filters images by the search term', function () use ($fakeDocker) {
    $fakeDocker();

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->set('search', '1.27')
        ->assertSee(str_repeat('b', 12))
        ->assertDontSee(str_repeat('a', 12));
});

it('redirects users of another team away from the page', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->assertRedirect(route('server.index'));
});
