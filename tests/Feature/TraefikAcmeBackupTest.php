<?php

use App\Actions\Proxy\DeleteTraefikAcmeBackup;
use App\Actions\Proxy\DeleteTraefikCertificate;
use App\Actions\Proxy\GetTraefikCertificates;
use App\Actions\Proxy\ListTraefikAcmeBackups;
use App\Actions\Proxy\RestoreTraefikAcmeBackup;
use App\Actions\Proxy\SaveTraefikAcmeFile;
use App\Enums\ProxyTypes;
use App\Jobs\RestartProxyJob;
use App\Livewire\Server\Proxy;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Runs the remote commands against a local directory instead of a server, so the shell scripts are really executed.
 */
beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false]);

    $this->baseConfigPath = sys_get_temp_dir().'/coolify-acme-backup-test-'.bin2hex(random_bytes(6));
    mkdir($this->baseConfigPath.'/proxy', 0700, true);
    config(['constants.coolify.base_config_path' => $this->baseConfigPath]);
    $this->proxyDirectory = $this->baseConfigPath.'/proxy';
    $this->acmePath = $this->proxyDirectory.'/acme.json';

    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $key->id,
        'user' => 'deploy',
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running'],
    ]);

    $this->acmeContents = json_encode(['letsencrypt' => ['Certificates' => [
        ['domain' => ['main' => 'one.example.com'], 'certificate' => base64_encode('one'), 'key' => base64_encode('key-one')],
        ['domain' => ['main' => 'two.example.com'], 'certificate' => base64_encode('two'), 'key' => base64_encode('key-two')],
    ]]]);
    file_put_contents($this->acmePath, $this->acmeContents);
    chmod($this->acmePath, 0600);

    $this->remoteLines = [];
    Process::fake(function ($process) {
        $command = $process->command;
        if (str_contains($command, ' scp ') && preg_match("~'([^']+)' \\S+:'([^']+)'$~", $command, $matches)) {
            copy($matches[1], $matches[2]);

            return Process::result();
        }

        $lines = explode("\n", $command);
        $body = array_slice($lines, 1, -1);
        array_push($this->remoteLines, ...$body);
        $script = implode("\n", array_map(fn (string $line): string => preg_replace('/^sudo /', '', $line), $body));

        $handle = proc_open(['sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return Process::result(output: $output, errorOutput: $errorOutput, exitCode: proc_close($handle));
    });
});

afterEach(function () {
    File::deleteDirectory($this->baseConfigPath);
});

function acmeBackupFiles(string $directory): array
{
    return collect(scandir($directory))
        ->filter(fn (string $name): bool => preg_match(ListTraefikAcmeBackups::NAME_PATTERN, $name) === 1)
        ->sort()
        ->values()
        ->all();
}

function createAcmeBackup(string $directory, string $name, string $contents): void
{
    file_put_contents("{$directory}/{$name}", $contents);
    chmod("{$directory}/{$name}", 0600);
}

function filePermissions(string $path): string
{
    clearstatcache();

    return substr(sprintf('%o', fileperms($path)), -3);
}

it('backs up acme.json before a certificate is deleted', function () {
    DeleteTraefikCertificate::run($this->server, GetTraefikCertificates::run($this->server)[0]['id']);

    $backups = acmeBackupFiles($this->proxyDirectory);
    expect($backups)->toHaveCount(1)
        ->and($backups[0])->toMatch('/^acme\.json\.backup-\d{8}T\d{6}Z-[0-9a-f]{8}$/')
        ->and(file_get_contents($this->proxyDirectory.'/'.$backups[0]))->toBe($this->acmeContents)
        ->and(filePermissions($this->proxyDirectory.'/'.$backups[0]))->toBe('600')
        ->and(json_decode(file_get_contents($this->acmePath), true)['letsencrypt']['Certificates'])->toHaveCount(1)
        ->and(filePermissions($this->acmePath))->toBe('600');

    // Every remote line must stay one quoted sudo shell script for non-root SSH users.
    expect($this->remoteLines)->not->toBeEmpty();
    foreach ($this->remoteLines as $line) {
        expect(isSingleSudoShellScript($line))->toBeTrue();
    }
});

it('does not create a backup when acme.json does not exist yet', function () {
    unlink($this->acmePath);

    SaveTraefikAcmeFile::run($this->server, '{}');

    expect(acmeBackupFiles($this->proxyDirectory))->toBeEmpty()
        ->and(file_get_contents($this->acmePath))->toBe('{}');
});

