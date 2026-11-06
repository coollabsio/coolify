<?php

use App\Actions\Server\AuthorizedKeys;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\Dns\DnsRecord;
use App\Services\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Symfony\Component\Process\Process as ShellProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Once::flush();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Process::fake();
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0, 'custom_dns_servers' => '192.0.2.53']);
    $this->team = Team::factory()->create();
    $this->key = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id, 'private_key_id' => $this->key->id,
        'ip' => '192.0.2.10', 'user' => 'root',
    ]);
    $this->scripts = [];
    Process::fake(function (PendingProcess $process) {
        // Batches go to the remote shell on stdin.
        if (filled($process->input)) {
            $this->scripts[] = rtrim((string) $process->input, "\n");
        }

        return Process::result();
    });
    $this->home = sys_get_temp_dir().'/authorized-keys-test-'.bin2hex(random_bytes(8));
    mkdir($this->home.'/.ssh', 0700, true);
});

afterEach(function () {
    File::deleteDirectory($this->home);
    Server::flushIdentityMap();
});

it('keeps the live file intact if its intermediate file disappears', function () {
    AuthorizedKeys::remove($this->server, $this->key);
    $file = $this->home.'/.ssh/authorized_keys';
    $original = $this->key->publicKeyBody()." old\nssh-ed25519 RECOVERY operator\n";
    file_put_contents($file, $original);
    mkdir($this->home.'/bin');
    // Simulate the other cleanup deleting the staging file before it can be copied.
    file_put_contents($this->home.'/bin/grep', <<<'SH'
#!/bin/sh
output=$(readlink /proc/$$/fd/1)
/usr/bin/grep "$@"
rc=$?
rm -f "$output"
exit "$rc"
SH);
    chmod($this->home.'/bin/grep', 0700);
    $process = new ShellProcess(['sh', '-se'], null, [
        'HOME' => $this->home, 'PATH' => $this->home.'/bin:'.getenv('PATH'),
    ], end($this->scripts));
    $process->run();

    expect(file_get_contents($file))->toBe($original);
});

it('serializes concurrent additions and removals without losing recovery keys or file mode', function () {
    AuthorizedKeys::remove($this->server, $this->key);
    $remove = end($this->scripts);
    $newKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    $nextKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    AuthorizedKeys::add($this->server, $nextKey, $newKey);
    $add = end($this->scripts);
    $file = $this->home.'/.ssh/authorized_keys';
    file_put_contents($file, $this->key->publicKeyBody()." old\n".$newKey->publicKeyBody()." current\nssh-ed25519 RECOVERY operator\n");
    chmod($file, 0640);
    $processes = [];
    for ($i = 0; $i < 6; $i++) {
        foreach ([$remove, $add] as $script) {
            $process = new ShellProcess(['sh', '-se'], null, ['HOME' => $this->home], $script);
            $process->start();
            $processes[] = $process;
        }
    }
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    expect(file_get_contents($file))->toBe($newKey->publicKeyBody()." current\nssh-ed25519 RECOVERY operator\n".$nextKey->authorizedKeysLine()."\n")
        ->and(fileperms($file) & 0777)->toBe(0640);
});

it('retains equivalent key material used by another record for the same host on a different port', function () {
    $otherTeam = Team::factory()->create();
    $copy = PrivateKey::factory()->create([
        'team_id' => $otherTeam->id, 'private_key' => $this->key->private_key,
    ]);
    Server::factory()->create([
        'team_id' => $otherTeam->id, 'private_key_id' => $copy->id,
        'ip' => $this->server->ip, 'user' => 'root', 'port' => 2222,
    ]);

    expect(AuthorizedKeys::remove($this->server, $this->key))->toBeFalse();
});

