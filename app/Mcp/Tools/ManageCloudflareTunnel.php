<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageCloudflareTunnel extends RestActionTool
{
    protected string $name = 'manage_cloudflare_tunnel';

    protected string $description = 'Read the Cloudflare Tunnel settings of a server of the authenticated team, or mark the tunnel as enabled or disabled. These actions do not install or remove cloudflared on the server. Requires read ability for get, write ability for changes. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'get' => ['method' => 'GET', 'path' => 'servers/{uuid}/cloudflare-tunnel', 'ability' => 'read', 'description' => 'tunnel settings and the server address'],
            'update' => ['method' => 'PATCH', 'path' => 'servers/{uuid}/cloudflare-tunnel', 'ability' => 'write', 'description' => 'change the stored setting (data: is_cloudflare_tunnel)'],
            'enable' => ['method' => 'POST', 'path' => 'servers/{uuid}/cloudflare-tunnel/enable', 'ability' => 'write', 'confirm' => true, 'description' => 'mark the tunnel as enabled after cloudflared was set up by hand'],
            'disable' => ['method' => 'POST', 'path' => 'servers/{uuid}/cloudflare-tunnel/disable', 'ability' => 'write', 'confirm' => true, 'description' => 'mark the tunnel as disabled and restore the previous server address when known'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Server UUID.',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body for update: is_cloudflare_tunnel.';
    }
}
