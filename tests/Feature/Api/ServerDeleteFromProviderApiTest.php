<?php

use App\Actions\Server\DeleteServer;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $newToken = $this->user->createToken('write-token', ['write']);
    $newToken->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->token = $newToken->plainTextToken;
});

function deleteServerViaApi(string $token, string $uuid, string $query = '')
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$token])
        ->deleteJson("/api/v1/servers/{$uuid}{$query}");
}

it('does not delete from the cloud provider by default', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'hetzner_server_id' => 123]);

    deleteServerViaApi($this->token, $server->uuid)->assertOk();

    DeleteServer::assertPushed(fn ($action, array $params) => $params[0] === $server->id
        && $params[1] === false
        && $params[5] === false
        && $params[7] === false);
});

it('deletes from the linked cloud provider when delete_from_provider is true', function (array $attributes) {
    $server = Server::factory()->create(['team_id' => $this->team->id, ...$attributes]);

    deleteServerViaApi($this->token, $server->uuid, '?delete_from_provider=true')->assertOk();

    DeleteServer::assertPushed(fn ($action, array $params) => $params[0] === $server->id
        && $params[1] === true
        && $params[5] === true
        && $params[7] === true);
})->with([
    'hetzner' => [['hetzner_server_id' => 123]],
    'vultr' => [['vultr_instance_id' => 'vultr-instance-id']],
    'digitalocean' => [['digitalocean_droplet_id' => 456]],
]);

it('rejects delete_from_provider when the server has no cloud provider link', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    deleteServerViaApi($this->token, $server->uuid, '?delete_from_provider=true')
        ->assertStatus(422)
        ->assertJson(['message' => 'Server is not linked to a cloud provider.']);

    expect(Server::find($server->id))->not->toBeNull();
    DeleteServer::assertNotPushed();
});

it('does not delete a server of another team from the cloud provider', function () {
    $otherServer = Server::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'hetzner_server_id' => 123,
    ]);

    deleteServerViaApi($this->token, $otherServer->uuid, '?delete_from_provider=true')->assertNotFound();

    expect(Server::find($otherServer->id))->not->toBeNull();
    DeleteServer::assertNotPushed();
});
