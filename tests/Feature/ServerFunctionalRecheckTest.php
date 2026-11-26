<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Sleep::fake();
});

function createServerForFunctionalRecheckTest(array $settings): Server
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.10',
    ]);
    $server->settings->update($settings);

    return $server->refresh();
}

it('marks a server functional again when the live SSH and Docker checks pass', function () {
    $server = createServerForFunctionalRecheckTest(['is_reachable' => false, 'is_usable' => false]);

    Process::fake([
        '*' => Process::result(output: '{"Server":{"Version":"29.4.3"}}', exitCode: 0),
    ]);

    expect($server->isFunctionalAfterRecheck())->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeTrue();
});

it('keeps a server not functional when the live SSH check fails', function () {
    $server = createServerForFunctionalRecheckTest(['is_reachable' => false, 'is_usable' => false]);

    Process::fake([
        '*' => Process::result(errorOutput: 'Connection refused', exitCode: 255),
    ]);

    expect($server->isFunctionalAfterRecheck())->toBeFalse()
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeFalse();
});

it('does not connect to a reachable server that is marked unusable', function () {
    $server = createServerForFunctionalRecheckTest(['is_reachable' => true, 'is_usable' => false]);

    Process::fake();

    expect($server->isFunctionalAfterRecheck())->toBeFalse();

    Process::assertNothingRan();
});

it('keeps a server not functional when Docker does not respond', function () {
    $server = createServerForFunctionalRecheckTest(['is_reachable' => false, 'is_usable' => false]);

    Process::fake([
        '*docker*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: '', exitCode: 0),
    ]);

    expect($server->isFunctionalAfterRecheck())->toBeFalse()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeFalse();
});

it('does not connect to a server that is already functional', function () {
    $server = createServerForFunctionalRecheckTest(['is_reachable' => true, 'is_usable' => true]);

    Process::fake();

    expect($server->isFunctionalAfterRecheck())->toBeTrue();

    Process::assertNothingRan();
});

it('does not connect to a disabled server', function () {
    $server = createServerForFunctionalRecheckTest(['is_reachable' => false, 'is_usable' => false, 'force_disabled' => true]);

    Process::fake();

    expect($server->isFunctionalAfterRecheck())->toBeFalse();

    Process::assertNothingRan();
});
