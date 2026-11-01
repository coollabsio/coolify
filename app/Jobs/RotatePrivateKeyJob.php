<?php

namespace App\Jobs;

use App\Actions\Server\AuthorizedKeys;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Moves every server that uses the old key to the new key. A server moves only after the new key logs in.
 * Servers that fail stay on the old key, so the rotation can run again.
 */
class RotatePrivateKeyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 1800;

    public const RESULT_TTL_SECONDS = 86400;

    public function __construct(
        public int $oldPrivateKeyId,
        public int $newPrivateKeyId,
        public ?int $startedByUserId = null,
    ) {}

    public static function cacheKey(PrivateKey $privateKey): string
    {
        return "private-key-rotation:{$privateKey->uuid}";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("rotate-private-key-{$this->oldPrivateKeyId}"))->expireAfter($this->timeout + 60)->dontRelease()];
    }

    public function handle(): void
    {
        $oldKey = PrivateKey::find($this->oldPrivateKeyId);
        if (! $oldKey) {
            return;
        }
        $newKey = PrivateKey::where('team_id', $oldKey->team_id)->find($this->newPrivateKeyId);
        $state = Cache::get(self::cacheKey($oldKey), []);
        if (! $newKey) {
            $this->storeState($oldKey, [...$state, 'status' => 'failed', 'error' => 'The new key does not exist anymore.']);

            return;
        }

        $state = [...$state, 'status' => 'running', 'new_private_key_id' => $newKey->id, 'servers' => []];
        $servers = Server::where('team_id', $oldKey->team_id)->where('private_key_id', $oldKey->id)->get();
        foreach ($servers as $server) {
            $result = $this->rotateServer($server, $oldKey, $newKey);
            $state['servers'][$server->uuid] = ['name' => $server->name, ...$result];
            $this->storeState($oldKey, $state);

            auditLog('system.server.ssh_key_rotated', [
                'team_id' => $oldKey->team_id,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'old_private_key_uuid' => $oldKey->uuid,
                'new_private_key_uuid' => $newKey->uuid,
                'result' => $result['status'],
            ], $result['status'] === 'moved' ? 'info' : 'warning');
        }

        $counts = collect($state['servers'])->countBy('status')->all();
        $this->storeState($oldKey, [...$state, 'status' => 'finished']);
        auditLog('system.private_key.rotated', [
            'team_id' => $oldKey->team_id,
            'private_key_uuid' => $oldKey->uuid,
            'private_key_name' => $oldKey->name,
            'new_private_key_uuid' => $newKey->uuid,
            'started_by_user_id' => $this->startedByUserId,
            'servers' => $counts,
        ], array_diff(array_keys($counts), ['moved']) === [] ? 'info' : 'warning');
    }

    public function failed(?Throwable $exception): void
    {
        $oldKey = PrivateKey::find($this->oldPrivateKeyId);
        if ($oldKey) {
            $this->storeState($oldKey, [...Cache::get(self::cacheKey($oldKey), []), 'status' => 'failed', 'error' => 'The rotation stopped. Start it again.']);
        }
    }

    /**
     * @return array{status: 'moved'|'moved_old_not_removed'|'failed'|'unreachable', message: string}
     */
    private function rotateServer(Server $server, PrivateKey $oldKey, PrivateKey $newKey): array
    {
        if (! AuthorizedKeys::canRunOn($server)) {
            return ['status' => 'unreachable', 'message' => 'The server is disabled or has no IP address.'];
        }

        try {
            AuthorizedKeys::add($server, $newKey, $oldKey);
        } catch (Throwable $e) {
            return ['status' => 'unreachable', 'message' => 'Could not connect: '.$this->errorWithoutHost($server, $e)];
        }

        $probe = clone $server;
        $probe->private_key_id = $newKey->id;
        $probe->setRelation('privateKey', $newKey);
        ['uptime' => $newKeyWorks] = $probe->validateConnection(justCheckingNewKey: true);
        if (! $newKeyWorks) {
            try {
                AuthorizedKeys::remove($server, $newKey);
            } catch (Throwable) {
                // The server stays on the old key. The next rotation adds the same new key again.
            }

            return ['status' => 'failed', 'message' => 'The new key could not log in. Check the AuthorizedKeysFile setting of sshd.'];
        }

        // The saved hook closes the multiplexed connection, so the next command logs in with the new key.
        $server->privateKey()->associate($newKey);
        $server->save();

        try {
            if (! AuthorizedKeys::remove($server, $oldKey)) {
                return ['status' => 'moved_old_not_removed', 'message' => 'The old key stays, because another server for this host uses it.'];
            }
        } catch (Throwable $e) {
            return ['status' => 'moved_old_not_removed', 'message' => 'The old key was not removed: '.$this->errorWithoutHost($server, $e)];
        }

        return ['status' => 'moved', 'message' => 'Moved to the new key.'];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function storeState(PrivateKey $oldKey, array $state): void
    {
        Cache::put(self::cacheKey($oldKey), $state, self::RESULT_TTL_SECONDS);
    }

    /** The UI shows server names only, so remove the host address from SSH errors. */
    private function errorWithoutHost(Server $server, Throwable $e): string
    {
        $message = str_replace((string) $server->ip, 'the server', remove_iip($e->getMessage()));

        return trim(preg_replace('/^ssh: connect to host the server port \d+:\s*/i', '', $message));
    }
}
