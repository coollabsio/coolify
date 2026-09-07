<?php

namespace App\Services\ServerTransfer;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ServerTransferClaimer
{
    /**
     * Claim a managed host for this Coolify instance.
     *
     * Database ownership (metadata + Sentinel settings) is committed in one transaction so a
     * mid-flight failure rolls back. Remote SSH claim-file writes happen after commit and are
     * best-effort — they cannot participate in the DB transaction.
     *
     * @return array{
     *     server_uuid: string,
     *     claim: array<string, mixed>,
     *     sentinel_rebound: bool,
     *     claim_written: bool,
     *     message: string
     * }
     */
    public function claim(Server $server, bool $writeRemote = true, bool $rebindSentinel = true): array
    {
        if ($server->id === 0) {
            throw new RuntimeException('Cannot claim the Coolify host itself.');
        }

        $instanceUrl = rtrim((string) (instanceSettings()->fqdn ?: config('app.url')), '/');
        if ($instanceUrl === '') {
            throw new RuntimeException('Instance URL (FQDN or APP_URL) must be set before claiming a server.');
        }

        $exportId = data_get($server->server_metadata, 'transfer.export_id');

        $claim = [
            'instance_url' => $instanceUrl,
            'server_uuid' => $server->uuid,
            'team_id' => $server->team_id,
            'claimed_at' => now()->toIso8601String(),
            'export_id' => $exportId,
            'schema_version' => ServerTransferBundle::SCHEMA_VERSION,
        ];

        $result = DB::transaction(function () use ($server, $claim, $rebindSentinel, $instanceUrl) {
            $server = Server::query()->with('settings')->lockForUpdate()->findOrFail($server->id);

            $sentinelRebound = false;
            if ($rebindSentinel && $server->settings) {
                $server->settings->sentinel_custom_url = $instanceUrl;
                $server->settings->ensureValidSentinelToken();
                // Leave sentinel disabled until operator enables metrics; endpoint is ready.
                $server->settings->save();
                $sentinelRebound = true;
            }

            $metadata = $server->server_metadata ?? [];
            $metadata['transfer'] = array_merge((array) data_get($metadata, 'transfer', []), [
                'status' => 'claimed',
                'claimed_at' => $claim['claimed_at'],
                'claim' => $claim,
                'claim_written' => false,
                'sentinel_rebound' => $sentinelRebound,
            ]);
            $server->server_metadata = $metadata;
            $server->save();

            return [
                'server_uuid' => $server->uuid,
                'claim' => $claim,
                'sentinel_rebound' => $sentinelRebound,
                'claim_written' => false,
            ];
        });

        // Remote host I/O is outside the transaction (cannot be rolled back with DB rows).
        $claimWritten = false;
        if ($writeRemote) {
            $claimWritten = $this->writeClaimFile($server, $claim);
            if ($claimWritten) {
                $this->persistClaimWritten($server);
            }
        }

        $result['claim_written'] = $claimWritten;
        $result['message'] = $claimWritten
            ? 'Server claimed. Ownership file written and Sentinel rebound to this instance.'
            : 'Server claimed in Coolify. Remote ownership file was not written (SSH unavailable or skipped).';

        return $result;
    }

    /**
     * Mark a server as transferred away from this instance (source side).
     * Disables automations to prevent dual management.
     *
     * All database writes run in one transaction so partial disable state cannot stick on failure.
     * Local SSH key cache cleanup runs after commit (filesystem; not transactional).
     *
     * @return array{server_uuid: string, message: string}
     */
    public function markTransferred(Server $server, ?string $exportId = null, ?string $targetInstanceUrl = null): array
    {
        if ($server->id === 0) {
            throw new RuntimeException('Cannot transfer the Coolify host itself.');
        }

        $result = DB::transaction(function () use ($server, $exportId, $targetInstanceUrl) {
            $server = Server::query()->with('settings')->lockForUpdate()->findOrFail($server->id);

            if ($server->settings) {
                $server->settings->force_disabled = true;
                $server->settings->is_sentinel_enabled = false;
                $server->settings->save();
            }

            $metadata = $server->server_metadata ?? [];
            $metadata['transfer'] = array_merge((array) data_get($metadata, 'transfer', []), [
                'status' => 'transferred',
                'export_id' => $exportId ?? data_get($metadata, 'transfer.export_id'),
                'target_instance_url' => $targetInstanceUrl,
                'transferred_at' => now()->toIso8601String(),
            ]);
            $server->server_metadata = $metadata;
            $server->save();

            return [
                'server_uuid' => $server->uuid,
                'message' => 'Server marked as transferred. Automations disabled on this instance.',
            ];
        });

        $this->clearLocalSshArtifacts($server);

        return $result;
    }

    private function persistClaimWritten(Server $server): void
    {
        DB::transaction(function () use ($server) {
            $server = Server::query()->lockForUpdate()->find($server->id);
            if (! $server) {
                return;
            }

            $metadata = $server->server_metadata ?? [];
            data_set($metadata, 'transfer.claim_written', true);
            $server->server_metadata = $metadata;
            $server->save();
        });
    }

    /**
     * Best-effort local SSH mux/key cleanup after transfer (filesystem; not part of DB rollback).
     * forceDisableServer is idempotent for force_disabled and also clears cached SSH key material.
     */
    private function clearLocalSshArtifacts(Server $server): void
    {
        try {
            $server->refresh();
            $server->forceDisableServer();
        } catch (Throwable $e) {
            Log::warning('Failed to clear local SSH artifacts after transfer', [
                'server_uuid' => $server->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write export bundle to the managed host mailbox (air-gapped transfer).
     *
     * @param  array<string, mixed>  $bundle
     * @return array{path: string, written: bool}
     */
    public function writeMailbox(Server $server, array $bundle, ?string $passphrase = null): array
    {
        ServerTransferBundle::assertValid($bundle);

        $exportId = (string) data_get($bundle, 'export_id', new_public_id());
        $filename = "server-transfer-{$exportId}.coolify.json";
        $path = ServerTransferBundle::MAILBOX_DIR.'/'.$filename;

        $payload = $passphrase
            ? ServerTransferBundle::encryptWithPassphrase($bundle, $passphrase)
            : $bundle;

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $b64 = base64_encode($json);

        $written = false;
        try {
            instant_remote_process([
                'mkdir -p '.escapeshellarg(ServerTransferBundle::MAILBOX_DIR),
                'echo '.escapeshellarg($b64).' | base64 -d > '.escapeshellarg($path),
                'chmod 600 '.escapeshellarg($path),
                'chown 9999:root '.escapeshellarg($path).' || true',
            ], $server, true);
            $written = true;
        } catch (Throwable $e) {
            Log::warning('Failed to write server transfer mailbox', [
                'server_uuid' => $server->uuid,
                'error' => $e->getMessage(),
            ]);
            if (! app()->runningUnitTests()) {
                throw $e;
            }
        }

        return [
            'path' => $path,
            'written' => $written,
        ];
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function writeClaimFile(Server $server, array $claim): bool
    {
        $json = json_encode($claim, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $b64 = base64_encode($json);
        $path = ServerTransferBundle::CLAIM_PATH;

        try {
            instant_remote_process([
                'mkdir -p /data/coolify',
                'echo '.escapeshellarg($b64).' | base64 -d > '.escapeshellarg($path),
                'chmod 600 '.escapeshellarg($path),
                'chown 9999:root '.escapeshellarg($path).' || true',
            ], $server, true);

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to write instance claim file', [
                'server_uuid' => $server->uuid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
