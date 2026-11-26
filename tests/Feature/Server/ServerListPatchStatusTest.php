<?php

use App\Livewire\Server\Index;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

function serverListPatchServer(object $test, string $name, array $settings = []): Server
{
    $server = Server::factory()->create([
        'name' => $name,
        'team_id' => $test->team->id,
        'private_key_id' => $test->privateKey->id,
    ]);
    $server->settings->forceFill($settings)->saveQuietly();

    return $server;
}

function serverListPatchResult(int $updates, int $security, ?bool $rebootRequired): array
{
    return [
        'osId' => 'ubuntu',
        'package_manager' => 'apt',
        'total_updates' => $updates,
        'security_updates' => $security,
        'reboot_required' => $rebootRequired,
        'updates' => [],
    ];
}

function serverListPatchActingAs(object $test, string $role): void
{
    $user = User::factory()->create();
    $test->team->members()->attach($user, ['role' => $role]);
    $test->actingAs($user);
    session(['currentTeam' => $test->team]);
}

describe('patchStatus()', function () {
    it('returns null when the server was never checked', function () {
        $server = serverListPatchServer($this, 'never');

        expect($server->settings->fresh()->patchStatus())->toBeNull();
    });

    it('returns the error of a failed check', function () {
        $server = serverListPatchServer($this, 'failed', [
            'patch_check_result' => ['osId' => 'ubuntu', 'package_manager' => 'apt'],
            'patch_check_error' => 'apt-get failed',
            'patch_checked_at' => now(),
        ]);

        expect($server->settings->fresh()->patchStatus())
            ->toMatchArray(['updates' => 0, 'security' => 0, 'reboot_required' => false, 'error' => 'apt-get failed']);
    });

    it('returns the update, security, and reboot counts', function () {
        $server = serverListPatchServer($this, 'counts', [
            'patch_check_result' => serverListPatchResult(3, 1, true),
            'patch_checked_at' => now()->subHour(),
        ]);

        $status = $server->settings->fresh()->patchStatus();

        expect($status)->toMatchArray(['updates' => 3, 'security' => 1, 'reboot_required' => true, 'error' => null])
            ->and($status['checked_at']->isSameMinute(now()->subHour()))->toBeTrue();
    });

    it('treats an old result without security and reboot keys as none', function () {
        $server = serverListPatchServer($this, 'old', [
            'patch_check_result' => ['osId' => 'ubuntu', 'package_manager' => 'apt', 'total_updates' => 2, 'updates' => []],
            'patch_checked_at' => now(),
        ]);

        expect($server->settings->fresh()->patchStatus())
            ->toMatchArray(['updates' => 2, 'security' => 0, 'reboot_required' => false, 'error' => null]);
    });
});

describe('server list', function () {
    beforeEach(function () {
        serverListPatchServer($this, 'secure-host', [
            'patch_check_result' => serverListPatchResult(3, 1, true),
            'patch_checked_at' => now(),
        ]);
        serverListPatchServer($this, 'plain-host', [
            'patch_check_result' => serverListPatchResult(2, 0, false),
            'patch_checked_at' => now(),
        ]);
        serverListPatchServer($this, 'broken-host', [
            'patch_check_error' => 'apt-get failed',
            'patch_checked_at' => now(),
        ]);
        serverListPatchServer($this, 'unchecked-host');
    });

    it('shows patch badges to an admin', function () {
        serverListPatchActingAs($this, 'admin');

        Livewire::test(Index::class)
            ->assertSee('1 security update')
            ->assertSee('2 updates')
            ->assertSee('Reboot required')
            ->assertSee('Patch check failed')
            ->assertDontSee('Patches:');
    });

    it('hides patch badges from a member', function () {
        serverListPatchActingAs($this, 'member');

        Livewire::test(Index::class)
            ->assertSee('secure-host')
            ->assertDontSee('security update')
            ->assertDontSee('Reboot required')
            ->assertDontSee('Patch check failed');
    });

    it('shows up to date when every checked server has no updates', function () {
        Server::query()->each(fn (Server $server) => $server->settings->forceFill([
            'patch_check_result' => serverListPatchResult(0, 0, false),
            'patch_check_error' => null,
            'patch_checked_at' => now(),
        ])->saveQuietly());
        serverListPatchActingAs($this, 'admin');

        Livewire::test(Index::class)
            ->assertSee('Up to date');
    });
});