it('retains a key used through a hostname alias or an unresolved host', function (bool $resolved) {
    Server::factory()->create([
        'team_id' => $this->team->id, 'private_key_id' => $this->key->id,
        'ip' => 'ssh.example.test', 'user' => 'root',
    ]);
    app()->bind(DnsResolver::class, fn () => new class($resolved) extends DnsResolver
    {
        public function __construct(private bool $resolved)
        {
            parent::__construct('192.0.2.53');
        }

        public function query(string $host, string $type = self::TYPE_A): array|false
        {
            return $this->resolved ? ($type === self::TYPE_A ? [new DnsRecord(self::TYPE_A, '192.0.2.10')] : []) : false;
        }
    });

    expect(AuthorizedKeys::remove($this->server, $this->key))->toBeFalse();
})->with([true, false]);

it('removes a shared key from an independent literal IP server', function () {
    $other = Server::factory()->create([
        'team_id' => $this->team->id, 'private_key_id' => $this->key->id,
        'ip' => '192.0.2.11', 'user' => 'root',
    ]);
    $this->server->settings->forceFill(['ssh_host_key' => 'ssh-ed25519 AAAAHOSTA'])->save();
    $other->settings->forceFill(['ssh_host_key' => 'ssh-ed25519 AAAAHOSTB'])->save();

    expect(AuthorizedKeys::remove($this->server->fresh(), $this->key))->toBeTrue();
});

it('retains a shared key used through another IP of the same machine', function (?string $otherHostKey) {
    $other = Server::factory()->create([
        'team_id' => $this->team->id, 'private_key_id' => $this->key->id,
        'ip' => '100.64.0.10', 'user' => 'root',
    ]);
    $this->server->settings->forceFill(['ssh_host_key' => "ecdsa-sha2-nistp256 AAAAOTHER\nssh-ed25519 AAAAHOSTA"])->save();
    $other->settings->forceFill(['ssh_host_key' => $otherHostKey])->save();

    expect(AuthorizedKeys::remove($this->server->fresh(), $this->key))->toBeFalse();
})->with([
    'same host key' => 'ssh-ed25519 AAAAHOSTA',
    'host key not known yet' => null,
]);

it('retains the same legacy key with no fingerprint through a differently cased hostname', function () {
    $this->key->forceFill(['fingerprint' => null])->saveQuietly();
    $this->server->forceFill(['ip' => 'SSH.EXAMPLE.TEST.'])->saveQuietly();
    Server::factory()->create([
        'team_id' => $this->team->id, 'private_key_id' => $this->key->id,
        'ip' => 'ssh.example.test', 'user' => 'root',
    ]);

    expect(AuthorizedKeys::remove($this->server, $this->key))->toBeFalse();
});

it('does not retain an unrelated key used by another record on the same host', function () {
    $otherKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    Server::factory()->create([
        'team_id' => $this->team->id, 'private_key_id' => $otherKey->id,
        'ip' => $this->server->ip, 'user' => 'root',
    ]);

    expect(AuthorizedKeys::remove($this->server, $this->key))->toBeTrue();
});

it('preserves quoted restrictions and duplicate entries when rotating a key', function () {
    $newKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    $options = 'from="192.0.2.0/24",command="echo hello world",restrict';
    $file = $this->home.'/.ssh/authorized_keys';
    file_put_contents($file, $options.' '.$this->key->publicKeyBody()." old\n".'restrict '.$this->key->publicKeyBody()." second\n");
    AuthorizedKeys::add($this->server, $newKey, $this->key);
    $process = new ShellProcess(['sh', '-se'], null, ['HOME' => $this->home], end($this->scripts));
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect(file_get_contents($file))
        ->toContain($options.' '.$newKey->authorizedKeysLine())
        ->toContain('restrict '.$newKey->authorizedKeysLine());
});

function runAuthorizedKeysScript(string $home, string $script): ShellProcess
{
    $process = new ShellProcess(['bash', '-se'], null, ['HOME' => $home, 'PATH' => getenv('PATH')], $script);
    $process->run();

    return $process;
}

