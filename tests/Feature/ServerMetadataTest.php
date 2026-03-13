<?php

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = Team::factory()->create();
    $user->teams()->attach($this->team);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

it('casts server_metadata as array', function () {
    $metadata = [
        'os' => 'Ubuntu 22.04.3 LTS',
        'arch' => 'x86_64',
        'kernel' => '5.15.0-91-generic',
        'cpus' => 4,
        'memory_bytes' => 8589934592,
        'uptime_since' => '2024-01-15 10:30:00',
        'collected_at' => now()->toIso8601String(),
    ];

    $this->server->update(['server_metadata' => $metadata]);
    $this->server->refresh();

    expect($this->server->server_metadata)->toBeArray()
        ->and($this->server->server_metadata['os'])->toBe('Ubuntu 22.04.3 LTS')
        ->and($this->server->server_metadata['cpus'])->toBe(4)
        ->and($this->server->server_metadata['memory_bytes'])->toBe(8589934592);
});

it('stores null server_metadata by default', function () {
    expect($this->server->server_metadata)->toBeNull();
});

it('includes server_metadata in fillable', function () {
    $this->server->fill(['server_metadata' => ['os' => 'Test']]);

    expect($this->server->server_metadata)->toBe(['os' => 'Test']);
});

it('persists and retrieves full server metadata structure', function () {
    $metadata = [
        'os' => 'Debian GNU/Linux 12 (bookworm)',
        'arch' => 'aarch64',
        'kernel' => '6.1.0-17-arm64',
        'cpus' => 8,
        'memory_bytes' => 17179869184,
        'uptime_since' => '2024-03-01 08:00:00',
        'collected_at' => '2024-03-10T12:00:00+00:00',
    ];

    $this->server->update(['server_metadata' => $metadata]);
    $this->server->refresh();

    expect($this->server->server_metadata)
        ->toHaveKeys(['os', 'arch', 'kernel', 'cpus', 'memory_bytes', 'uptime_since', 'collected_at'])
        ->and($this->server->server_metadata['os'])->toBe('Debian GNU/Linux 12 (bookworm)')
        ->and($this->server->server_metadata['arch'])->toBe('aarch64')
        ->and($this->server->server_metadata['cpus'])->toBe(8)
        ->and(round($this->server->server_metadata['memory_bytes'] / 1073741824, 1))->toBe(16.0);
});

it('returns null from gatherServerMetadata when server is not functional', function () {
    $this->server->settings->update([
        'is_reachable' => false,
        'is_usable' => false,
    ]);

    $this->server->refresh();

    expect($this->server->gatherServerMetadata())->toBeNull();
});

it('can overwrite server_metadata with new values', function () {
    $this->server->update(['server_metadata' => ['os' => 'Ubuntu 20.04', 'cpus' => 2]]);
    $this->server->refresh();

    expect($this->server->server_metadata['os'])->toBe('Ubuntu 20.04');

    $this->server->update(['server_metadata' => ['os' => 'Ubuntu 22.04', 'cpus' => 4]]);
    $this->server->refresh();

    expect($this->server->server_metadata['os'])->toBe('Ubuntu 22.04')
        ->and($this->server->server_metadata['cpus'])->toBe(4);
});
