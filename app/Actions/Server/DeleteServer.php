<?php

namespace App\Actions\Server;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\HetznerDeletionFailed;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\VultrService;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteServer
{
    use AsAction;

    public function handle(int $serverId, bool $deleteFromHetzner = false, ?int $hetznerServerId = null, ?int $cloudProviderTokenId = null, ?int $teamId = null, bool $deleteFromVultr = false, ?string $vultrInstanceId = null, bool $deleteFromDigitalOcean = false, ?int $digitalOceanDropletId = null)
    {
        $server = Server::withTrashed()->find($serverId);

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

        logger()->debug($server ? 'Deleting server from Coolify' : 'Server already deleted from Coolify, skipping Coolify deletion');

        // If server is already deleted from Coolify, skip this part
        if (! $server) {
            return; // Server already force deleted from Coolify
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
            $team?->notify(new HetznerDeletionFailed($hetznerServerId, $teamId, $e->getMessage()));
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
}
