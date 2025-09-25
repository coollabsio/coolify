<?php

use App\Models\PrivateKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateKeyStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up a test team for the tests
        $this->actingAs(\App\Models\User::factory()->create());
    }

    protected function getValidPrivateKey(): string
    {
        return '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----';
    }

    /** @test */
    public function it_successfully_stores_private_key_in_filesystem()
    {
        Storage::fake('ssh-keys');

        $privateKey = PrivateKey::createAndStore([
            'name' => 'Test Key',
            'description' => 'Test Description',
            'private_key' => $this->getValidPrivateKey(),
            'team_id' => currentTeam()->id,
        ]);

        $this->assertDatabaseHas('private_keys', [
            'id' => $privateKey->id,
            'name' => 'Test Key',
        ]);

        $filename = "ssh_key@{$privateKey->uuid}";
        Storage::disk('ssh-keys')->assertExists($filename);

        $storedContent = Storage::disk('ssh-keys')->get($filename);
        $this->assertEquals($privateKey->private_key, $storedContent);
    }

    /** @test */
    public function it_throws_exception_when_storage_fails()
    {
        Storage::fake('ssh-keys');

        // Mock Storage::put to return false (simulating storage failure)
        Storage::shouldReceive('disk')
            ->with('ssh-keys')
            ->andReturn(
                \Mockery::mock()
                    ->shouldReceive('exists')
                    ->andReturn(true)
                    ->shouldReceive('put')
                    ->with(\Mockery::any(), 'test')
                    ->andReturn(true)
                    ->shouldReceive('delete')
                    ->with(\Mockery::any())
                    ->andReturn(true)
                    ->shouldReceive('put')
                    ->with(\Mockery::pattern('/ssh_key@/'), \Mockery::any())
                    ->andReturn(false) // Simulate storage failure
                    ->getMock()
            );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to write SSH key to filesystem');

        PrivateKey::createAndStore([
            'name' => 'Test Key',
            'description' => 'Test Description',
            'private_key' => $this->getValidPrivateKey(),
            'team_id' => currentTeam()->id,
        ]);

        // Assert that no database record was created due to transaction rollback
        $this->assertDatabaseMissing('private_keys', [
            'name' => 'Test Key',
        ]);
    }

    /** @test */
    public function it_throws_exception_when_storage_directory_is_not_writable()
    {
        Storage::fake('ssh-keys');

        // Mock Storage disk to simulate directory not writable
        Storage::shouldReceive('disk')
            ->with('ssh-keys')
            ->andReturn(
                \Mockery::mock()
                    ->shouldReceive('exists')
                    ->with('')
                    ->andReturn(true)
                    ->shouldReceive('put')
                    ->with(\Mockery::pattern('/\.test_write_/'), 'test')
                    ->andReturn(false) // Simulate directory not writable
                    ->getMock()
            );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SSH keys storage directory is not writable');

        PrivateKey::createAndStore([
            'name' => 'Test Key',
            'description' => 'Test Description',
            'private_key' => $this->getValidPrivateKey(),
            'team_id' => currentTeam()->id,
        ]);
    }

    /** @test */
    public function it_creates_storage_directory_if_not_exists()
    {
        Storage::fake('ssh-keys');

        // Mock Storage disk to simulate directory not existing, then being created
        Storage::shouldReceive('disk')
            ->with('ssh-keys')
            ->andReturn(
                \Mockery::mock()
                    ->shouldReceive('exists')
                    ->with('')
                    ->andReturn(false) // Directory doesn't exist
                    ->shouldReceive('makeDirectory')
                    ->with('')
                    ->andReturn(true) // Successfully create directory
                    ->shouldReceive('put')
                    ->with(\Mockery::pattern('/\.test_write_/'), 'test')
                    ->andReturn(true) // Directory is writable after creation
                    ->shouldReceive('delete')
                    ->with(\Mockery::pattern('/\.test_write_/'))
                    ->andReturn(true)
                    ->shouldReceive('put')
                    ->with(\Mockery::pattern('/ssh_key@/'), \Mockery::any())
                    ->andReturn(true)
                    ->shouldReceive('exists')
                    ->with(\Mockery::pattern('/ssh_key@/'))
                    ->andReturn(true)
                    ->shouldReceive('get')
                    ->with(\Mockery::pattern('/ssh_key@/'))
                    ->andReturn($this->getValidPrivateKey())
                    ->getMock()
            );

        $privateKey = PrivateKey::createAndStore([
            'name' => 'Test Key',
            'description' => 'Test Description',
            'private_key' => $this->getValidPrivateKey(),
            'team_id' => currentTeam()->id,
        ]);

        $this->assertDatabaseHas('private_keys', [
            'id' => $privateKey->id,
            'name' => 'Test Key',
        ]);
    }

    /** @test */
    public function it_throws_exception_when_file_content_verification_fails()
    {
        Storage::fake('ssh-keys');

        // Mock Storage disk to simulate file being created but with wrong content
        Storage::shouldReceive('disk')
            ->with('ssh-keys')
            ->andReturn(
                \Mockery::mock()
                    ->shouldReceive('exists')
                    ->with('')
                    ->andReturn(true)
                    ->shouldReceive('put')
                    ->with(\Mockery::pattern('/\.test_write_/'), 'test')
                    ->andReturn(true)
                    ->shouldReceive('delete')
                    ->with(\Mockery::pattern('/\.test_write_/'))
                    ->andReturn(true)
                    ->shouldReceive('put')
                    ->with(\Mockery::pattern('/ssh_key@/'), \Mockery::any())
                    ->andReturn(true) // File created successfully
                    ->shouldReceive('exists')
                    ->with(\Mockery::pattern('/ssh_key@/'))
                    ->andReturn(true) // File exists
                    ->shouldReceive('get')
                    ->with(\Mockery::pattern('/ssh_key@/'))
                    ->andReturn('corrupted content') // But content is wrong
                    ->shouldReceive('delete')
                    ->with(\Mockery::pattern('/ssh_key@/'))
                    ->andReturn(true) // Clean up bad file
                    ->getMock()
            );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SSH key file content verification failed');

        PrivateKey::createAndStore([
            'name' => 'Test Key',
            'description' => 'Test Description',
            'private_key' => $this->getValidPrivateKey(),
            'team_id' => currentTeam()->id,
        ]);

        // Assert that no database record was created due to transaction rollback
        $this->assertDatabaseMissing('private_keys', [
            'name' => 'Test Key',
        ]);
    }

    /** @test */
    public function it_successfully_deletes_private_key_from_filesystem()
    {
        Storage::fake('ssh-keys');

        $privateKey = PrivateKey::createAndStore([
            'name' => 'Test Key',
            'description' => 'Test Description',
            'private_key' => $this->getValidPrivateKey(),
            'team_id' => currentTeam()->id,
        ]);

        $filename = "ssh_key@{$privateKey->uuid}";
        Storage::disk('ssh-keys')->assertExists($filename);

        $privateKey->delete();

        Storage::disk('ssh-keys')->assertMissing($filename);
    }

    /** @test */
    public function it_handles_database_transaction_rollback_on_storage_failure()
    {
        Storage::fake('ssh-keys');

        // Count initial private keys
        $initialCount = PrivateKey::count();

        // Mock storage failure after database save
        Storage::shouldReceive('disk')
            ->with('ssh-keys')
            ->andReturn(
                \Mockery::mock()
                    ->shouldReceive('exists')
                    ->with('')
                    ->andReturn(true)
                    ->shouldReceive('put')
                    ->with(\Mockery::pattern('/\.test_write_/'), 'test')
                    ->andReturn(true)
                    ->shouldReceive('delete')
                    ->with(\Mockery::pattern('/\.test_write_/'))
                    ->andReturn(true)
                    ->shouldReceive('put')
                    ->with(\Mockery::pattern('/ssh_key@/'), \Mockery::any())
                    ->andReturn(false) // Storage fails
                    ->getMock()
            );

        try {
            PrivateKey::createAndStore([
                'name' => 'Test Key',
                'description' => 'Test Description',
                'private_key' => $this->getValidPrivateKey(),
                'team_id' => currentTeam()->id,
            ]);
        } catch (\Exception $e) {
            // Expected exception
        }

        // Assert that database was rolled back
        $this->assertEquals($initialCount, PrivateKey::count());
        $this->assertDatabaseMissing('private_keys', [
            'name' => 'Test Key',
        ]);
    }

    /** @test */
    public function it_successfully_updates_private_key_with_transaction()
    {
        Storage::fake('ssh-keys');

        $privateKey = PrivateKey::createAndStore([
            'name' => 'Test Key',
            'description' => 'Test Description',
            'private_key' => $this->getValidPrivateKey(),
            'team_id' => currentTeam()->id,
        ]);

        $newPrivateKey = str_replace('Test', 'Updated', $this->getValidPrivateKey());

        $privateKey->updatePrivateKey([
            'name' => 'Updated Key',
            'private_key' => $newPrivateKey,
        ]);

        $this->assertDatabaseHas('private_keys', [
            'id' => $privateKey->id,
            'name' => 'Updated Key',
        ]);

        $filename = "ssh_key@{$privateKey->uuid}";
        $storedContent = Storage::disk('ssh-keys')->get($filename);
        $this->assertEquals($newPrivateKey, $storedContent);
    }
}
