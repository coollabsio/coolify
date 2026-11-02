<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\ExplainFailedDeploy;
use App\Mcp\Prompts\TroubleshootApplication;
use App\Mcp\Resources\ApplicationResource;
use App\Mcp\Resources\InfrastructureOverviewResource;
use App\Mcp\Tools\CancelDeployment;
use App\Mcp\Tools\CloudProvider;
use App\Mcp\Tools\Control;
use App\Mcp\Tools\CoolifyHelp;
use App\Mcp\Tools\CreateApplication;
use App\Mcp\Tools\CreateDatabase;
use App\Mcp\Tools\CreateService;
use App\Mcp\Tools\Deploy;
use App\Mcp\Tools\DockerCleanup;
use App\Mcp\Tools\GetApplication;
use App\Mcp\Tools\GetApplicationPreview;
use App\Mcp\Tools\GetCurrentTeam;
use App\Mcp\Tools\GetDatabase;
use App\Mcp\Tools\GetDatabaseImport;
use App\Mcp\Tools\GetDeployment;
use App\Mcp\Tools\GetDestination;
use App\Mcp\Tools\GetEnvironment;
use App\Mcp\Tools\GetEnvValues;
use App\Mcp\Tools\GetInfrastructureOverview;
use App\Mcp\Tools\GetLogs;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetServerDomains;
use App\Mcp\Tools\GetServerMetrics;
use App\Mcp\Tools\GetServerResources;
use App\Mcp\Tools\GetService;
use App\Mcp\Tools\GetServiceApplication;
use App\Mcp\Tools\GetServiceDatabase;
use App\Mcp\Tools\ListApplicationPreviews;
use App\Mcp\Tools\ListApplications;
use App\Mcp\Tools\ListAuditEvents;
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
use App\Mcp\Tools\ListResources;
use App\Mcp\Tools\ListResourceTags;
use App\Mcp\Tools\ListRollbackImages;
use App\Mcp\Tools\ListScheduledTaskExecutions;
use App\Mcp\Tools\ListScheduledTasks;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListServiceApplications;
use App\Mcp\Tools\ListServiceDatabases;
use App\Mcp\Tools\ListServices;
use App\Mcp\Tools\ListServiceTemplates;
use App\Mcp\Tools\ListSharedEnvKeys;
use App\Mcp\Tools\ListStorages;
use App\Mcp\Tools\ListTags;
use App\Mcp\Tools\ListTeamMembers;
use App\Mcp\Tools\ListUnhealthyResources;
use App\Mcp\Tools\ManageApplication;
use App\Mcp\Tools\ManageBackup;
use App\Mcp\Tools\ManageDatabase;
use App\Mcp\Tools\ManageDestination;
use App\Mcp\Tools\ManageEnv;
use App\Mcp\Tools\ManageGitSource;
use App\Mcp\Tools\ManageNotifications;
use App\Mcp\Tools\ManagePreview;
use App\Mcp\Tools\ManagePrivateKey;
use App\Mcp\Tools\ManageProject;
use App\Mcp\Tools\ManageProxy;
use App\Mcp\Tools\ManageRegistry;
use App\Mcp\Tools\ManageS3Storage;
use App\Mcp\Tools\ManageScheduledTask;
use App\Mcp\Tools\ManageServer;
use App\Mcp\Tools\ManageService;
use App\Mcp\Tools\ManageSharedEnv;
use App\Mcp\Tools\ManageStorage;
use App\Mcp\Tools\ManageTags;
use App\Mcp\Tools\RollbackApplication;
use App\Mcp\Tools\SearchResources;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class CoolifyServer extends Server
{
    protected string $name = 'Coolify';

    protected string $version = '0.3.0';

    /**
     * Return all registered tools in a single tools/list page (default package limit is 15).
     */
    public int $maxPaginationLength = 100;

    public int $defaultPaginationLength = 100;

    protected string $instructions = <<<'MD'
Coolify MCP for the authenticated team token. Every tool enforces team ownership.

Start here (prefer these before deep get_*):
1. coolify_help — tools this token can use, plus step-by-step recipes (topic=deploy_app_from_git, add_database_to_app, ...).
2. get_infrastructure_overview — counts + health_hints.
3. search_resources — fuzzy name/UUID/domain when type is unknown.
4. list_unhealthy_resources sample_only=true — cheap "what's broken?" sample + counts.

Then: list_*/get_* for details. Debug is DB-first:
- list_deployments → get_deployment(include_log_summary=true)
- list_env_keys / list_shared_env_keys (names only, never values)
- get_logs only if status is running; on failure use reason + next_tools (do not loop)

Lifecycle (requires token ability **deploy**):
- control (start|stop|restart; stop needs confirm=true)
- deploy, cancel_deployment, rollback_application

Changes (requires token ability **write**): manage_* and create_* tools. They run the same REST API routes, with the same validation and permissions. Destructive actions need confirm=true: ask the user first.

Prompts: troubleshoot_application, explain_failed_deploy.
Resources: coolify://overview, coolify://application/{uuid}.

Responses: `{ data, _actions?, _pagination? }`. Private keys, S3 secrets, tokens, passwords, and webhook URLs are never returned. Env values only with get_env_values (read:sensitive). Full deploy logs are never returned; optional log summaries are best-effort redacted only.
MD;

    /**
     * Every tool class. Tools hide themselves when the token cannot use them (shouldRegister).
     *
     * @var array<int, class-string<Tool>>
     */
    public const TOOLS = [
        CoolifyHelp::class,
        GetInfrastructureOverview::class,
        SearchResources::class,
        ListUnhealthyResources::class,
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
        ListApplicationPreviews::class,
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
        ListSharedEnvKeys::class,
        ListStorages::class,
        ListResourceTags::class,
        ListScheduledTasks::class,
        ListScheduledTaskExecutions::class,
        ListTags::class,
        ListGithubApps::class,
        ListGithubRepositories::class,
        ListGithubBranches::class,
        Control::class,
        Deploy::class,
        CancelDeployment::class,
        ManageProject::class,
        CreateDatabase::class,
        ManageDatabase::class,
        GetDatabaseImport::class,
        ListServiceTemplates::class,
        CreateService::class,
        ManageService::class,
        ManageEnv::class,
        ManageSharedEnv::class,
        GetEnvValues::class,
        CreateApplication::class,
        ManageApplication::class,
        ManagePreview::class,
        GetApplicationPreview::class,
        RollbackApplication::class,
        ListRollbackImages::class,
        ManageScheduledTask::class,
        ManageBackup::class,
        ManageStorage::class,
        ManageTags::class,
        ManageServer::class,
        ManageProxy::class,
        DockerCleanup::class,
        ManageDestination::class,
        ManageRegistry::class,
        ManagePrivateKey::class,
        ManageS3Storage::class,
        ManageGitSource::class,
        CloudProvider::class,
        ManageNotifications::class,
        ListAuditEvents::class,
        GetServerMetrics::class,
    ];

    protected array $tools = self::TOOLS;

    protected array $resources = [
        InfrastructureOverviewResource::class,
        ApplicationResource::class,
    ];

    protected array $prompts = [
        TroubleshootApplication::class,
        ExplainFailedDeploy::class,
    ];
}
