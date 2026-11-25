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

it('returns and changes the Syft and Grype time limits and the CPU limit', function () {
    $this->getJson("/api/v1/servers/{$this->server->uuid}/image-scanning", imageScanningApiToken($this->user, $this->team, ['read']))
        ->assertOk()
        ->assertJsonPath('image_scan_syft_timeout', 600)
        ->assertJsonPath('image_scan_grype_timeout', 600)
        ->assertJsonPath('image_scan_cpus', 1);
    app('auth')->forgetGuards();

    $this->patchJson("/api/v1/servers/{$this->server->uuid}/image-scanning", ['image_scan_syft_timeout' => 1800, 'image_scan_grype_timeout' => 1200, 'image_scan_cpus' => 0.5], imageScanningApiToken($this->user, $this->team, ['write']))
        ->assertOk()
        ->assertJsonPath('image_scan_syft_timeout', 1800)
        ->assertJsonPath('image_scan_grype_timeout', 1200)
        ->assertJsonPath('image_scan_cpus', 0.5)
        ->assertJsonPath('image_scan_memory_mb', 1024);

    $settings = $this->server->settings->fresh();
    expect($settings->image_scan_syft_timeout)->toBe(1800)
        ->and($settings->image_scan_grype_timeout)->toBe(1200)
        ->and($settings->image_scan_cpus)->toBe(0.5);
    Queue::assertNothingPushed();
});

it('refuses time and CPU limits that are not one of the options', function (array $body, string $field) {
    $this->patchJson("/api/v1/servers/{$this->server->uuid}/image-scanning", $body, imageScanningApiToken($this->user, $this->team, ['write']))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect($this->server->settings->fresh()->image_scan_grype_timeout)->toBe(600);
})->with([
    'syft time limit' => [['image_scan_syft_timeout' => 45], 'image_scan_syft_timeout'],
    'grype time limit' => [['image_scan_grype_timeout' => 7200], 'image_scan_grype_timeout'],
    'cpus' => [['image_scan_cpus' => 3], 'image_scan_cpus'],
]);

it('refuses memory and CPU limits above what the server has', function (array $body, string $field, string $message) {
    $this->server->forceFill(['server_metadata' => ['memory_bytes' => 2 * 1024 ** 3, 'cpus' => 2]])->saveQuietly();

    $this->patchJson("/api/v1/servers/{$this->server->uuid}/image-scanning", $body, imageScanningApiToken($this->user, $this->team, ['write']))
        ->assertStatus(422)
        ->assertJsonPath("errors.{$field}.0", $message);

    expect($this->server->settings->fresh()->image_scan_memory_mb)->toBe(1024);
})->with([
    'memory' => [['image_scan_memory_mb' => 4096], 'image_scan_memory_mb', 'This server allows: 1024, 2048. A higher limit is more than the memory of the server.'],
    'cpus' => [['image_scan_cpus' => 4], 'image_scan_cpus', 'This server allows: 0.5, 1, 2. A higher limit is more than the CPU count of the server.'],
]);
