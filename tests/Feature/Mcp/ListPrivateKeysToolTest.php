<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mcpKeysRsaPem(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return $pem;
}

beforeEach(function () {
    config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'app.maintenance.driver' => 'file']);
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
});

function mcpKeysCall(array $abilities)
{
    auth()->forgetGuards();
    $token = test()->user->createToken('mcp-keys', $abilities)->plainTextToken;

    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'list_private_keys', 'arguments' => (object) []],
    ]);
}

test('list_private_keys returns team keys without key material', function () {
    $mine = PrivateKey::factory()->create(['team_id' => $this->team->id, 'name' => 'deploy-key', 'is_git_related' => true]);
    PrivateKey::factory()->create(['team_id' => Team::factory()->create()->id, 'name' => 'other-team-key', 'private_key' => mcpKeysRsaPem()]);

    $response = mcpKeysCall(['read']);

    $response->assertOk();
    $text = $response->json('result.content.0.text');
    $body = json_decode($text, true);
    expect(collect($body['data'])->pluck('uuid')->all())->toBe([$mine->uuid])
        ->and($body['data'][0])->toMatchArray(['name' => 'deploy-key', 'is_git_related' => true])
        ->and($text)->not->toContain('BEGIN OPENSSH')
        ->and($text)->not->toContain('private_key')
        ->and($text)->not->toContain('fingerprint');
});
