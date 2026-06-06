<?php

use App\Enums\NetworkAttachmentStatus;
use App\Models\DockerNetwork;
use App\Models\NetworkAttachment;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults to unknown status on creation', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $dockerNetwork = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Backend Private Network',
        'docker_network_name' => 'coolify-net-attachment',
    ]);
    $attachment = NetworkAttachment::create([
        'server_id' => $server->id,
        'docker_network_id' => $dockerNetwork->id,
    ]);

    expect($attachment->status)->toBe(NetworkAttachmentStatus::Unknown)
        ->and($attachment->is_managed)->toBeFalse()
        ->and($attachment->is_runtime_discovered)->toBeFalse();
});
