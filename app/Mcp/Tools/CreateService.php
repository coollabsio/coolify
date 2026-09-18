<?php

namespace App\Mcp\Tools;

use App\Actions\Service\CreateService as CreateServiceAction;
use App\Actions\Shared\ResolveResourcePlacement;
use App\Exceptions\ResourcePlacementException;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateService extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    protected string $name = 'create_service';

    protected string $description = 'Create a service from a one-click template slug (see list_service_templates) or from base64 docker_compose_raw, in a project/environment on a server owned by the authenticated team. Requires write ability (deploy ability when instant_deploy=true).';

    public function handle(Request $request): Response
    {
        $instantDeploy = filter_var($request->get('instant_deploy'), FILTER_VALIDATE_BOOLEAN);
        if ($error = $this->ensureAbility($request, $instantDeploy ? 'deploy' : 'write', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $templateSlug = $request->get('type');
        $dockerComposeRaw = $request->get('docker_compose_raw');
        if (blank($templateSlug) && blank($dockerComposeRaw)) {
            return $this->mcpError($request, 'Provide either type (a template slug) or docker_compose_raw.');
        }

        try {
            $placement = ResolveResourcePlacement::run(
                $teamId,
                (string) $request->get('project_uuid'),
                $request->get('environment_name'),
                $request->get('environment_uuid'),
                (string) $request->get('server_uuid'),
                $request->get('destination_uuid'),
            );
            $service = CreateServiceAction::run($placement, $templateSlug, $dockerComposeRaw, array_filter([
                'name' => $request->get('name'),
                'description' => $request->get('description'),
            ]), $instantDeploy);
        } catch (ResourcePlacementException $e) {
            return $this->mcpError($request, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->mcpError($request, $e->getMessage());
        }

        auditLog('mcp.create_service', ['team_id' => $teamId, 'uuid' => $service->uuid, 'service_type' => $service->service_type]);

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'uuid' => $service->uuid,
            'next_tools' => [['tool' => 'get_service', 'args' => ['uuid' => $service->uuid]]],
        ]), ['resource_uuid' => $service->uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('One-click template slug (from list_service_templates). Provide this or docker_compose_raw.'),
            'docker_compose_raw' => $schema->string()->description('Base64-encoded docker compose. Provide this or type.'),
            'project_uuid' => $schema->string()->description('Project UUID.')->required(),
            'server_uuid' => $schema->string()->description('Server UUID.')->required(),
            'environment_name' => $schema->string()->description('Provide this or environment_uuid.'),
            'environment_uuid' => $schema->string()->description('Provide this or environment_name.'),
            'destination_uuid' => $schema->string()->description('Required only when the server has multiple destinations.'),
            'name' => $schema->string()->description('Optional name.'),
            'description' => $schema->string()->description('Optional description.'),
            'instant_deploy' => $schema->boolean()->description('Start the service immediately after creation.'),
        ];
    }
}
