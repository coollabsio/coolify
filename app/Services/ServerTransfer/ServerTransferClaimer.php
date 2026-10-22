<?php

namespace App\Services\ServerTransfer;

use App\Enums\GithubRunnerStatus;
use App\Jobs\DeleteResourceJob;
use App\Models\GithubRunnerExecution;
use App\Models\ManagedDnsRecord;
use App\Models\Server;
use App\Notifications\Internal\GeneralNotification;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ServerTransferClaimer
{
    /**
     * The managed host records which claim owns it. Both instances can reach the host, so it is the one
     * place where an instance can learn that another instance took the server over.
     */
    public const OWNER_MARKER_DIR = '/data/coolify/transfer';

    public const OWNER_MARKER_PATH = self::OWNER_MARKER_DIR.'/owner.json';

    /**
     * Take management of a server on this Coolify instance and bind Sentinel to it.
     *
     * Database ownership (metadata + Sentinel settings) is committed in one transaction so a
     * mid-flight failure rolls back. The owner marker on the host is written last in that
     * transaction: if the host cannot be reached, the claim fails and nothing changes here.
     *
     * @return array{
     *     server_uuid: string,
     *     claim: array<string, mixed>,
     *     message: string
     * }
     */
    public function claim(Server $server): array
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
            'claim_id' => new_public_id(),
            'instance_url' => $instanceUrl,
            'server_uuid' => $server->uuid,
            'team_id' => $server->team_id,
            'claimed_at' => now()->toIso8601String(),
            'export_id' => $exportId,
            'schema_version' => ServerTransferBundle::SCHEMA_VERSION,
        ];

        return DB::transaction(function () use ($server, $claim, $instanceUrl) {
            $server = Server::query()->with('settings')->lockForUpdate()->findOrFail($server->id);

            // A disabled server refuses SSH, so the owner marker could not be written.
            if ($server->team->serverOverflow()) {
                throw new RuntimeException('Your team is over its server limit. Upgrade your subscription or remove a server first.');
            }

            if ($server->settings) {
                $server->settings->sentinel_custom_url = $instanceUrl;
                $server->settings->ensureValidSentinelToken();
                $server->settings->is_sentinel_enabled = true;
                $server->settings->force_disabled = false;
                $server->settings->save();
            }

            $metadata = $server->server_metadata ?? [];
            $metadata['transfer'] = array_merge((array) data_get($metadata, 'transfer', []), [
                'status' => 'claimed',
                'claimed_at' => $claim['claimed_at'],
                'claim' => $claim,
            ]);
            $server->server_metadata = $metadata;
            $server->save();

            $this->writeOwnerMarker($server, $claim);

            return [
                'server_uuid' => $server->uuid,
                'claim' => $claim,
                'message' => 'This Coolify instance now manages the server.',
            ];
        });
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
            unset($metadata['transfer']['finish'], $metadata['transfer']['taken_back_at']);
            $server->server_metadata = $metadata;
            $server->save();

            $this->releaseGithubRunners($server);

            // Kept for a take back. Not owned, so this instance never changes or deletes them at the provider.
            $metadata['transfer']['parked_dns_record_ids'] = app(ManagedDnsRecordCleanup::class)->parkResources($this->dnsResources($server));
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

    /**
     * The runners on the server belong to the target now. Queued runners are cancelled. Started runners are marked
     * finished without removing them at GitHub: their jobs keep running, and deleting the archive here later must
     * not deregister them. A disabled config also stops new runners for this server from GitHub webhooks.
     */
    /**
     * The other instance serves the hostnames of a transferred server. Without this, deleting the archive here,
     * or the orphaned-record cleanup after it, would delete live DNS records at the provider.
     */
    public function forgetDnsRecords(Server $server): void
    {
        app(ManagedDnsRecordCleanup::class)->forgetResources($this->dnsResources($server));
    }

    /**
     * Delete the archive of a transferred server with its resources, records only: another instance manages the
     * host, so nothing runs on the server and nothing changes at a provider.
     */
    public function deleteArchive(Server $server): void
    {
        if (! $server->isTransferredAway()) {
            throw new RuntimeException('Only the archive of a transferred server can be deleted this way.');
        }

        $this->forgetDnsRecords($server);
        foreach ($server->definedResources() as $resource) {
            (new DeleteResourceJob($resource, deleteFromCoolifyOnly: true))->handle();
        }
        $server->forceDelete();
    }

    /**
     * @return Collection<int, Model>
     */
    private function dnsResources(Server $server): Collection
    {
        // The server itself owns the DNS record of its Cloudflare tunnel.
        return collect([$server])
            ->concat($server->applications())
            ->concat($server->services()->with('applications')->get()->flatMap->applications);
    }

    /**
     * After a take back: own the parked DNS records again where Cloudflare confirms them, then start the steps that
     * finish the take back (webhooks, runners, records that this instance could not take back). Provider calls run
     * here, after the claim committed.
     *
     * @return list<array<string, mixed>> the steps
     */
    public function restoreAfterTakeBack(Server $server): array
    {
        $dns = app(CloudflareDnsProvider::class);
        $parkedIds = (array) data_get($server->server_metadata, 'transfer.parked_dns_record_ids', []);
        foreach (ManagedDnsRecord::query()->whereIn('id', $parkedIds)->get() as $record) {
            if ($dns->reclaimRecord($record)) {
                $record->update(['owned' => true]);
            }
        }

        DB::transaction(function () use ($server) {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            $metadata = $locked->server_metadata ?? [];
            unset($metadata['transfer']['parked_dns_record_ids']);
            $metadata['transfer']['taken_back_at'] = now()->toIso8601String();
            $locked->server_metadata = $metadata;
            $locked->save();
            $server->server_metadata = $metadata;
        });

        return app(TransferFinishChecklist::class)->start($server);
    }

    private function releaseGithubRunners(Server $server): void
    {
        $server->githubRunnerConfig()->update(['is_enabled' => false]);

        $message = 'The server was transferred to another Coolify instance. This instance stopped managing its runners.';
        GithubRunnerExecution::query()
            ->where('server_id', $server->id)
            ->whereIn('status', GithubRunnerStatus::active())
            ->get()
            ->each(fn (GithubRunnerExecution $execution) => $execution->finish(GithubRunnerStatus::Cancelled, $message));
    }

    /**
     * Stop managing a claimed server when its owner marker names a claim of another instance.
     * A server that was added here without a claim ignores the marker: a marker can be left over on a
     * host that an old instance deleted, and the next claim overwrites it.
     *
     * @return bool true when this instance released the server
     */
    public function releaseIfClaimedElsewhere(Server $server, ?string $markerJson): bool
    {
        if ($server->id === 0 || data_get($server->server_metadata, 'transfer.status') !== 'claimed') {
            return false;
        }

        $marker = json_decode((string) $markerJson, true);
        $markerClaimId = data_get($marker, 'claim_id');
        if (! is_string($markerClaimId) || $markerClaimId === '') {
            return false;
        }

        if ($markerClaimId === data_get($server->server_metadata, 'transfer.claim.claim_id')) {
            return false;
        }

        $owner = data_get($marker, 'instance_url');
        $owner = is_string($owner) && $owner !== '' ? $owner : null;

        $this->markTransferred($server, targetInstanceUrl: $owner);

        Log::warning('Server is claimed by another Coolify instance; management stopped here', [
            'server_uuid' => $server->uuid,
            'owner_instance_url' => $owner,
        ]);
        $server->team?->notify(new GeneralNotification(
            "Server '{$server->name}' is now managed by ".($owner ?? 'another Coolify instance').'. This instance stopped managing it.',
            success: false,
        ));

        return true;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function writeOwnerMarker(Server $server, array $claim): void
    {
        $marker = json_encode([
            'claim_id' => $claim['claim_id'],
            'instance_url' => $claim['instance_url'],
            'server_uuid' => $claim['server_uuid'],
            'claimed_at' => $claim['claimed_at'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        instant_remote_process(['mkdir -p '.self::OWNER_MARKER_DIR], $server);
        instant_remote_write_file($server, self::OWNER_MARKER_PATH, $marker);
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
}
