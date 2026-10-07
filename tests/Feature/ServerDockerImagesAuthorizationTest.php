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
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    Process::fake([
        '*docker image ls*' => Process::result(output: json_encode(['Repository' => 'nginx', 'Tag' => '1.27', 'ID' => 'sha256:'.str_repeat('a', 64), 'Size' => '1MB', 'CreatedSince' => 'now'])),
        '*' => Process::result(output: ''),
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

it('does not list images for a user removed from the server team after mount', function () {
    $component = Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid]);

    $this->team->members()->detach($this->user->id);
    $this->user->refresh();

    $component->call('load')
        ->assertForbidden();

    Process::assertNothingRan();
});

it('still lists images for a member of the server team', function () {
    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->assertSet('images.0.reference', 'nginx:1.27');
});
