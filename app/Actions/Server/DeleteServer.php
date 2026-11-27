<?php

namespace App\Actions\Server;

use App\Jobs\DeleteResourceJob;
use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Notifications\Server\HetznerDeletionFailed;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\HostingerService;
use App\Services\VultrService;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteServer
{
    use AsAction;

    public function handle(int $serverId, bool $deleteFromHetzner = false, ?int $hetznerServerId = null, ?int $cloudProviderTokenId = null, ?int $teamId = null, bool $deleteFromVultr = false, ?string $vultrInstanceId = null, bool $deleteFromDigitalOcean = false, ?int $digitalOceanDropletId = null, bool $deleteFromHostinger = false, ?int $hostingerVirtualMachineId = null, bool $removeResourcesFromServer = true)
    {
        $server = Server::withTrashed()->find($serverId);
        // Another Coolify instance manages the cloud server of a transferred server.
        if ($server?->isTransferredAway()) {
            $deleteFromHetzner = $deleteFromVultr = $deleteFromDigitalOcean = $deleteFromHostinger = false;
        }

        // Before the cloud server is deleted, while the containers can still be removed.
        // A deleted cloud server takes its containers with it, and another instance runs those of a transferred server.
        $deletesCloudServer = $deleteFromHetzner || $deleteFromVultr || $deleteFromDigitalOcean || $deleteFromHostinger;
        $resourcesDeleted = ! $server || $this->deleteResources(
            $server,
            removeFromServer: $removeResourcesFromServer && ! $deletesCloudServer && ! $server->isTransferredAway(),
        );

        // A Sentinel that keeps running would push to Coolify for a server that no longer exists.
        if ($server && ! $server->isTransferredAway() && ! $deleteFromHetzner && ! $deleteFromVultr && ! $deleteFromDigitalOcean) {
            $this->stopSentinel($server);
        }

        // Delete from Hetzner even if server is already gone from Coolify
        if ($deleteFromHetzner && ($hetznerServerId || ($server && $server->hetzner_server_id))) {
            $this->deleteFromHetznerById(
                $hetznerServerId ?? $server->hetzner_server_id,
                $cloudProviderTokenId ?? $server->cloud_provider_token_id,
                $teamId ?? $server->team_id
            );
        }

        if ($deleteFromVultr && ($vultrInstanceId || ($server && $server->vultr_instance_id))) {
            $this->deleteFromVultrById(
                $vultrInstanceId ?? $server->vultr_instance_id,
                $cloudProviderTokenId ?? $server->cloud_provider_token_id,
                $teamId ?? $server->team_id
            );
        }

        if ($deleteFromDigitalOcean && ($digitalOceanDropletId || ($server && $server->digitalocean_droplet_id))) {
            $this->deleteFromDigitalOceanById(
                $digitalOceanDropletId ?? $server->digitalocean_droplet_id,
                $cloudProviderTokenId ?? $server->cloud_provider_token_id,
                $teamId ?? $server->team_id
            );
        }

        if ($deleteFromHostinger && ($hostingerVirtualMachineId || ($server && $server->hostinger_virtual_machine_id))) {
            $this->deleteFromHostingerById(
                $hostingerVirtualMachineId ?? $server->hostinger_virtual_machine_id,
                $cloudProviderTokenId ?? $server->cloud_provider_token_id,
                $teamId ?? $server->team_id
            );
        }

        logger()->debug($server ? 'Deleting server from Coolify' : 'Server already deleted from Coolify, skipping Coolify deletion');

        // If server is already deleted from Coolify, skip this part
        if (! $server) {
            return; // Server already force deleted from Coolify
        }

        // The server stays soft-deleted, so no resource is left without a server. cleanup:stucked-resources retries it.
        if (! $resourcesDeleted) {
            return;
        }

        try {
            $server->forceDelete();
        } catch (\Throwable $e) {
            logger()->error('Failed to force delete server from Coolify', [
                'error' => $e->getMessage(),
                'server_id' => $server->id,
            ]);
        }
    }

    /**
     * Deletes the resources of the server one after the other, before the server and its destinations are deleted.
     * A resource whose server does not respond is still deleted from Coolify.
     */
    private function deleteResources(Server $server, bool $removeFromServer): bool
    {
        $deleted = true;
        foreach ($server->primaryResources() as $resource) {
            // The server is soft-deleted, so the relations of the resource do not load it.
            $resource->destination?->setRelation('server', $server);
            if ($resource instanceof Service) {
                $resource->setRelation('server', $server);
            }
            try {
                (new DeleteResourceJob($resource, dockerCleanup: false, deleteFromCoolifyOnly: ! $removeFromServer))->handle();
            } catch (\Throwable $e) {
                $deleted = false;
                logger()->error('Failed to delete a resource of a deleted server', [
                    'error' => $e->getMessage(),
                    'server_id' => $server->id,
                    'resource_type' => $resource->getMorphClass(),
                    'resource_id' => $resource->id,
                ]);
            }
        }

        return $deleted;
    }

    private function stopSentinel(Server $server): void
    {
        if (! $server->settings?->is_sentinel_enabled || ! $server->isFunctional()) {
            return;
        }

        try {
            instant_remote_process(['docker rm -f coolify-sentinel'], $server, false, timeout: 30, maxAttempts: 1);
        } catch (\Throwable $e) {
            logger()->warning('Failed to stop Sentinel before deleting the server', [
                'error' => $e->getMessage(),
                'server_id' => $server->id,
            ]);
        }
    }

    private function deleteFromHetznerById(int $hetznerServerId, ?int $cloudProviderTokenId, int $teamId): void
    {
        try {
            // Use the provided token, or fallback to first available team token
            $token = null;

            if ($cloudProviderTokenId) {
                $token = CloudProviderToken::where('id', $cloudProviderTokenId)
                    ->where('team_id', $teamId)
                    ->where('provider', 'hetzner')
                    ->first();
            }

            if (! $token) {
                $token = CloudProviderToken::where('team_id', $teamId)
                    ->where('provider', 'hetzner')
                    ->first();
            }

            if (! $token) {

                return;
            }

            $hetznerService = new HetznerService($token->token);
            $hetznerService->deleteServer($hetznerServerId);

        } catch (\Throwable $e) {

            // Log the error but don't prevent the server from being deleted from Coolify
            logger()->error('Failed to delete server from Hetzner', [
                'error' => $e->getMessage(),
                'hetzner_server_id' => $hetznerServerId,
                'team_id' => $teamId,
            ]);

            // Notify the team about the failure
            $team = Team::find($teamId);
            $team?->notify(new HetznerDeletionFailed($hetznerServerId, $teamId, remove_iip($e->getMessage())));
        }
    }

    private function deleteFromVultrById(string $vultrInstanceId, ?int $cloudProviderTokenId, int $teamId): void
    {
        try {
            $token = null;

            if ($cloudProviderTokenId) {
                $token = CloudProviderToken::where('id', $cloudProviderTokenId)
                    ->where('team_id', $teamId)
                    ->where('provider', 'vultr')
                    ->first();
            }

            if (! $token) {
                $token = CloudProviderToken::where('team_id', $teamId)
                    ->where('provider', 'vultr')
                    ->first();
            }

            if (! $token) {
                throw new \RuntimeException('No Vultr token found for the server team.');
            }

            $vultrService = new VultrService($token->token);
            $vultrService->deleteInstance($vultrInstanceId);

            logger()->debug('Deleted server from Vultr', [
                'vultr_instance_id' => $vultrInstanceId,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            logger()->error('Failed to delete server from Vultr', [
                'error' => $e->getMessage(),
                'vultr_instance_id' => $vultrInstanceId,
                'team_id' => $teamId,
            ]);

            throw $e;
        }
    }

    private function deleteFromDigitalOceanById(int $digitalOceanDropletId, ?int $cloudProviderTokenId, int $teamId): void
    {
        try {
            $token = null;

            if ($cloudProviderTokenId) {
                $token = CloudProviderToken::where('id', $cloudProviderTokenId)
                    ->where('team_id', $teamId)
                    ->where('provider', 'digitalocean')
                    ->first();
            }

            if (! $token) {
                $token = CloudProviderToken::where('team_id', $teamId)
                    ->where('provider', 'digitalocean')
                    ->first();
            }

            if (! $token) {
                throw new \RuntimeException('No DigitalOcean token found for the server team.');
            }

            $digitalOceanService = new DigitalOceanService($token->token);
            $digitalOceanService->deleteDroplet($digitalOceanDropletId);

            logger()->debug('Deleted droplet from DigitalOcean', [
                'digitalocean_droplet_id' => $digitalOceanDropletId,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            logger()->error('Failed to delete droplet from DigitalOcean', [
                'error' => $e->getMessage(),
                'digitalocean_droplet_id' => $digitalOceanDropletId,
                'team_id' => $teamId,
            ]);

            throw $e;
        }
    }

    private function deleteFromHostingerById(int $hostingerVirtualMachineId, ?int $cloudProviderTokenId, int $teamId): void
    {
        try {
            $token = null;

            if ($cloudProviderTokenId) {
                $token = CloudProviderToken::where('id', $cloudProviderTokenId)
                    ->where('team_id', $teamId)
                    ->where('provider', 'hostinger')
                    ->first();
            }

            if (! $token) {
                $token = CloudProviderToken::where('team_id', $teamId)
                    ->where('provider', 'hostinger')
                    ->first();
            }

            if (! $token) {
                throw new \RuntimeException('No Hostinger token found for the server team.');
            }

            $hostingerService = new HostingerService($token->token);
            $subscriptionId = $hostingerService->getVirtualMachine($hostingerVirtualMachineId)['subscription_id'] ?? null;

            if (! $subscriptionId) {
                throw new \RuntimeException('The Hostinger VPS has no subscription.');
            }

            $hostingerService->disableAutoRenewal($subscriptionId);

            logger()->debug('Disabled Hostinger auto-renewal, so Hostinger deletes the VPS at the end of the paid period', [
                'hostinger_virtual_machine_id' => $hostingerVirtualMachineId,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            logger()->error('Failed to delete VPS from Hostinger', [
                'error' => $e->getMessage(),
                'hostinger_virtual_machine_id' => $hostingerVirtualMachineId,
                'team_id' => $teamId,
            ]);

            throw $e;
        }
    }
}
