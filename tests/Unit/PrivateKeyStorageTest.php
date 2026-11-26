<?php

use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use phpseclib3\Crypt\EC;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = Team::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);
});

function privateKeyStorageTestKey(): string
{
    return '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----';
}

/**
 * Fakes the ssh-keys disk and wraps it in a partial mock. Calls without an expectation go to the fake disk.
 */
function privateKeyStorageTestDisk(callable $configure): MockInterface
{
    $fakeDisk = Storage::fake('ssh-keys');
    $disk = Mockery::mock($fakeDisk);
    $configure($disk, $fakeDisk);
    Storage::set('ssh-keys', $disk);

    return $disk;
}

function privateKeyStorageTestCreate(Team $team): PrivateKey
{
    return PrivateKey::createAndStore([
        'name' => 'Test Key',
        'description' => 'Test Description',
        'private_key' => privateKeyStorageTestKey(),
        'team_id' => $team->id,
    ]);
}

it('successfully stores private key in filesystem', function () {
    Storage::fake('ssh-keys');

    $privateKey = privateKeyStorageTestCreate($this->team);

    $this->assertDatabaseHas('private_keys', [
        'id' => $privateKey->id,
        'name' => 'Test Key',
    ]);

    $filename = "ssh_key@{$privateKey->uuid}";
    Storage::disk('ssh-keys')->assertExists($filename);

    $storedContent = Storage::disk('ssh-keys')->get($filename);
    expect($storedContent)->toBe($privateKey->private_key);
});

it('throws exception when storage fails', function () {
    privateKeyStorageTestDisk(function (MockInterface $disk, FilesystemAdapter $fakeDisk) {
        $disk->shouldReceive('put')->with(Mockery::pattern('/\A\.test_write_/'), 'test')->andReturnUsing($fakeDisk->put(...));
        $disk->shouldReceive('put')->with(Mockery::pattern('/\Assh_key@/'), Mockery::any())->andReturn(false);
    });

    expect(fn () => privateKeyStorageTestCreate($this->team))
        ->toThrow(Exception::class, 'Failed to write SSH key to filesystem');

    $this->assertDatabaseMissing('private_keys', [
        'name' => 'Test Key',
    ]);
});

it('throws exception when storage directory is not writable', function () {
    privateKeyStorageTestDisk(function (MockInterface $disk, FilesystemAdapter $fakeDisk) {
        $disk->shouldReceive('put')->with(Mockery::pattern('/\A\.test_write_/'), 'test')->andReturn(false);
    });

    expect(fn () => privateKeyStorageTestCreate($this->team))
        ->toThrow(Exception::class, 'SSH keys storage directory is not writable. Run on the host: sudo chown -R 9999 /data/coolify/ssh && sudo chmod -R 700 /data/coolify/ssh && docker restart coolify');
});

it('creates storage directory if not exists', function () {
    $disk = privateKeyStorageTestDisk(function (MockInterface $disk, FilesystemAdapter $fakeDisk) {
        $disk->shouldReceive('exists')->with('')->andReturn(false);
        $disk->shouldReceive('exists')->with(Mockery::pattern('/\Assh_key@/'))->andReturnUsing($fakeDisk->exists(...));
        $disk->shouldReceive('makeDirectory')->with('')->once()->andReturn(true);
    });

    $privateKey = privateKeyStorageTestCreate($this->team);

    $this->assertDatabaseHas('private_keys', [
        'id' => $privateKey->id,
        'name' => 'Test Key',
    ]);
    expect($disk->get("ssh_key@{$privateKey->uuid}"))->toBe($privateKey->private_key);
});

it('throws exception when file content verification fails', function () {
    $disk = privateKeyStorageTestDisk(function (MockInterface $disk, FilesystemAdapter $fakeDisk) {
        $disk->shouldReceive('get')->with(Mockery::pattern('/\Assh_key@/'))->andReturn('corrupted content');
    });

    expect(fn () => privateKeyStorageTestCreate($this->team))
        ->toThrow(Exception::class, 'SSH key file content verification failed');

    $this->assertDatabaseMissing('private_keys', [
        'name' => 'Test Key',
    ]);
    expect(collect($disk->files())->filter(fn (string $file) => preg_match('/\Assh_key@[^.]+\z/', $file) === 1))->toBeEmpty();
});

it('successfully deletes private key from filesystem', function () {
    Storage::fake('ssh-keys');

    $privateKey = privateKeyStorageTestCreate($this->team);

    $filename = "ssh_key@{$privateKey->uuid}";
    Storage::disk('ssh-keys')->assertExists($filename);

    $privateKey->delete();

    Storage::disk('ssh-keys')->assertMissing($filename);
});

it('handles database transaction rollback on storage failure', function () {
    $initialCount = PrivateKey::count();
    privateKeyStorageTestDisk(function (MockInterface $disk, FilesystemAdapter $fakeDisk) {
        $disk->shouldReceive('put')->with(Mockery::pattern('/\A\.test_write_/'), 'test')->andReturnUsing($fakeDisk->put(...));
        $disk->shouldReceive('put')->with(Mockery::pattern('/\Assh_key@/'), Mockery::any())->andReturn(false);
    });

    try {
        privateKeyStorageTestCreate($this->team);
    } catch (Exception) {
    }

    expect(PrivateKey::count())->toBe($initialCount);
    $this->assertDatabaseMissing('private_keys', [
        'name' => 'Test Key',
    ]);
});

it('rewrites the key file when the private key is updated', function () {
    Storage::fake('ssh-keys');

    $privateKey = privateKeyStorageTestCreate($this->team);

    $newPrivateKey = EC::createKey('Ed25519')->toString('OpenSSH');

    $privateKey->update([
        'name' => 'Updated Key',
        'private_key' => $newPrivateKey,
    ]);

    $this->assertDatabaseHas('private_keys', [
        'id' => $privateKey->id,
        'name' => 'Updated Key',
    ]);

    $storedContent = Storage::disk('ssh-keys')->get("ssh_key@{$privateKey->uuid}");
    expect($storedContent)->toBe(formatPrivateKey($newPrivateKey))
        ->and($storedContent)->toBe($privateKey->fresh()->private_key);
});
