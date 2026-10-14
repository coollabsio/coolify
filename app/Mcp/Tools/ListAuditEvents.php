<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListAuditEvents extends RestActionTool
{
    protected string $name = 'list_audit_events';

    protected string $description = 'List audit events of the authenticated team, newest first. Only team admins and owners. Actor emails, IPs, and metadata need read:sensitive. Requires read ability. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'path' => 'audit-events', 'ability' => 'read', 'description' => 'audit events (data: per_page max 100, page, search, action, source all|ui|api|mcp|webhook|system|scheduler)'],
        ];
    }

    protected function pathParams(): array
    {
        return [];
    }

    protected function dataDescription(): string
    {
        return 'Query filters, same as the REST API: per_page, page, search, action, source.';
    }
}
