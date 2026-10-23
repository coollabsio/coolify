<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Mcp\Servers\CoolifyServer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class CoolifyHelp extends Tool
{
    protected string $name = 'coolify_help';

    protected string $description = 'Start here. Lists the Coolify MCP tools this token can use and step-by-step recipes for multi-step tasks. Optional: topic (recipe name) or search (filter tools).';

    use BuildsResponse;
    use ResolvesTeam;

    /**
     * Multi-step workflows. Each step names a registered tool (and action for grouped tools).
     *
     * @var array<string, array{description: string, steps: array<int, array{tool: string, action?: string, note: string}>}>
     */
    public const RECIPES = [
        'deploy_app_from_git' => [
            'description' => 'Create and deploy an application from a Git repository',
            'steps' => [
                ['tool' => 'list_projects', 'note' => 'Find the project, or create one with manage_project action=create.'],
                ['tool' => 'get_project', 'note' => 'Pick the environment (name or UUID).'],
                ['tool' => 'list_servers', 'note' => 'Pick a reachable server_uuid.'],
                ['tool' => 'list_destinations', 'note' => 'Needed only when the server has more than one destination.'],
                ['tool' => 'list_github_apps', 'note' => 'Private repo: use a GitHub app, or a deploy key (manage_private_key).'],
                ['tool' => 'create_application', 'action' => 'public', 'note' => 'Or action=github_app / deploy_key. Set git_repository, git_branch, build_pack, ports_exposes.'],
                ['tool' => 'manage_env', 'action' => 'bulk_update', 'note' => 'Set environment variables (resource=application).'],
                ['tool' => 'deploy', 'note' => 'Queue the first deployment.'],
                ['tool' => 'get_deployment', 'note' => 'Follow the status; include_log_summary=true on failure.'],
            ],
        ],
        'add_database_to_app' => [
            'description' => 'Create a database and connect an application to it',
            'steps' => [
                ['tool' => 'get_application', 'note' => 'Note the project, environment, and server of the app.'],
                ['tool' => 'create_database', 'action' => 'postgresql', 'note' => 'Use the same environment and server_uuid as the app.'],
                ['tool' => 'control', 'note' => 'Start the database (resource=database, action=start).'],
                ['tool' => 'get_env_values', 'note' => 'Read the internal connection URL (needs read:sensitive).'],
                ['tool' => 'manage_env', 'action' => 'create', 'note' => 'Add DATABASE_URL to the application.'],
                ['tool' => 'deploy', 'note' => 'Redeploy the app so it gets the new variable.'],
            ],
        ],
        'deploy_service_from_template' => [
            'description' => 'Deploy a one-click service',
            'steps' => [
                ['tool' => 'list_service_templates', 'note' => 'Find the template type (search=...).'],
                ['tool' => 'create_service', 'action' => 'template', 'note' => 'Set type, project, environment, server_uuid.'],
                ['tool' => 'manage_service', 'action' => 'update_application', 'note' => 'Set domains on the service application.'],
                ['tool' => 'control', 'note' => 'Start it (resource=service, action=start).'],
            ],
        ],
        'add_server' => [
            'description' => 'Connect a new server over SSH',
            'steps' => [
                ['tool' => 'manage_private_key', 'action' => 'create', 'note' => 'Add the SSH private key (never returned again).'],
                ['tool' => 'manage_server', 'action' => 'create', 'note' => 'Set name, ip, port, user, private_key_uuid.'],
                ['tool' => 'manage_server', 'action' => 'validate', 'note' => 'Check the connection and install Docker if needed.'],
                ['tool' => 'get_server', 'note' => 'Check that the server is reachable and usable.'],
                ['tool' => 'manage_proxy', 'action' => 'get', 'note' => 'Check the proxy.'],
            ],
        ],
        'debug_failed_deploy' => [
            'description' => 'Find why a deployment failed',
            'steps' => [
                ['tool' => 'list_deployments', 'note' => 'application_uuid=... for the history.'],
                ['tool' => 'get_deployment', 'note' => 'include_log_summary=true (needs read:sensitive).'],
                ['tool' => 'list_env_keys', 'note' => 'Check for missing variables (names only).'],
                ['tool' => 'get_logs', 'note' => 'Only if the container runs (needs read:sensitive).'],
                ['tool' => 'deploy', 'note' => 'Redeploy after the fix; force=true to rebuild.'],
            ],
        ],
        'backup_database' => [
            'description' => 'Back up a database to S3',
            'steps' => [
                ['tool' => 'manage_s3_storage', 'action' => 'create', 'note' => 'Add the S3 storage (the secret is never returned).'],
                ['tool' => 'manage_s3_storage', 'action' => 'validate', 'note' => 'Check the credentials.'],
                ['tool' => 'manage_backup', 'action' => 'create', 'note' => 'Set frequency, s3_storage_uuid, save_s3=true; backup_now=true runs it at once.'],
                ['tool' => 'list_backup_executions', 'note' => 'Check the result.'],
            ],
        ],
    ];

    /**
     * @var array<int, string>
     */
    private const START_HERE = ['get_infrastructure_overview', 'search_resources', 'list_unhealthy_resources'];

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        if (is_null($this->resolveTeamId($request))) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $tools = $this->visibleTools();

        $topic = $request->get('topic');
        if ($topic !== null) {
            if (! is_string($topic) || ! isset(self::RECIPES[$topic])) {
                return $this->mcpError($request, 'Unknown topic. Use: '.implode(', ', array_keys(self::RECIPES)).'.');
            }

            return $this->mcpSuccess($request, $this->respond([
                'topic' => $topic,
                'description' => self::RECIPES[$topic]['description'],
                'steps' => collect(self::RECIPES[$topic]['steps'])
                    ->map(fn (array $step): array => $step + ['available' => $this->stepAvailable($step, $tools)])
                    ->all(),
                'notes' => ['Steps with available=false need more token abilities.', 'Ask the user before every action that needs confirm=true.'],
            ]));
        }

        $search = $request->get('search');
        if ($search !== null && (! is_string($search) || trim($search) === '')) {
            return $this->mcpError($request, 'search must be a non-empty string when provided.');
        }
        if (is_string($search)) {
            $needle = Str::lower(trim($search));
            $tools = array_filter($tools, fn (array $tool, string $name): bool => str_contains($name, $needle) || str_contains(Str::lower($tool['summary']), $needle), ARRAY_FILTER_USE_BOTH);
        }

        return $this->mcpSuccess($request, $this->respond([
            'start_here' => array_values(array_filter(self::START_HERE, fn (string $name): bool => isset($tools[$name]))),
            'read_tools' => $this->summaries(array_filter($tools, fn (array $tool): bool => $tool['read_only'])),
            'change_tools' => $this->summaries(array_filter($tools, fn (array $tool): bool => ! $tool['read_only'])),
            'recipes' => collect(self::RECIPES)->map(fn (array $recipe): string => $recipe['description'])->all(),
            'workflow' => [
                'Resolve a UUID with search_resources or list_*, then get_* for details.',
                'Debug with list_deployments / get_deployment before get_logs.',
                'Change tools use the same validation and permissions as the REST API.',
                'Ask the user before every action that needs confirm=true.',
                'Call coolify_help with topic=<recipe> for step-by-step guides.',
            ],
        ]));
    }

    /**
     * Registered tools that this token can see, keyed by name.
     *
     * @return array<string, array{summary: string, read_only: bool, actions: array<int, string>|null}>
     */
    private function visibleTools(): array
    {
        return collect(CoolifyServer::TOOLS)
            ->map(fn (string $class): Tool => app($class))
            ->filter(fn (Tool $tool): bool => $tool->eligibleForRegistration())
            ->mapWithKeys(fn (Tool $tool): array => [$tool->name() => [
                'summary' => Str::before($tool->description(), '. ').'.',
                'read_only' => ($tool->annotations()['readOnlyHint'] ?? false) === true,
                'actions' => $tool instanceof RestActionTool ? $tool->availableActionNames() : null,
            ]])
            ->all();
    }

    /**
     * A step is available when the token sees its tool and, for grouped tools, its action.
     *
     * @param  array{tool: string, action?: string, note: string}  $step
     * @param  array<string, array{summary: string, read_only: bool, actions: array<int, string>|null}>  $tools
     */
    private function stepAvailable(array $step, array $tools): bool
    {
        if (! isset($tools[$step['tool']])) {
            return false;
        }

        return ! isset($step['action']) || in_array($step['action'], $tools[$step['tool']]['actions'] ?? [], true);
    }

    /**
     * @param  array<string, array{summary: string, read_only: bool, actions: array<int, string>|null}>  $tools
     * @return array<string, string>
     */
    private function summaries(array $tools): array
    {
        return array_map(fn (array $tool): string => Str::limit(rtrim($tool['summary'], '.').'.', 140), $tools);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->enum(array_keys(self::RECIPES))->description('Optional recipe: step-by-step guide for a multi-step task.'),
            'search' => $schema->string()->description('Optional: only list tools whose name or summary contains this text.'),
        ];
    }
}
