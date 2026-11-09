<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'app.maintenance.driver' => 'file',
        'constants.ssh.mux_enabled' => false,
    ]);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
});

function imageScanningApiToken(User $user, Team $team, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'image-scanning-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken, 'Content-Type' => 'application/json'];
}

it('returns the scanner memory limit of the server', function () {
    $this->getJson("/api/v1/servers/{$this->server->uuid}/image-scanning", imageScanningApiToken($this->user, $this->team, ['read']))
        ->assertOk()
        ->assertJsonPath('image_scan_memory_mb', 1024)
        ->assertJsonPath('is_image_scan_enabled', false);
});

it('changes only the scanner memory limit', function () {
    $this->patchJson("/api/v1/servers/{$this->server->uuid}/image-scanning", ['image_scan_memory_mb' => 4096], imageScanningApiToken($this->user, $this->team, ['write']))
        ->assertOk()
        ->assertJsonPath('image_scan_memory_mb', 4096)
        ->assertJsonPath('is_image_scan_enabled', false);

    expect($this->server->settings->fresh()->image_scan_memory_mb)->toBe(4096);
    Queue::assertNothingPushed();
});

it('refuses a memory limit that is not one of the options', function (array $body, string $field) {
    $this->patchJson("/api/v1/servers/{$this->server->uuid}/image-scanning", $body, imageScanningApiToken($this->user, $this->team, ['write']))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect($this->server->settings->fresh()->image_scan_memory_mb)->toBe(1024);
})->with([
    'not an option' => [['image_scan_memory_mb' => 100], 'image_scan_memory_mb'],
    'not a number' => [['image_scan_memory_mb' => 'big'], 'image_scan_memory_mb'],
    'only unknown fields' => [['memory' => 2048], 'is_image_scan_enabled'],
]);

it('does not change the memory limit of a server that another instance manages', function () {
    $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

    $this->patchJson("/api/v1/servers/{$this->server->uuid}/image-scanning", ['image_scan_memory_mb' => 2048], imageScanningApiToken($this->user, $this->team, ['write']))
        ->assertStatus(409);

    expect($this->server->settings->fresh()->image_scan_memory_mb)->toBe(1024);
});

it('does not let a member or another team change the memory limit', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $otherTeam = Team::factory()->create();
    $otherOwner = User::factory()->create();
    $otherTeam->members()->attach($otherOwner->id, ['role' => 'owner']);

    $this->patchJson("/api/v1/servers/{$this->server->uuid}/image-scanning", ['image_scan_memory_mb' => 8192], imageScanningApiToken($member, $this->team, ['write']))
        ->assertForbidden();
    app('auth')->forgetGuards();
    $this->patchJson("/api/v1/servers/{$this->server->uuid}/image-scanning", ['image_scan_memory_mb' => 8192], imageScanningApiToken($otherOwner, $otherTeam, ['write']))
        ->assertNotFound();

    expect($this->server->settings->fresh()->image_scan_memory_mb)->toBe(1024);
});
