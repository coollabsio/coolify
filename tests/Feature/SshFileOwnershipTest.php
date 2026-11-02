<?php

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Horizon runs as root and php-fpm runs as www-data. SSH files that a root process writes must belong to
 * the owner of storage/app/ssh, or the web process cannot read or replace them.
 */
const SSH_FILES_TEST_OWNER = 65534;

beforeEach(function () {
    if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        $this->markTestSkipped('Only a root process can change file owners.');
    }

    $this->originalStoragePath = storage_path();
    $this->storageRoot = sys_get_temp_dir().'/coolify-ssh-owner-'.uniqid();
    File::ensureDirectoryExists($this->storageRoot.'/app/ssh/keys', 0700);
    chown($this->storageRoot.'/app/ssh', SSH_FILES_TEST_OWNER);
    chown($this->storageRoot.'/app/ssh/keys', SSH_FILES_TEST_OWNER);
    app()->useStoragePath($this->storageRoot);
    config(['filesystems.disks.ssh-keys.root' => $this->storageRoot.'/app/ssh/keys']);
    Storage::forgetDisk('ssh-keys');
});

afterEach(function () {
    if (isset($this->storageRoot)) {
        app()->useStoragePath($this->originalStoragePath);
        Storage::forgetDisk('ssh-keys');
        File::deleteDirectory($this->storageRoot);
    }
});

function makeServerWithPinnedHostKey(): Server
{
    $server = new Server;
    $server->forceFill(['uuid' => 'owner-test', 'ip' => '10.0.0.5', 'port' => 22, 'user' => 'root']);
    $server->setRelation('settings', (new ServerSetting)->forceFill([
        'ssh_host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl',
    ]));

    return $server;
}

function syncKnownHostsFileFor(Server $server): string
{
    return (new ReflectionMethod(SshMultiplexingHelper::class, 'syncKnownHostsFile'))->invoke(null, $server);
}

it('gives a known_hosts file that root writes to the owner of the ssh directory', function () {
    $path = syncKnownHostsFileFor(makeServerWithPinnedHostKey());

    expect(fileowner(dirname($path)))->toBe(SSH_FILES_TEST_OWNER)
        ->and(fileowner($path))->toBe(SSH_FILES_TEST_OWNER);
});

it('repairs a known_hosts directory and file that root created before', function () {
    $server = makeServerWithPinnedHostKey();
    $path = syncKnownHostsFileFor($server);
    chown(dirname($path), 0);
    chown($path, 0);

    syncKnownHostsFileFor($server);

    expect(fileowner(dirname($path)))->toBe(SSH_FILES_TEST_OWNER)
        ->and(fileowner($path))->toBe(SSH_FILES_TEST_OWNER);
});

it('gives a private key file and its lock file that root writes to the owner of the ssh directory', function () {
    $team = User::factory()->create()->teams()->first();
    $privateKey = PrivateKey::create([
        'name' => 'owner-test-key',
        'team_id' => $team->id,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);

    $privateKey->storeInFileSystem();

    expect(fileowner($privateKey->getKeyLocation()))->toBe(SSH_FILES_TEST_OWNER)
        ->and(fileowner($privateKey->getKeyLocation().'.lock'))->toBe(SSH_FILES_TEST_OWNER)
        ->and(fileperms($privateKey->getKeyLocation()) & 0777)->toBe(0600);
});
