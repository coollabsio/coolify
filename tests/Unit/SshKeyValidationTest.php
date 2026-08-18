<?php

namespace Tests\Unit;

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for SSH key validation to prevent sporadic "Permission denied" errors.
 *
 * The root cause: validateSshKey() only checked file existence, not content.
 * When a key was rotated in the DB but the old file persisted on disk,
 * SSH would use the stale key and fail with "Permission denied (publickey)".
 *
 * @see https://github.com/coollabsio/coolify/issues/7724
 */
class SshKeyValidationTest extends TestCase
{
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = sys_get_temp_dir().'/coolify-ssh-test-'.Str::uuid();
        File::ensureDirectoryExists($this->diskRoot);
        config(['filesystems.disks.ssh-keys.root' => $this->diskRoot]);
        app('filesystem')->forgetDisk('ssh-keys');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    private function makePrivateKey(string $keyContent = 'TEST_KEY_CONTENT'): PrivateKey
    {
        $privateKey = new class extends PrivateKey
        {
            public int $storeCallCount = 0;

            public function refresh()
            {
                return $this;
            }

            public function getKeyLocation()
            {
                return Storage::disk('ssh-keys')->path("ssh_key@{$this->uuid}");
            }

            public function storeInFileSystem()
            {
                $this->storeCallCount++;
                $filename = "ssh_key@{$this->uuid}";
                $disk = Storage::disk('ssh-keys');
                $disk->put($filename, $this->private_key);
                $keyLocation = $disk->path($filename);
                chmod($keyLocation, 0600);

                return $keyLocation;
            }
        };

        $privateKey->uuid = (string) Str::uuid();
        $privateKey->private_key = $keyContent;

        return $privateKey;
    }

    private function callValidateSshKey(PrivateKey $privateKey): void
    {
        $reflection = new \ReflectionMethod(SshMultiplexingHelper::class, 'validateSshKey');
        $reflection->setAccessible(true);
        $reflection->invoke(null, $privateKey);
    }

    public function test_validate_ssh_key_rewrites_stale_file_and_fixes_permissions()
    {
        $privateKey = $this->makePrivateKey('NEW_PRIVATE_KEY_CONTENT');

        $filename = "ssh_key@{$privateKey->uuid}";
        $disk = Storage::disk('ssh-keys');
        $disk->put($filename, 'OLD_PRIVATE_KEY_CONTENT');
        $keyPath = $disk->path($filename);
        chmod($keyPath, 0644);

        $this->callValidateSshKey($privateKey);

        $this->assertSame('NEW_PRIVATE_KEY_CONTENT', $disk->get($filename));
        $this->assertSame(1, $privateKey->storeCallCount);
        $this->assertSame(0600, fileperms($keyPath) & 0777);
    }

    public function test_validate_ssh_key_creates_missing_file()
    {
        $privateKey = $this->makePrivateKey('MY_KEY_CONTENT');

        $filename = "ssh_key@{$privateKey->uuid}";
        $disk = Storage::disk('ssh-keys');
        $this->assertFalse($disk->exists($filename));

        $this->callValidateSshKey($privateKey);

        $this->assertTrue($disk->exists($filename));
        $this->assertSame('MY_KEY_CONTENT', $disk->get($filename));
        $this->assertSame(1, $privateKey->storeCallCount);
    }

    public function test_validate_ssh_key_skips_rewrite_when_content_matches()
    {
        $privateKey = $this->makePrivateKey('SAME_KEY_CONTENT');

        $filename = "ssh_key@{$privateKey->uuid}";
        $disk = Storage::disk('ssh-keys');
        $disk->put($filename, 'SAME_KEY_CONTENT');
        $keyPath = $disk->path($filename);
        chmod($keyPath, 0600);

        $this->callValidateSshKey($privateKey);

        $this->assertSame(0, $privateKey->storeCallCount, 'Should not rewrite when content matches');
        $this->assertSame('SAME_KEY_CONTENT', $disk->get($filename));
    }

    public function test_validate_ssh_key_fixes_permissions_without_rewrite()
    {
        $privateKey = $this->makePrivateKey('KEY_CONTENT');

        $filename = "ssh_key@{$privateKey->uuid}";
        $disk = Storage::disk('ssh-keys');
        $disk->put($filename, 'KEY_CONTENT');
        $keyPath = $disk->path($filename);
        chmod($keyPath, 0644);

        $this->callValidateSshKey($privateKey);

        $this->assertSame(0, $privateKey->storeCallCount, 'Should not rewrite when content matches');
        $this->assertSame(0600, fileperms($keyPath) & 0777, 'Should fix permissions even without rewrite');
    }

    public function test_store_in_file_system_enforces_correct_permissions()
    {
        $privateKey = $this->makePrivateKey('KEY_FOR_PERM_TEST');
        $privateKey->storeInFileSystem();

        $filename = "ssh_key@{$privateKey->uuid}";
        $keyPath = Storage::disk('ssh-keys')->path($filename);

        $this->assertSame(0600, fileperms($keyPath) & 0777);
    }

    public function test_store_in_file_system_lock_file_persists()
    {
        // Use the real storeInFileSystem to verify lock file behavior
        $disk = Storage::disk('ssh-keys');
        $uuid = (string) Str::uuid();
        $filename = "ssh_key@{$uuid}";
        $keyLocation = $disk->path($filename);
        $lockFile = $keyLocation.'.lock';

        $privateKey = new class extends PrivateKey
        {
            public function refresh()
            {
                return $this;
            }

            public function getKeyLocation()
            {
                return Storage::disk('ssh-keys')->path("ssh_key@{$this->uuid}");
            }

            protected function ensureStorageDirectoryExists()
            {
                // No-op in test — directory already exists
            }
        };

        $privateKey->uuid = $uuid;
        $privateKey->private_key = 'LOCK_TEST_KEY';

        $privateKey->storeInFileSystem();

        // Lock file should persist (not be deleted) to prevent flock race conditions
        $this->assertFileExists($lockFile, 'Lock file should persist after storeInFileSystem');
    }

    public function test_server_model_detects_private_key_id_changes()
    {
        $reflection = new \ReflectionMethod(\App\Models\Server::class, 'booted');
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $source = implode('', array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString(
            "wasChanged('private_key_id')",
            $source,
            'Server saved event should detect private_key_id changes'
        );
    }
}
