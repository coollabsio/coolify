<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageIntegrationTokens extends RestActionTool
{
    protected string $name = 'manage_integration_tokens';

    protected string $description = 'List, get, add, update, or delete integration tokens (Cloudflare DNS/Tunnel, Doppler, Infisical, Vault) of the authenticated team, list the DNS zones of its Cloudflare tokens, or read the zones of a Cloudflare token again. The token secret is never returned. Requires admin or owner, read ability for list/get/zones, write ability for changes. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'path' => 'security/integration-tokens', 'ability' => 'read', 'description' => 'integration tokens of the team'],
            'get' => ['method' => 'GET', 'path' => 'security/integration-tokens/{uuid}', 'ability' => 'read', 'description' => 'one integration token'],
            'zones' => ['method' => 'GET', 'path' => 'dns/zones', 'ability' => 'read', 'description' => 'DNS zones that the Cloudflare tokens of the team can access'],
            'create' => ['method' => 'POST', 'path' => 'security/integration-tokens', 'ability' => 'write', 'description' => 'add a token after the provider accepts it (data: provider cloudflare|doppler|infisical|vault, name, token; Cloudflare: capabilities [dns, tunnel], automatic_dns, proxied_dns; secret managers: metadata)'],
            'update' => ['method' => 'PATCH', 'path' => 'security/integration-tokens/{uuid}', 'ability' => 'write', 'description' => 'rename, rotate the secret, or change settings; omitted fields keep their value (data: name, token, capabilities, automatic_dns, proxied_dns, metadata)'],
            'sync_zones' => ['method' => 'POST', 'path' => 'security/integration-tokens/{uuid}/sync-zones', 'ability' => 'write', 'description' => 'read the zones of a Cloudflare token again'],
            'delete' => ['method' => 'DELETE', 'path' => 'security/integration-tokens/{uuid}', 'ability' => 'delete', 'confirm' => true, 'description' => 'delete a token that no resource uses and that manages no DNS records'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Integration token UUID (get, update, sync_zones, delete).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body for create and update, same fields as the REST API: provider, name, token, capabilities, automatic_dns, proxied_dns, metadata.';
    }
}
