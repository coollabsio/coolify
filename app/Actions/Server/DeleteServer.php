<?php

namespace App\Actions\Server;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Services\HetznerService;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteServer
{
    use AsAction;

    public function handle(Server $server, bool $deleteFromHetzner = false)
    {
        // Delete from Hetzner Cloud if requested and server has hetzner_server_id
        if ($deleteFromHetzner && $server->hetzner_server_id) {
            $this->deleteFromHetzner($server);
        }

        StopSentinel::run($server);
        $server->forceDelete();
    }

    private function deleteFromHetzner(Server $server): void
    {
        try {
            // Use the server's associated token, or fallback to first available team token
            $token = $server->cloudProviderToken;

            if (! $token) {
                $token = CloudProviderToken::where('team_id', $server->team_id)
                    ->where('provider', 'hetzner')
                    ->first();
            }

            if (! $token) {
                ray('No Hetzner token found for team, skipping Hetzner deletion', [
                    'team_id' => $server->team_id,
                    'server_id' => $server->id,
                ]);

                return;
            }

            $hetznerService = new HetznerService($token->token);
            $hetznerService->deleteServer($server->hetzner_server_id);

            ray('Deleted server from Hetzner', [
                'hetzner_server_id' => $server->hetzner_server_id,
                'server_id' => $server->id,
            ]);
        } catch (\Throwable $e) {
            ray('Failed to delete server from Hetzner', [
                'error' => $e->getMessage(),
                'hetzner_server_id' => $server->hetzner_server_id,
                'server_id' => $server->id,
            ]);

            // Log the error but don't prevent the server from being deleted from Coolify
            logger()->error('Failed to delete server from Hetzner', [
                'error' => $e->getMessage(),
                'hetzner_server_id' => $server->hetzner_server_id,
                'server_id' => $server->id,
            ]);
        }
    }
}
