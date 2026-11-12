<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetVulnerabilities extends RestActionTool
{
    protected string $name = 'get_vulnerabilities';

    protected string $description = 'Read image vulnerability scan results of the authenticated team: the security status of every resource, or the status, scanned images, and vulnerabilities of one application, service, or database. Requires read ability. Same data and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'path' => 'vulnerabilities', 'ability' => 'read', 'description' => 'every resource with its security status, worst first (data: status, comma-separated: update_now, update_available, no_fix, check_needed, ok, not_checked)'],
            'resource' => ['method' => 'GET', 'paths' => [
                'application' => 'applications/{uuid}/vulnerabilities',
                'service' => 'services/{uuid}/vulnerabilities',
                'database' => 'databases/{uuid}/vulnerabilities',
            ], 'ability' => 'read', 'description' => 'status, scanned images, and vulnerabilities of one resource, worst first (data: state fixed|no_fix|ignored, severity, image, search, page, per_page max 200; state and severity are comma-separated)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Resource UUID (resource action).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Filters, sent as query parameters: status (list); state, severity, image, search, page, per_page (resource).';
    }
}
