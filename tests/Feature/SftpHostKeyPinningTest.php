<?php

use App\Enums\BackupStorageType;
use App\Jobs\RevalidateUnusableS3StoragesJob;
use App\Livewire\Storage\Form;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use Livewire\Livewire;
use phpseclib3\Net\SFTP;

uses(RefreshDatabase::class);

/** The ed25519 host key of a test SFTP server, and its fingerprint from `ssh-keygen -lf`. */
const SFTP_TEST_HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhw/KsYTF9TQOwQX1EAM3gI5oxDS9Daz5nNuCixSRXT';
const SFTP_TEST_HOST_KEY_FINGERPRINT = 'SHA256:eWi1PeTgK0w2iDxELFYDaIMDP8CTLk3iJ/RR3e5u52U';

/**
 * Replaces the phpseclib connection with a stub: the server presents `$serverKey`, or the connection fails with
 * `$failure`. `$parameters` receives the options of the SFTP connection.
 */
function fakeSftpServer(string $serverKey, ?Throwable $failure = null, ?array &$parameters = null): void
{
    app()->bind(SftpConnectionProvider::class, function ($app, array $options) use ($serverKey, $failure, &$parameters) {
        $parameters = $options;
        $connection = Mockery::mock(SFTP::class);
        $connection->allows('getServerPublicHostKey')->andReturn($serverKey);
        $connection->allows('rawlist')->andReturn([]);
        $connection->allows('disconnect');

        return new class($connection, $failure) implements ConnectionProvider
        {
            public function __construct(private SFTP $connection, private ?Throwable $failure) {}

            public function provideConnection(): SFTP
            {
                if ($this->failure) {
                    throw $this->failure;
                }

                return $this->connection;
            }

            public function disconnect(): void {}
        };
    });
}

function sftpHostKeyStorage(Team $team, array $attributes = []): S3Storage
{
    $storage = S3Storage::create([
        'team_id' => $team->id,
        'type' => BackupStorageType::Sftp,
        'name' => 'SFTP backups',
        'region' => 'us-east-1',
        'key' => 'backup',
        'secret' => 'password',
        'endpoint' => 'sftp://1.1.1.1:2222/backups',
        'is_usable' => true,
    ]);
    $storage->forceFill($attributes)->save();

    return $storage;
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member, ['role' => 'member']);
});

it('saves the host key on the first successful connection', function () {
    fakeSftpServer('ssh-ed25519 '.explode(' ', SFTP_TEST_HOST_KEY)[1], parameters: $parameters);
    $storage = sftpHostKeyStorage($this->team);

    $storage->testConnection(shouldSave: true, acceptNewHostKey: true);

    expect($storage->fresh()->sftp_host_key)->toBe(SFTP_TEST_HOST_KEY)
        ->and($storage->sftpHostKeyFingerprint())->toBe(SFTP_TEST_HOST_KEY_FINGERPRINT)
        ->and($parameters['hostFingerprint'])->toBeNull();
});

it('saves the key type of an RSA host key, not the signature algorithm', function () {
    $blob = base64_encode(pack('N', 7).'ssh-rsa'.pack('N', 1)."\x01".pack('N', 1)."\x02");
    fakeSftpServer('rsa-sha2-512 '.$blob);
    $storage = sftpHostKeyStorage($this->team);

    $storage->testConnection(shouldSave: true, acceptNewHostKey: true);

    expect($storage->fresh()->sftp_host_key)->toBe('ssh-rsa '.$blob);
});

it('checks a saved host key on every connection', function () {
    fakeSftpServer(SFTP_TEST_HOST_KEY, parameters: $parameters);
    $storage = sftpHostKeyStorage($this->team, ['sftp_host_key' => SFTP_TEST_HOST_KEY]);

    $storage->filesystem()->files();

    expect($parameters['hostFingerprint'])->toBe(SFTP_TEST_HOST_KEY_FINGERPRINT)
        ->and($parameters['preferredAlgorithms'])->toBe(['hostkey' => ['ssh-ed25519']]);
});

it('refuses a server whose host key changed', function () {
    fakeSftpServer(SFTP_TEST_HOST_KEY, UnableToEstablishAuthenticityOfHost::becauseTheAuthenticityCantBeEstablished('1.1.1.1'));
    $storage = sftpHostKeyStorage($this->team, ['sftp_host_key' => SFTP_TEST_HOST_KEY]);

    expect(fn () => $storage->testConnection(shouldSave: true))
        ->toThrow(RuntimeException::class, 'The SSH host key of the SFTP server 1.1.1.1 changed.');

    $storage->refresh();
    expect($storage->sftp_host_key)->toBe(SFTP_TEST_HOST_KEY)
        ->and($storage->is_usable)->toBeFalse();
});

it('pins the saved host key in the rclone config', function () {
    $storage = sftpHostKeyStorage($this->team, ['sftp_host_key' => SFTP_TEST_HOST_KEY]);

    expect($storage->rcloneConfig())->toContain("\nhost_keys = ".SFTP_TEST_HOST_KEY."\n");
});

it('refuses to connect to an SFTP storage without a saved host key', function () {
    fakeSftpServer(SFTP_TEST_HOST_KEY, parameters: $parameters);
    $storage = sftpHostKeyStorage($this->team);

    expect(fn () => $storage->filesystem()->files())->toThrow(RuntimeException::class, S3Storage::SFTP_HOST_KEY_MISSING_MESSAGE)
        ->and(fn () => $storage->rcloneConfig())->toThrow(RuntimeException::class, S3Storage::SFTP_HOST_KEY_MISSING_MESSAGE)
        ->and($parameters)->toBeNull()
        ->and($storage->fresh()->sftp_host_key)->toBeNull();
});

