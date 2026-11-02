<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\CallsRestApi;
use App\Mcp\Concerns\ChecksTokenAbilities;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base for MCP tools that group several REST API routes under one `action` argument.
 *
 * Subclasses only declare fixed routes. Agents cannot choose a route, only an action
 * from the list, path IDs, and a JSON body. Security checks run in the REST route.
 */
abstract class RestActionTool extends Tool
{
    use BuildsResponse;
    use CallsRestApi;
    use ChecksTokenAbilities;
    use ResolvesTeam;

    /**
     * An action has either one `path`, or `paths` keyed by the `resource` argument
     * (for example ['application' => 'applications/{uuid}/envs']).
     *
     * @return array<string, array{method: string, path?: string, paths?: array<string, string>, ability: string, confirm?: bool, description: string}>
     */
    abstract protected function actions(): array;

    /**
     * Path parameters this tool accepts, with descriptions.
     *
     * @return array<string, string>
     */
    abstract protected function pathParams(): array;

    /**
     * Description of the fields accepted in `data`. Empty when the tool takes no body.
     */
    protected function dataDescription(): string
    {
        return '';
    }

    /**
     * Actions that the current token has the ability for.
     *
     * @return array<string, array{method: string, path?: string, paths?: array<string, string>, ability: string, confirm?: bool, description: string}>
     */
    protected function availableActions(): array
    {
        return array_filter($this->actions(), fn (array $action): bool => $this->currentTokenCan($action['ability']));
    }

    /**
     * Names of the actions that the current token has the ability for.
     *
     * @return array<int, string>
     */
    public function availableActionNames(): array
    {
        return array_keys($this->availableActions());
    }

    public function shouldRegister(): bool
    {
        return $this->availableActions() !== [];
    }

    public function handle(Request $request): Response
    {
        $actions = $this->availableActions();
        $actionName = $request->get('action');

        $allActions = $this->actions();
        if (! is_string($actionName) || ! isset($allActions[$actionName])) {
            return $this->mcpError($request, 'action must be one of: '.implode(', ', array_keys($actions)).'.');
        }
        $action = $allActions[$actionName];

        // Hidden actions still reach this check, so the agent gets the missing ability, not "unknown action".
        if ($error = $this->ensureAbility($request, $action['ability'], $this->name)) {
            return $error;
        }

        if (is_null($this->resolveTeamId($request))) {
            return $this->mcpError($request, 'Invalid token.');
        }

        if (($action['confirm'] ?? false) && $error = $this->ensureConfirmed($request, "{$this->name} {$actionName}")) {
            return $error;
        }

        $path = $action['path'] ?? null;
        if (isset($action['paths'])) {
            $resource = $request->get('resource');
            if (! is_string($resource) || ! isset($action['paths'][$resource])) {
                return $this->mcpError($request, 'resource must be one of: '.implode(', ', array_keys($action['paths'])).'.');
            }
            $path = $action['paths'][$resource];
        }

        $data = $request->get('data') ?? [];
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            return $this->mcpError($request, 'data must be a JSON object.');
        }

        $pathParams = [];
        foreach (array_keys($this->pathParams()) as $param) {
            $value = $request->get($param);
            if ($value !== null) {
                if (! is_scalar($value)) {
                    return $this->mcpError($request, "{$param} must be a string.");
                }
                $pathParams[$param] = $value;
            }
        }

        try {
            $result = $this->callRestApi($action['method'], $path, $pathParams, $data);
        } catch (\InvalidArgumentException $e) {
            return $this->mcpError($request, $e->getMessage());
        }

        $context = ['action' => $actionName, 'resource' => $request->get('resource'), 'status' => $result['status']] + array_map('strval', $pathParams);

        if ($result['status'] >= 400) {
            $body = is_array($result['body']) ? $result['body'] : [];
            $message = (string) ($body['message'] ?? 'Request failed.');
            if (isset($body['errors']) && is_array($body['errors'])) {
                $message .= ' '.json_encode($body['errors']);
            }

            return $this->mcpError($request, $message, $context);
        }

        return $this->mcpSuccess($request, $this->respond(is_array($result['body']) ? $result['body'] : []), $context);
    }

    public function schema(JsonSchema $schema): array
    {
        $actionLines = collect($this->availableActions())
            ->map(fn (array $action, string $name): string => "{$name}: {$action['description']}".(($action['confirm'] ?? false) ? ' (needs confirm=true)' : ''))
            ->implode('; ');

        $fields = [
            'action' => $schema->string()->enum(array_keys($this->availableActions()))->description($actionLines)->required(),
        ];

        $resources = collect($this->availableActions())->pluck('paths')->filter()->flatMap(fn (array $paths): array => array_keys($paths))->unique()->values()->all();
        if ($resources !== []) {
            $fields['resource'] = $schema->string()->enum($resources)->description('Resource type for actions that support several ('.implode(' | ', $resources).').');
        }

        foreach ($this->pathParams() as $param => $description) {
            $fields[$param] = $schema->string()->description($description);
        }

        if ($this->dataDescription() !== '') {
            $fields['data'] = $schema->object()->description($this->dataDescription());
        }

        if (collect($this->availableActions())->contains(fn (array $action): bool => $action['confirm'] ?? false)) {
            $fields['confirm'] = $schema->boolean()->description('Must be true for destructive actions.');
        }

        return $fields;
    }
}