it('keeps the last line intact when a rotation appends to a file without a final newline', function () {
    $newKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    $file = $this->home.'/.ssh/authorized_keys';
    file_put_contents($file, $this->key->publicKeyBody()." old\nssh-ed25519 PERSONAL operator");
    AuthorizedKeys::add($this->server, $newKey, $this->key);
    $process = runAuthorizedKeysScript($this->home, end($this->scripts));
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(file($file, FILE_IGNORE_NEW_LINES))->toBe([
            $this->key->publicKeyBody().' old',
            'ssh-ed25519 PERSONAL operator',
            $newKey->authorizedKeysLine(),
        ]);

    // A failed login test removes the new key again; the personal key must stay.
    expect(AuthorizedKeys::undoAdd($this->server, $newKey, $this->key))->toBeTrue();
    $process = runAuthorizedKeysScript($this->home, end($this->scripts));
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(file_get_contents($file))->toBe($this->key->publicKeyBody()." old\nssh-ed25519 PERSONAL operator\n");
});

it('takes over a lock that a crashed edit left behind', function () {
    $lock = $this->home.'/.ssh/.coolify-authorized-keys.lock';
    mkdir($lock);
    touch($lock, time() - 1200);
    $file = $this->home.'/.ssh/authorized_keys';
    file_put_contents($file, $this->key->publicKeyBody()." old\nssh-ed25519 RECOVERY operator\n");
    AuthorizedKeys::remove($this->server, $this->key);

    $started = microtime(true);
    $process = runAuthorizedKeysScript($this->home, end($this->scripts));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(microtime(true) - $started)->toBeLessThan(5)
        ->and(file_get_contents($file))->toBe("ssh-ed25519 RECOVERY operator\n")
        ->and(is_dir($lock))->toBeFalse();
});

it('does not retain a key because of another key without a fingerprint on the same host', function () {
    $otherTeam = Team::factory()->create();
    $legacy = PrivateKey::factory()->create([
        'team_id' => $otherTeam->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    $legacy->forceFill(['fingerprint' => null])->saveQuietly();
    Server::factory()->create([
        'team_id' => $otherTeam->id, 'private_key_id' => $legacy->id,
        'ip' => $this->server->ip, 'user' => 'root',
    ]);

    expect(AuthorizedKeys::remove($this->server, $this->key))->toBeTrue();
});

it('removes only the lines it added when the new key cannot log in', function () {
    $newKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    $file = $this->home.'/.ssh/authorized_keys';
    $manual = 'from="192.0.2.0/24" '.$newKey->publicKeyBody().' added by hand';
    $original = 'restrict '.$this->key->publicKeyBody()." old\n{$manual}\nssh-ed25519 PERSONAL operator\n";
    file_put_contents($file, $original);
    AuthorizedKeys::add($this->server, $newKey, $this->key);
    expect(runAuthorizedKeysScript($this->home, end($this->scripts))->isSuccessful())->toBeTrue()
        ->and(file_get_contents($file))->toContain('restrict '.$newKey->authorizedKeysLine());

    AuthorizedKeys::undoAdd($this->server, $newKey, $this->key);
    $process = runAuthorizedKeysScript($this->home, end($this->scripts));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(file_get_contents($file))->toBe($original);
});

it('stops with its own exit code when the old key is not in the file', function () {
    $newKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    $file = $this->home.'/.ssh/authorized_keys';
    file_put_contents($file, "ssh-ed25519 PERSONAL operator\n");
    AuthorizedKeys::add($this->server, $newKey, $this->key);
    $process = runAuthorizedKeysScript($this->home, end($this->scripts));

    expect($process->getExitCode())->toBe(AuthorizedKeys::OLD_KEY_NOT_FOUND)
        ->and(file_get_contents($file))->toBe("ssh-ed25519 PERSONAL operator\n");
});

it('retains the same key material saved without a fingerprint by another team on the same host', function () {
    $otherTeam = Team::factory()->create();
    $copy = PrivateKey::factory()->create([
        'team_id' => $otherTeam->id, 'private_key' => $this->key->private_key,
    ]);
    $copy->forceFill(['fingerprint' => null])->saveQuietly();
    Server::factory()->create([
        'team_id' => $otherTeam->id, 'private_key_id' => $copy->id,
        'ip' => $this->server->ip, 'user' => 'root', 'port' => 2222,
    ]);

    expect(AuthorizedKeys::remove($this->server, $this->key))->toBeFalse();
});
