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
    fakeRemoteProcesses([
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

    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "docker image rm '{$danglingId}'"));
});

it('refuses to delete an image that a container is using', function () use ($fakeDocker, $nginxId, $danglingId) {
    $fakeDocker($nginxId, $danglingId);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->call('delete', 'nginx:1.27')
        ->assertDispatched('error');

    Process::assertNotRan(fn ($process) => str_contains(remoteProcessScript($process), 'docker image rm'));
});

it('filters images by the search term', function () use ($fakeDocker, $nginxId, $danglingId) {
    $fakeDocker($nginxId, $danglingId);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->set('search', 'nginx')
        ->assertSee('1.27')
        ->assertDontSee(str_repeat('b', 12));
});

it('paginates images and returns to the first page when the search changes', function () {
    fakeRemoteProcesses([
        '*docker image ls*' => Process::result(output: collect(range(1, 12))
            ->map(fn (int $i) => json_encode([
                'Repository' => sprintf('app-%02d', $i), 'Tag' => 'latest', 'ID' => 'sha256:'.str_repeat(dechex($i), 64),
                'Size' => '1MB', 'CreatedSince' => '1 day ago',
            ]))
            ->implode("\n")),
        '*docker ps -a*' => Process::result(output: ''),
        '*docker system df*' => Process::result(output: ''),
    ]);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->assertSee('app-10')
        ->assertDontSee('app-11')
        ->assertSee('1–10 of 12')
        ->call('nextPage')
        ->assertSee('app-12')
        ->assertDontSee('app-01')
        ->assertSee('11–12 of 12')
        ->set('search', 'app-0')
        ->assertSee('app-01')
        ->assertSee('1–9 of 9')
        ->set('perPage', 5)
        ->assertSee('1–5 of 9')
        ->assertDontSee('app-06');
});

it('sorts images by the chosen column and ignores unknown sort values', function () {
    fakeRemoteProcesses([
        '*docker image ls*' => Process::result(output: implode("\n", [
            json_encode(['Repository' => 'beta', 'Tag' => 'latest', 'ID' => 'sha256:'.str_repeat('1', 64), 'Size' => '9MB', 'CreatedAt' => '2024-03-01 10:00:00 +0000 UTC', 'CreatedSince' => '7 months ago']),
            json_encode(['Repository' => 'alpha', 'Tag' => 'latest', 'ID' => 'sha256:'.str_repeat('2', 64), 'Size' => '100MB', 'CreatedAt' => '2024-01-01 10:00:00 +0000 UTC', 'CreatedSince' => '9 months ago']),
            json_encode(['Repository' => 'gamma', 'Tag' => 'latest', 'ID' => 'sha256:'.str_repeat('3', 64), 'Size' => '1.2GB', 'CreatedAt' => '2024-02-01 10:00:00 +0000 UTC', 'CreatedSince' => '8 months ago']),
        ])),
        '*docker ps -a*' => Process::result(output: ''),
        '*docker system df*' => Process::result(output: ''),
    ]);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->assertSeeInOrder(['beta', 'alpha', 'gamma'])
        ->call('setSort', 'repository_asc')
        ->assertSet('sort', 'repository_asc')
        ->assertSeeInOrder(['alpha', 'beta', 'gamma'])
        ->call('setSort', 'size_asc')
        ->assertSeeInOrder(['9MB', '100MB', '1.2GB'])
        ->call('setSort', 'size_desc')
        ->assertSeeInOrder(['1.2GB', '100MB', '9MB'])
        ->call('setSort', 'created_desc')
        ->assertSeeInOrder(['beta', 'gamma', 'alpha'])
        ->call('setSort', 'containers_desc')
        ->assertSet('sort', 'created_desc')
        ->assertSeeInOrder(['beta', 'gamma', 'alpha']);
});

it('returns to the first page when the sort changes', function () {
    fakeRemoteProcesses([
        '*docker image ls*' => Process::result(output: collect(range(1, 12))
            ->map(fn (int $i) => json_encode([
                'Repository' => sprintf('app-%02d', $i), 'Tag' => 'latest', 'ID' => 'sha256:'.str_repeat(dechex($i), 64),
                'Size' => '1MB', 'CreatedSince' => '1 day ago',
            ]))
            ->implode("\n")),
        '*docker ps -a*' => Process::result(output: ''),
        '*docker system df*' => Process::result(output: ''),
    ]);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->call('nextPage')
        ->call('setSort', 'repository_desc')
        ->assertSee('1–10 of 12')
        ->assertSee('app-12')
        ->assertDontSee('app-02');
});
