<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetApplication;
use App\Mcp\Tools\GetCurrentTeam;
use App\Mcp\Tools\GetDatabase;
use App\Mcp\Tools\GetDeployment;
use App\Mcp\Tools\GetDestination;
use App\Mcp\Tools\GetEnvironment;
use App\Mcp\Tools\GetInfrastructureOverview;
use App\Mcp\Tools\GetLogs;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetServerDomains;
use App\Mcp\Tools\GetServerResources;
use App\Mcp\Tools\GetService;
use App\Mcp\Tools\GetServiceApplication;
use App\Mcp\Tools\GetServiceDatabase;
use App\Mcp\Tools\ListApplications;
use App\Mcp\Tools\ListBackupExecutions;
use App\Mcp\Tools\ListDatabaseBackups;
use App\Mcp\Tools\ListDatabases;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListDestinations;
use App\Mcp\Tools\ListEnvKeys;
use App\Mcp\Tools\ListGithubApps;
use App\Mcp\Tools\ListGithubBranches;
use App\Mcp\Tools\ListGithubRepositories;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListResourceTags;
use App\Mcp\Tools\ListResources;
use App\Mcp\Tools\ListScheduledTaskExecutions;
use App\Mcp\Tools\ListScheduledTasks;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListServiceApplications;
use App\Mcp\Tools\ListServiceDatabases;
use App\Mcp\Tools\ListServices;
use App\Mcp\Tools\ListStorages;
use App\Mcp\Tools\ListTags;
use App\Mcp\Tools\ListTeamMembers;
use Laravel\Mcp\Server;

class CoolifyServer extends Server
{
    protected string $name = 'Coolify';

    protected string $version = '0.2.0';

    /**
     * Return all registered tools in a single tools/list page (default package limit is 15).
     */
    public int $maxPaginationLength = 100;

    public int $defaultPaginationLength = 100;

    protected string $instructions = <<<'MD'
Read-only MCP server for Coolify, scoped to the authenticated team token. Every tool enforces team ownership.

Recommended workflow:
1. get_infrastructure_overview or get_current_team — start here.
2. list_projects / get_project / get_environment — navigate hierarchy.
3. list_servers / list_applications / list_databases / list_services / list_resources — browse inventories (paginated).
4. get_* by UUID for details; get_logs / list_deployments for debugging; list_env_keys for config names (never values).
5. Server context: get_server_domains, get_server_resources, list_destinations.

Every response is `{ data, _actions?, _pagination? }`. Secrets, passwords, env values, private keys, and full deploy logs are never returned.
MD;

    protected array $tools = [
        GetInfrastructureOverview::class,
        GetCurrentTeam::class,
        ListTeamMembers::class,
        ListServers::class,
        GetServer::class,
        GetServerDomains::class,
        GetServerResources::class,
        ListDestinations::class,
        GetDestination::class,
        ListProjects::class,
        GetProject::class,
        GetEnvironment::class,
        ListResources::class,
        ListApplications::class,
        GetApplication::class,
        ListDatabases::class,
        GetDatabase::class,
        ListDatabaseBackups::class,
        ListBackupExecutions::class,
        ListServices::class,
        GetService::class,
        ListServiceApplications::class,
        GetServiceApplication::class,
        ListServiceDatabases::class,
        GetServiceDatabase::class,
        ListDeployments::class,
        GetDeployment::class,
        GetLogs::class,
        ListEnvKeys::class,
        ListStorages::class,
        ListResourceTags::class,
        ListScheduledTasks::class,
        ListScheduledTaskExecutions::class,
        ListTags::class,
        ListGithubApps::class,
        ListGithubRepositories::class,
        ListGithubBranches::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
