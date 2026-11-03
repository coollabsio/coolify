<?php

namespace App\Jobs;

use App\Actions\Server\AuthorizedKeys;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Removes the old key from a server some time after a rotation moved the server to the new key.
 * Deployments, backups, and scheduled tasks that started before the rotation still log in with the old key.
 * Safe to run again: removing a key that is not on the server changes nothing.
 */
class RemoveRotatedAuthorizedKeyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The shortest wait before the old key is removed. A server waits at least its deployment timeout. */
    public const GRACE_SECONDS = 3600;

    public $tries = 3;

    public $backoff = [300, 1800];

    public $timeout = 300;

    /**
     * @param  string  $oldPublicKeyBody  The public key, so the job works after the old key record is deleted.
     */
    public function __construct(
        public int $serverId,
        public int $oldPrivateKeyId,
        public string $oldPrivateKeyUuid,
        public string $oldPublicKeyBody,
    ) {}

    public static function delaySeconds(Server $server): int
    {
        return max(self::GRACE_SECONDS, (int) data_get($server, 'settings.dynamic_timeout') + 300);
    }

    public function handle(): void
    {
        $server = Server::find($this->serverId);
        if (! $server) {
            return;
        }
        if ($this->serverUsesOldKey($server)) {
            $this->report($server, 'moved_old_not_removed', 'The old key stays on the server, because the server uses it again.');

            return;
        }
        if (! AuthorizedKeys::canRunOn($server)) {
            $this->report($server, 'moved_old_not_removed', 'The old key stays on the server, because Coolify does not run commands on it.');

            return;
        }
        if (! AuthorizedKeys::removePublicKey($server, $this->oldPublicKeyBody, $this->oldPrivateKeyId)) {
            $this->report($server, 'moved_old_not_removed', 'The old key stays on the server, because another server for this host uses it.');

            return;
        }

        $this->report($server, 'moved', 'Moved to the new key. The old key was removed.');
    }

    public function failed(?Throwable $exception): void
    {
        $server = Server::find($this->serverId);
        if ($server) {
            $message = $exception ? RotatePrivateKeyJob::errorWithoutHost($server, $exception) : 'The job stopped.';
            $this->report($server, 'moved_old_not_removed', 'The old key was not removed: '.$message);
        }
    }

    private function serverUsesOldKey(Server $server): bool
    {
        if ($server->private_key_id === $this->oldPrivateKeyId) {
            return true;
        }
        $currentKey = $server->privateKey;
        $oldFingerprint = AuthorizedKeys::fingerprint($this->oldPublicKeyBody);
        if (! $currentKey || ! $oldFingerprint) {
            return false;
        }

        return ($currentKey->fingerprint ?: PrivateKey::generateFingerprint($currentKey->private_key)) === $oldFingerprint;
    }

    /** Updates the row of the server in the result of the rotation, while the rotation still waits for this job. */
    private function report(Server $server, string $status, string $message): void
    {
        auditLog('system.server.ssh_key_old_removed', [
            'team_id' => $server->team_id,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'old_private_key_uuid' => $this->oldPrivateKeyUuid,
            'result' => $status,
        ], $status === 'moved' ? 'info' : 'warning');

        $cacheKey = RotatePrivateKeyJob::cacheKey($this->oldPrivateKeyUuid);
        $state = Cache::get($cacheKey);
        if (data_get($state, "servers.{$server->uuid}.status") !== RotatePrivateKeyJob::OLD_KEY_PENDING) {
            return;
        }
        $state['servers'][$server->uuid] = [...$state['servers'][$server->uuid], 'status' => $status, 'message' => $message];
        Cache::put($cacheKey, $state, RotatePrivateKeyJob::RESULT_TTL_SECONDS);
    }
}
