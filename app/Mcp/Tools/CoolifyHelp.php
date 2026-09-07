<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CoolifyHelp extends Tool
{
    protected string $name = 'coolify_help';

    protected string $description = 'Tool catalog by intent. Call first when unsure which Coolify MCP tool to use. Optional intent: overview | search | inventory | debug | deploy | github | team | essentials.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $intent = $request->get('intent');
        if ($intent !== null && (! is_string($intent) || trim($intent) === '')) {
            return $this->mcpError($request, 'intent must be a non-empty string when provided.');
        }
        $intent = is_string($intent) ? strtolower(trim($intent)) : null;

        $catalog = [
            'overview' => [
                'description' => 'Bird\'s-eye view and "what is broken?"',
                'tools' => [
                    'get_infrastructure_overview',
                    'list_unhealthy_resources',
                    'search_resources',
                    'get_current_team',
                ],
            ],
            'search' => [
                'description' => 'Find a resource by name/UUID/domain without knowing the type',
                'tools' => [
                    'search_resources',
                    'list_resources',
                    'list_tags',
                ],
            ],
            'inventory' => [
                'description' => 'Browse servers, projects, apps, DBs, services',
                'tools' => [
                    'list_servers',
                    'get_server',
                    'list_projects',
                    'get_project',
                    'get_environment',
                    'list_applications',
                    'get_application',
                    'list_databases',
                    'get_database',
                    'list_services',
                    'get_service',
                    'list_destinations',
                    'get_destination',
                ],
            ],
            'debug' => [
                'description' => 'Diagnose failures (prefer DB-backed tools before live logs)',
                'tools' => [
                    'get_application',
                    'list_deployments',
                    'get_deployment',
                    'list_env_keys',
                    'list_shared_env_keys',
                    'list_application_previews',
                    'list_storages',
                    'list_scheduled_tasks',
                    'get_logs',
                ],
                'notes' => [
                    'get_logs needs a running container on a reachable server; on failure it returns reason + next_tools.',
                    'Use get_deployment with include_log_summary=true for build failures.',
                    'Prompts: troubleshoot_application, explain_failed_deploy.',
                ],
            ],
            'deploy' => [
                'description' => 'Lifecycle actions (requires token ability: deploy)',
                'tools' => [
                    'control',
                    'deploy',
                    'cancel_deployment',
                    'list_deployments',
                    'get_deployment',
                ],
                'notes' => [
                    'stop requires confirm=true.',
                    'Read-only tokens receive a clear missing_ability error.',
                ],
            ],
            'github' => [
                'description' => 'GitHub apps / repos / branches (list_github_apps is DB-only; repos/branches call GitHub)',
                'tools' => [
                    'list_github_apps',
                    'list_github_repositories',
                    'list_github_branches',
                ],
            ],
            'team' => [
                'description' => 'Team identity',
                'tools' => [
                    'get_current_team',
                    'list_team_members',
                ],
            ],
            'essentials' => [
                'description' => 'Minimal set for most questions',
                'tools' => [
                    'coolify_help',
                    'get_infrastructure_overview',
                    'search_resources',
                    'list_unhealthy_resources',
                    'list_applications',
                    'get_application',
                    'list_deployments',
                    'get_deployment',
                    'list_env_keys',
                    'get_logs',
                    'control',
                    'deploy',
                ],
            ],
        ];

        if ($intent !== null) {
            if (! isset($catalog[$intent])) {
                return $this->mcpError($request, 'Unknown intent. Use: '.implode(', ', array_keys($catalog)));
            }

            return $this->mcpSuccess($request, $this->respond([
                'intent' => $intent,
                'catalog' => [$intent => $catalog[$intent]],
                'workflow' => $this->workflowHints(),
            ]));
        }

        return $this->mcpSuccess($request, $this->respond([
            'intents' => array_keys($catalog),
            'catalog' => $catalog,
            'workflow' => $this->workflowHints(),
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function workflowHints(): array
    {
        return [
            'Start with coolify_help, get_infrastructure_overview, search_resources, or list_unhealthy_resources (sample_only=true).',
            'Resolve a UUID, then get_* for details.',
            'Debug with list_deployments / get_deployment(include_log_summary) before get_logs.',
            'get_logs is optional and fails structured when not running or server unreachable.',
            'Never request env values or secrets over MCP.',
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intent' => $schema->string()->description('Optional: overview | search | inventory | debug | deploy | github | team | essentials.'),
        ];
    }
}
