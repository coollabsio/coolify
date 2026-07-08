<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetApplication;
use App\Mcp\Tools\GetApplicationHealth;
use App\Mcp\Tools\GetApplicationLogs;
use App\Mcp\Tools\GetDatabase;
use App\Mcp\Tools\GetDeploymentLogs;
use App\Mcp\Tools\GetInfrastructureOverview;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetService;
use App\Mcp\Tools\GetServiceContainerLogs;
use App\Mcp\Tools\ListApplications;
use App\Mcp\Tools\ListDatabases;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListServiceContainers;
use App\Mcp\Tools\ListServices;
use Laravel\Mcp\Server;

class CoolifyServer extends Server
{
    protected string $name = 'Coolify';

    protected string $version = '0.1.0';

    protected string $instructions = <<<'MD'
Read-only MCP server for Coolify, scoped to the authenticated team token.

Recommended workflow:
1. get_infrastructure_overview — start here; single call returns all servers, projects with resource counts, and aggregates.
2. list_servers / list_projects / list_applications / list_databases / list_services — paginated summary listings (default 50 per page, cap 100).
3. get_server / get_application / get_database / get_service — full details for a single UUID.

Debugging a deploy:
4. get_application_health — links an application's latest deployment outcome to its current runtime status and restart history in one call; suggests get_deployment_logs or get_application_logs as next steps.
5. list_deployments / get_deployment_logs — deployment history and build-log lines for an application.
6. get_application_logs — tail an application container's live docker logs; automatically falls back to a captured crash-log snapshot if the container was auto-stopped and removed after exceeding its restart limit.
7. list_service_containers / get_service_container_logs — enumerate a service stack's sub-resources (with container names) and tail one of their docker logs.

Every response is `{ data, _actions?, _pagination? }`. `_actions` suggests the next tool + args; `_pagination.next` is the args to call again for the next page.
MD;

    protected array $tools = [
        GetInfrastructureOverview::class,
        ListServers::class,
        GetServer::class,
        ListProjects::class,
        ListApplications::class,
        GetApplication::class,
        ListDatabases::class,
        GetDatabase::class,
        ListServices::class,
        GetService::class,
        GetApplicationHealth::class,
        ListDeployments::class,
        GetDeploymentLogs::class,
        GetApplicationLogs::class,
        ListServiceContainers::class,
        GetServiceContainerLogs::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