it('keeps only the newest acme.json backups', function () {
    foreach (range(1, 12) as $day) {
        createAcmeBackup($this->proxyDirectory, sprintf('acme.json.backup-202601%02dT000000Z-0000000%s', $day, dechex($day)), "old-{$day}");
    }
    file_put_contents($this->proxyDirectory.'/acme.json.backup-unrelated', 'keep');

    SaveTraefikAcmeFile::run($this->server, '{}');

    $backups = acmeBackupFiles($this->proxyDirectory);
    expect($backups)->toHaveCount(ListTraefikAcmeBackups::KEEP)
        ->and($backups)->not->toContain('acme.json.backup-20260101T000000Z-00000001', 'acme.json.backup-20260103T000000Z-00000003')
        ->and($backups)->toContain('acme.json.backup-20260104T000000Z-00000004')
        ->and(file_get_contents($this->proxyDirectory.'/'.end($backups)))->toBe($this->acmeContents)
        ->and(file_exists($this->proxyDirectory.'/acme.json.backup-unrelated'))->toBeTrue();
});

it('lists acme.json backups newest first with time and size', function () {
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260101T101500Z-0000abcd', 'older');
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260202T101500Z-0000abce', 'newer backup');
    file_put_contents($this->proxyDirectory.'/acme.json.backup-$(touch pwned)', 'ignored');

    $backups = ListTraefikAcmeBackups::run($this->server);

    expect($backups)->toBe([
        ['name' => 'acme.json.backup-20260202T101500Z-0000abce', 'created_at' => '2026-02-02 10:15:00 UTC', 'size' => 12],
        ['name' => 'acme.json.backup-20260101T101500Z-0000abcd', 'created_at' => '2026-01-01 10:15:00 UTC', 'size' => 5],
    ])->and(file_exists($this->proxyDirectory.'/pwned'))->toBeFalse();
});

it('restores a backup after backing up the current acme.json', function () {
    // The restored backup is the oldest one, which the pruning must not remove before it is copied.
    foreach (range(1, 10) as $day) {
        createAcmeBackup($this->proxyDirectory, sprintf('acme.json.backup-202601%02dT000000Z-000000%02x', $day, $day), "backup-{$day}");
    }

    RestoreTraefikAcmeBackup::run($this->server, 'acme.json.backup-20260101T000000Z-00000001');

    $backups = acmeBackupFiles($this->proxyDirectory);
    expect(file_get_contents($this->acmePath))->toBe('backup-1')
        ->and(filePermissions($this->acmePath))->toBe('600')
        ->and($backups)->toHaveCount(ListTraefikAcmeBackups::KEEP)
        ->and(file_get_contents($this->proxyDirectory.'/'.end($backups)))->toBe($this->acmeContents)
        ->and($this->server->fresh()->hasPendingProxyConfiguration())->toBeTrue();
    foreach ($this->remoteLines as $line) {
        expect(isSingleSudoShellScript($line))->toBeTrue();
    }
});

it('refuses to restore or delete backups that are not listed', function (string $name) {
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260101T000000Z-00000001', 'backup');
    file_put_contents($this->baseConfigPath.'/acme.json.backup-20260101T000000Z-00000002', 'outside');

    expect(fn () => RestoreTraefikAcmeBackup::run($this->server, $name))->toThrow(RuntimeException::class)
        ->and(fn () => DeleteTraefikAcmeBackup::run($this->server, $name))->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->acmePath))->toBe($this->acmeContents)
        ->and(acmeBackupFiles($this->proxyDirectory))->toBe(['acme.json.backup-20260101T000000Z-00000001'])
        ->and($this->server->fresh()->hasPendingProxyConfiguration())->toBeFalse();
})->with([
    'path traversal' => '../acme.json.backup-20260101T000000Z-00000002',
    'shell injection' => "acme.json.backup-20260101T000000Z-00000001'; rm -rf /; '",
    'not a backup' => 'acme.json',
    'missing backup' => 'acme.json.backup-20260101T000000Z-0000000f',
]);

it('deletes a backup', function () {
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260101T000000Z-00000001', 'backup');

    DeleteTraefikAcmeBackup::run($this->server, 'acme.json.backup-20260101T000000Z-00000001');

    expect(acmeBackupFiles($this->proxyDirectory))->toBeEmpty()
        ->and(file_get_contents($this->acmePath))->toBe($this->acmeContents);
});

