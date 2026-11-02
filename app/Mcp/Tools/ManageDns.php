<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageDns extends RestActionTool
{
    protected string $name = 'manage_dns';

    protected string $description = 'Show, create, replace, or release Cloudflare DNS records for the domains of an application or a service. Records point only to the public addresses of the resource server, and only zones of the team are used. Requires read ability for status, write ability for create/replace, delete ability for release. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'status' => ['method' => 'GET', 'paths' => [
                'application' => 'applications/{uuid}/dns',
                'service' => 'services/{uuid}/dns',
            ], 'ability' => 'read', 'description' => 'DNS providers, managed records, and the default record address for each domain of the resource'],
            'create' => ['method' => 'POST', 'paths' => [
                'application' => 'applications/{uuid}/dns',
                'service' => 'services/{uuid}/dns',
            ], 'ability' => 'write', 'description' => 'create the DNS record for a domain (data: hostname, integration_token_uuid when several providers match, address). A conflict returns the record_id and current value: ask the user before replace'],
            'replace' => ['method' => 'POST', 'paths' => [
                'application' => 'applications/{uuid}/dns/replace',
                'service' => 'services/{uuid}/dns/replace',
            ], 'ability' => 'write', 'confirm' => true, 'description' => 'overwrite the conflicting record (data: hostname, record_id, current, integration_token_uuid, address)'],
            'release' => ['method' => 'DELETE', 'paths' => [
                'application' => 'applications/{uuid}/dns/{hostname}',
                'service' => 'services/{uuid}/dns/{hostname}',
            ], 'ability' => 'delete', 'confirm' => true, 'description' => 'release the record of a hostname the resource no longer uses; Coolify deletes it at Cloudflare only when it created it and nothing else uses it (data: delete_record=false keeps it)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application or service UUID.',
            'hostname' => 'Hostname of the record (release).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: hostname, integration_token_uuid, address (create); plus record_id, current (replace); delete_record (release).';
    }
}
