<?php

use App\Livewire\Project\Shared\Terminal;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use phpseclib3\Crypt\RSA;

uses(RefreshDatabase::class);

it('probes container capabilities with exactly one elevation layer', function (string $user, string $sudo) {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('constants.ssh.mux_enabled', false);
    Storage::fake('ssh-keys');
    $team = Team::factory()->create();
    $key = PrivateKey::create([
        'name' => 'Terminal test key',
        'private_key' => RSA::createKey(1024)->toString('OpenSSH'),
        'team_id' => $team->id,
    ]);
    $server = Server::factory()->create([
        'user' => $user,
        'team_id' => $team->id,
        'private_key_id' => $key->id,
    ]);
    Process::fake(fn () => Process::result(output: 'true'));

    $terminal = new Terminal;
    expect((new ReflectionMethod(Terminal::class, 'containerKeepsStdinOpen'))->invoke($terminal, $server, 'game-1'))->toBeTrue();
    expect((new ReflectionMethod(Terminal::class, 'checkShellAvailability'))->invoke($terminal, $server, 'game-1'))->toBeTrue();

    Process::assertRan(function ($process) use ($sudo) {
        if (! str_contains($process->command, 'docker inspect')) {
            return false;
        }
        expect($process->command)
            ->toContain("\n{$sudo}docker inspect --format '{{.Config.OpenStdin}}' 'game-1' 2>/dev/null\n")
            ->not->toContain('sudo sudo');

        return str_contains($process->command, 'docker inspect');
    });
    Process::assertRan(function ($process) use ($sudo) {
        if (! str_contains($process->command, 'docker exec')) {
            return false;
        }
        expect($process->command)
            ->toContain("{$sudo}docker exec 'game-1' bash -c 'exit 0' 2>/dev/null || {$sudo}docker exec 'game-1' sh -c 'exit 0' 2>/dev/null")
            ->not->toContain('sudo sudo');

        return true;
    });
})->with([
    'root' => ['root', ''],
    'non-root' => ['ubuntu', 'sudo '],
]);