function actingAsAcmeBackupUser(Team $team, string $role): User
{
    InstanceSettings::forceCreate(['id' => 0]);
    $user = User::factory()->create();
    $user->teams()->attach($team->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

it('lets an admin see and restore acme.json backups from the proxy page', function () {
    $this->withoutDefer();
    actingAsAcmeBackupUser($this->server->team, 'admin');
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260101T000000Z-00000001', '{}');

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('loadTraefikCertificates')
        ->assertSee('acme.json.backup-20260101T000000Z-00000001')
        ->assertSee('2026-01-01 00:00:00 UTC')
        ->assertSeeHtml(['restoreTraefikAcmeBackup(', 'deleteTraefikAcmeBackup('])
        ->call('restoreTraefikAcmeBackup', 'acme.json.backup-20260101T000000Z-00000001')
        ->assertDispatched('success')
        ->assertDispatched('refreshServerShow')
        ->assertSet('traefikCertificates', [])
        ->assertSee('Restart the proxy to apply TLS certificate changes.');

    $event = AuditEvent::query()->where('event', 'ui.proxy.acme_backup_restored')->sole();
    expect(file_get_contents($this->acmePath))->toBe('{}')
        ->and($event->team_id)->toBe($this->server->team_id)
        ->and($event->metadata)->toMatchArray([
            'server_uuid' => $this->server->uuid,
            'backup' => 'acme.json.backup-20260101T000000Z-00000001',
        ]);
});

it('restarts the proxy after a restore when the restart option is selected', function () {
    Queue::fake();
    $this->withoutDefer();
    actingAsAcmeBackupUser($this->server->team, 'admin');
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260101T000000Z-00000001', '{}');

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->assertSet('restartProxyAfterAcmeRestore', true)
        ->call('restoreTraefikAcmeBackup', 'acme.json.backup-20260101T000000Z-00000001', '', ['restartProxyAfterAcmeRestore'])
        ->assertDispatched('success');

    Queue::assertPushed(RestartProxyJob::class, fn (RestartProxyJob $job): bool => $job->server->is($this->server));
    expect(file_get_contents($this->acmePath))->toBe('{}')
        ->and(AuditEvent::query()->where('event', 'ui.proxy.restarted')->exists())->toBeTrue();
});

it('does not restart the proxy after a restore when the restart option is cleared', function () {
    Queue::fake();
    actingAsAcmeBackupUser($this->server->team, 'admin');
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260101T000000Z-00000001', '{}');

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('restoreTraefikAcmeBackup', 'acme.json.backup-20260101T000000Z-00000001', '')
        ->assertDispatched('success');

    Queue::assertNotPushed(RestartProxyJob::class);
});

it('lets an admin delete an acme.json backup from the proxy page', function () {
    actingAsAcmeBackupUser($this->server->team, 'admin');
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260101T000000Z-00000001', '{}');

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('deleteTraefikAcmeBackup', 'acme.json.backup-20260101T000000Z-00000001')
        ->assertDispatched('success')
        ->assertSet('traefikAcmeBackups', []);

    expect(acmeBackupFiles($this->proxyDirectory))->toBeEmpty();
});

it('does not let a member list, restore, or delete acme.json backups', function () {
    actingAsAcmeBackupUser($this->server->team, 'member');
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260101T000000Z-00000001', '{}');

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('loadTraefikCertificates')
        ->assertSet('traefikCertificates', fn (array $certificates): bool => count($certificates) === 2)
        ->assertSet('traefikAcmeBackups', [])
        ->assertDontSee('acme.json.backup-20260101T000000Z-00000001')
        ->assertDontSeeHtml(['restoreTraefikAcmeBackup(', 'deleteTraefikAcmeBackup('])
        ->call('restoreTraefikAcmeBackup', 'acme.json.backup-20260101T000000Z-00000001')
        ->assertDispatched('error')
        ->assertNotDispatched('success')
        ->call('deleteTraefikAcmeBackup', 'acme.json.backup-20260101T000000Z-00000001')
        ->assertNotDispatched('success');

    expect(file_get_contents($this->acmePath))->toBe($this->acmeContents)
        ->and(acmeBackupFiles($this->proxyDirectory))->toBe(['acme.json.backup-20260101T000000Z-00000001'])
        ->and($this->server->fresh()->hasPendingProxyConfiguration())->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'ui.proxy.acme_backup_restored')->exists())->toBeFalse();
});

it('does not list, restore, or delete acme.json backups of another team', function () {
    actingAsAcmeBackupUser(Team::factory()->create(), 'owner');
    createAcmeBackup($this->proxyDirectory, 'acme.json.backup-20260101T000000Z-00000001', '{}');

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('loadTraefikCertificates')
        ->assertSet('traefikAcmeBackups', [])
        ->call('restoreTraefikAcmeBackup', 'acme.json.backup-20260101T000000Z-00000001')
        ->assertDispatched('error')
        ->call('deleteTraefikAcmeBackup', 'acme.json.backup-20260101T000000Z-00000001')
        ->assertNotDispatched('success');

    expect($this->remoteLines)->toBeEmpty()
        ->and(file_get_contents($this->acmePath))->toBe($this->acmeContents)
        ->and(acmeBackupFiles($this->proxyDirectory))->toBe(['acme.json.backup-20260101T000000Z-00000001']);
});
