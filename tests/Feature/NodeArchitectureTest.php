<?php

use App\Enums\NodeRole;
use App\Models\Node;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores nodes separately from legacy servers', function () {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $node = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'role' => NodeRole::WORKER,
    ]);

    expect(Schema::hasTable('nodes'))->toBeTrue()
        ->and(Schema::hasColumn('servers', 'mode'))->toBeFalse()
        ->and($node->role)->toBe(NodeRole::WORKER)
        ->and($node->team->is($team))->toBeTrue()
        ->and($node->privateKey->is($privateKey))->toBeTrue()
        ->and(Server::query()->where('uuid', $node->uuid)->exists())->toBeFalse();
});

it('creates a node-bound Sentinel token', function () {
    $node = Node::factory()->create();

    $token = $node->ensureValidSentinelToken();
    $payload = json_decode(decrypt($token), true);

    expect($payload)->toBe(['node_uuid' => $node->uuid])
        ->and($node->fresh()->sentinel_token)->toBe($token);
});
