<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
class ManageImageScanning extends RestActionTool
{
    protected string $name = 'manage_image_scanning';

    protected string $description = 'Read or change image vulnerability scanning of a server of the authenticated team, or the team default for new servers. Requires read ability for get_server, write ability for update_server and update_team. Needs a team admin or owner. Turn scanning of one application on or off with manage_application update (is_image_scan_enabled). Read the results with get_vulnerabilities. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'get_server' => ['method' => 'GET', 'path' => 'servers/{uuid}/image-scanning', 'ability' => 'read', 'description' => 'whether the server scans, and the scanner state on the server'],
            'update_server' => ['method' => 'PATCH', 'path' => 'servers/{uuid}/image-scanning', 'ability' => 'write', 'description' => 'turn scanning of the server on or off (data: is_image_scan_enabled); turning it on gets the scanner on the server'],
            'update_team' => ['method' => 'PATCH', 'path' => 'team', 'ability' => 'write', 'description' => 'turn scanning on or off for servers added to the team from now on (data: scan_images_on_new_servers)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Server UUID (get_server and update_server).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body: is_image_scan_enabled (update_server), scan_images_on_new_servers (update_team).';
    }
}
