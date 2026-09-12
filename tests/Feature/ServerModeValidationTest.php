<?php

use App\Actions\Node\ValidateNode;
use App\Enums\NodeRole;
use App\Jobs\ServerConnectionCheckJob;
use App\Models\Node;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
});

it('keeps legacy servers and nodes as separate permanent types', function () {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $node = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $server->private_key_id,
        'role' => NodeRole::WORKER,
    ]);

    expect(Schema::hasColumn('servers', 'mode'))->toBeFalse()
        ->and($server)->toBeInstanceOf(Server::class)
        ->and($node)->toBeInstanceOf(Node::class)
        ->and($node->role)->toBe(NodeRole::WORKER);
});

it('uses Docker for legacy server connection checks', function () {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    Process::fake(['*' => Process::result(output: '{"Server":{"Version":"27.0.0"}}')]);

    (new ServerConnectionCheckJob($server, disableMux: false))->handle();

    expect($server->settings->fresh()->is_usable)->toBeTrue();
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker version --format json'));
});

it('validates Podman only through the node action', function () {
    $node = Node::factory()->create();
    Process::fake(['*' => Process::result(output: '{"host":{"arch":"amd64"}}')]);

    expect(ValidateNode::run($node))->toBeTrue()
        ->and($node->fresh()->is_reachable)->toBeTrue()
        ->and($node->fresh()->is_usable)->toBeTrue();
    Process::assertRan(fn ($process) => str_contains($process->command, 'podman info --format json'));
});
