<?php

namespace Tests\Unit;

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SshKeySyncValidationTest extends TestCase
{
    public function test_it_resyncs_out_of_sync_key_and_clears_mux_artifacts(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Storage::fake('ssh-keys');
        Storage::fake('ssh-mux');

        $privateKey = new PrivateKey;
        $privateKey->uuid = 'private-key-uuid';
        $privateKey->private_key = "correct-key\n";

        $server = new Server;
        $server->uuid = 'server-uuid';
        $server->ip = '127.0.0.1';
        $server->port = 22;
        $server->user = 'root';
        $server->setRelation('privateKey', $privateKey);

        Storage::disk('ssh-keys')->put('ssh_key@private-key-uuid', "stale-key\n");
        Storage::disk('ssh-mux')->put($server->muxFilename(), 'mux-socket-content');
        Cache::put("ssh_mux_connection_time_{$server->uuid}", time(), 300);

        $reflection = new \ReflectionMethod(SshMultiplexingHelper::class, 'validateSshKey');
        $reflection->setAccessible(true);
        $reflection->invoke(null, $server);

        $this->assertSame("correct-key\n", Storage::disk('ssh-keys')->get('ssh_key@private-key-uuid'));
        $this->assertFalse(Storage::disk('ssh-mux')->exists($server->muxFilename()));
        $this->assertNull(Cache::get("ssh_mux_connection_time_{$server->uuid}"));
    }
}
