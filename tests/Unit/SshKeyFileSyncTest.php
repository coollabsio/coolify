<?php

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SshKeyFileSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    protected function invokeValidate(PrivateKey $privateKey): void
    {
        $method = new ReflectionMethod(SshMultiplexingHelper::class, 'validateSshKey');
        $method->setAccessible(true);
        $method->invoke(null, $privateKey, null);
    }

    /** @test */
    public function it_recreates_missing_ssh_key_file_before_ssh_command_generation()
    {
        Storage::fake('ssh-keys');

        $privateKey = PrivateKey::createAndStore([
            'name' => 'Test Key',
            'description' => 'Test Description',
            'private_key' => $this->getValidPrivateKey(),
            'team_id' => currentTeam()->id,
        ]);

        $filename = "ssh_key@{$privateKey->uuid}";
        Storage::disk('ssh-keys')->delete($filename);
        Storage::disk('ssh-keys')->assertMissing($filename);

        $this->invokeValidate($privateKey);

        Storage::disk('ssh-keys')->assertExists($filename);
        expect(Storage::disk('ssh-keys')->get($filename))->toBe($privateKey->private_key);
    }

    /** @test */
    public function it_rewrites_stale_or_corrupted_ssh_key_file()
    {
        Storage::fake('ssh-keys');

        $privateKey = PrivateKey::createAndStore([
            'name' => 'Test Key',
            'description' => 'Test Description',
            'private_key' => $this->getValidPrivateKey(),
            'team_id' => currentTeam()->id,
        ]);

        $filename = "ssh_key@{$privateKey->uuid}";
        Storage::disk('ssh-keys')->put($filename, 'corrupted-key-content');

        $this->invokeValidate($privateKey);

        expect(Storage::disk('ssh-keys')->get($filename))->toBe($privateKey->private_key);
    }
}