it('does not need a host key for other storage types', function (BackupStorageType $type, string $endpoint) {
    $storage = sftpHostKeyStorage($this->team, ['type' => $type, 'endpoint' => $endpoint]);

    expect($storage->rcloneConfig())->toStartWith('[storage]')
        ->and($storage->filesystem())->toBeInstanceOf(FilesystemAdapter::class);
})->with([
    'ftps' => [BackupStorageType::Ftps, 'ftps://1.1.1.1/backups'],
    'webdav' => [BackupStorageType::Webdav, 'https://1.1.1.1/backups'],
]);

it('does not save a host key on an endpoint that changed during the connection test', function () {
    fakeSftpServer(SFTP_TEST_HOST_KEY);
    $storage = sftpHostKeyStorage($this->team);
    S3Storage::query()->whereKey($storage->id)->update(['endpoint' => 'sftp://1.0.0.1:2222/backups']);

    expect(fn () => $storage->testConnection(shouldSave: true, acceptNewHostKey: true))->toThrow(RuntimeException::class);

    $storage = $storage->fresh();
    expect($storage->sftp_host_key)->toBeNull()
        ->and($storage->endpoint)->toBe('sftp://1.0.0.1:2222/backups')
        ->and($storage->is_usable)->toBeFalse();
});

it('does not replace a host key that was saved during the connection test', function () {
    fakeSftpServer(SFTP_TEST_HOST_KEY);
    $storage = sftpHostKeyStorage($this->team);
    $otherKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l1GbjE';
    S3Storage::query()->whereKey($storage->id)->update(['sftp_host_key' => $otherKey]);

    expect(fn () => $storage->testConnection(shouldSave: true, acceptNewHostKey: true))->toThrow(RuntimeException::class);

    expect($storage->fresh()->sftp_host_key)->toBe($otherKey);
});

it('asks for a new connection test when the host key is removed', function () {
    $storage = sftpHostKeyStorage($this->team, ['sftp_host_key' => SFTP_TEST_HOST_KEY]);

    $storage->update(['endpoint' => 'sftp://1.0.0.1:2222/backups']);

    expect($storage->fresh()->is_usable)->toBeFalse();
});

it('removes the saved host key when the server host or port changes', function (string $endpoint, bool $keeps) {
    $storage = sftpHostKeyStorage($this->team, ['sftp_host_key' => SFTP_TEST_HOST_KEY]);

    $storage->update(['endpoint' => $endpoint]);

    expect($storage->fresh()->sftp_host_key)->toBe($keeps ? SFTP_TEST_HOST_KEY : null);
})->with([
    'other host' => ['sftp://1.0.0.1:2222/backups', false],
    'other port' => ['sftp://1.1.1.1:22/backups', false],
    'other directory' => ['sftp://1.1.1.1:2222/other', true],
]);

it('shows the host key fingerprint in the storage form', function () {
    $storage = sftpHostKeyStorage($this->team, ['sftp_host_key' => SFTP_TEST_HOST_KEY]);
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Form::class, ['storage' => $storage])
        ->assertSee(SFTP_TEST_HOST_KEY_FINGERPRINT);
});

it('lets an admin reset the host key, and the next connection test saves it again', function () {
    fakeSftpServer(SFTP_TEST_HOST_KEY);
    $storage = sftpHostKeyStorage($this->team, ['sftp_host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l1GbjE']);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(Form::class, ['storage' => $storage])->call('resetHostKey');
    expect($storage->fresh()->sftp_host_key)->toBeNull()
        ->and($storage->fresh()->is_usable)->toBeFalse();

    $component->call('testConnection')->assertDispatched('success');
    expect($storage->fresh()->sftp_host_key)->toBe(SFTP_TEST_HOST_KEY);
});

it('does not save a new host key from a background connection test', function () {
    fakeSftpServer(SFTP_TEST_HOST_KEY, parameters: $parameters);
    $storage = sftpHostKeyStorage($this->team);

    expect(fn () => $storage->testConnection(shouldSave: true))
        ->toThrow(RuntimeException::class, S3Storage::SFTP_HOST_KEY_MISSING_MESSAGE);

    $storage->refresh();
    expect($parameters)->toBeNull()
        ->and($storage->sftp_host_key)->toBeNull()
        ->and($storage->is_usable)->toBeFalse();
});

it('does not save the host key of an unusable storage when the revalidation job runs', function () {
    fakeSftpServer(SFTP_TEST_HOST_KEY, parameters: $parameters);
    $storage = sftpHostKeyStorage($this->team, ['is_usable' => false]);

    (new RevalidateUnusableS3StoragesJob)->handle();

    $storage->refresh();
    expect($parameters)->toBeNull()
        ->and($storage->sftp_host_key)->toBeNull()
        ->and($storage->is_usable)->toBeFalse();
});

it('still checks a saved host key in a background connection test', function () {
    fakeSftpServer(SFTP_TEST_HOST_KEY, parameters: $parameters);
    $storage = sftpHostKeyStorage($this->team, ['sftp_host_key' => SFTP_TEST_HOST_KEY, 'is_usable' => false]);

    $storage->testConnection(shouldSave: true);

    expect($storage->fresh()->is_usable)->toBeTrue()
        ->and($parameters['hostFingerprint'])->toBe(SFTP_TEST_HOST_KEY_FINGERPRINT);
});

it('does not let a member reset the host key', function () {
    $storage = sftpHostKeyStorage($this->team, ['sftp_host_key' => SFTP_TEST_HOST_KEY]);
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Form::class, ['storage' => $storage])->call('resetHostKey')->assertForbidden();

    expect($storage->fresh()->sftp_host_key)->toBe(SFTP_TEST_HOST_KEY);
});
