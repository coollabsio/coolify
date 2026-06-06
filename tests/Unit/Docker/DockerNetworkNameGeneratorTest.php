<?php

use App\Exceptions\DockerNetworkCreationException;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Support\DockerNetworkNameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $this->server = Server::factory()->create(['team_id' => $team->id]);
});

it('generates a name with coolify-net- prefix and 12 random characters', function () {
    $name = generateUniqueDockerNetworkName($this->server);

    expect($name)->toMatch('/^coolify-net-[a-z0-9]{12}$/');
});

it('throws exception when Docker runtime always reports the network exists', function () {
    $executor = fn () => json_encode(['docker_id' => '123']);

    expect(fn () => DockerNetworkNameGenerator::generate($this->server, $executor))
        ->toThrow(DockerNetworkCreationException::class);
});

it('succeeds when executor eventually reports the network is free after retries', function () {
    $callCount = 0;
    $executor = function ($command, $server) use (&$callCount) {
        $callCount++;

        return $callCount <= 3 ? json_encode(['docker_id' => '123']) : null;
    };

    $name = DockerNetworkNameGenerator::generate($this->server, $executor);

    expect($name)->toMatch('/^coolify-net-[a-z0-9]{12}$/')
        ->and($callCount)->toBe(4);
});
