<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageProxy extends RestActionTool
{
    protected string $name = 'manage_proxy';

    protected string $description = 'Read or change the proxy of a server of the authenticated team: settings, configuration file, restart. Requires read ability for get, write ability for update (changing proxy_type also needs deploy), deploy ability for set_configuration and restart. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'get' => ['method' => 'GET', 'path' => 'servers/{uuid}/proxy', 'ability' => 'read', 'description' => 'proxy type, status, and settings (the configuration file only with read:sensitive)'],
            'update' => ['method' => 'PATCH', 'path' => 'servers/{uuid}/proxy', 'ability' => 'write', 'confirm' => true, 'description' => 'change proxy settings (data: proxy_type, redirect_enabled, redirect_url, generate_exact_labels, traefik_certificate_resolver; changing proxy_type needs deploy ability)'],
            'set_configuration' => ['method' => 'PUT', 'path' => 'servers/{uuid}/proxy/configuration', 'ability' => 'deploy', 'confirm' => true, 'description' => 'replace the proxy configuration file (data: configuration, plain or base64)'],
            'restart' => ['method' => 'POST', 'path' => 'servers/{uuid}/proxy/restart', 'ability' => 'deploy', 'confirm' => true, 'description' => 'restart the proxy; routed apps are briefly unreachable'],
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
        return 'Request body, same fields as the REST API: proxy_type, redirect_enabled, redirect_url, generate_exact_labels, traefik_certificate_resolver (update); configuration (set_configuration).';
    }
}
