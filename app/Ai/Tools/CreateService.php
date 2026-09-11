<?php

namespace App\Ai\Tools;

use App\Actions\Service\CreateService as CreateServiceAction;
use App\Actions\Shared\ResolveResourcePlacement;
use App\Ai\Concerns\AuthorizesToolAction;
use App\Exceptions\ResourcePlacementException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateService implements Approvable, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Create a service from a one-click template slug (see list_service_templates) or from base64 '
            .'docker_compose_raw, in a project/environment on a server in the current team. Requires admin or owner role '
            .'and human approval. Provide type (template slug) or docker_compose_raw, plus project_uuid, server_uuid, and environment_name.';
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
            'instant_deploy' => $schema->boolean()->description('Deploy the service immediately after creation.'),
        ];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $this->authorizeToolGate('createAnyResource', 'create_service');

        $args = $request->all();
        $what = $args['type'] ?? 'service (from compose)';
        $instant = filter_var($args['instant_deploy'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $where = ($args['project_uuid'] ?? '?').'/'.($args['environment_name'] ?? $args['environment_uuid'] ?? '?');

        return Approval::required("Create {$what} in {$where} on server ".($args['server_uuid'] ?? '?').'.'
            .($instant ? ' It will deploy immediately.' : ''));
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'type' => 'nullable|string',
            'docker_compose_raw' => 'nullable|string',
            'project_uuid' => 'required|string',
            'server_uuid' => 'required|string',
            'environment_name' => 'nullable|string',
            'environment_uuid' => 'nullable|string',
            'destination_uuid' => 'nullable|string',
            'name' => 'nullable|string',
            'instant_deploy' => 'nullable|boolean',
        ]);

        $this->authorizeToolGate('createAnyResource', 'create_service');

        if (blank($args['type'] ?? null) && blank($args['docker_compose_raw'] ?? null)) {
            return 'Provide either type (a template slug) or docker_compose_raw.';
        }

        try {
            $placement = ResolveResourcePlacement::run(
                $this->actingTeamId(),
                $args['project_uuid'],
                $args['environment_name'] ?? null,
                $args['environment_uuid'] ?? null,
                $args['server_uuid'],
                $args['destination_uuid'] ?? null,
            );
            $service = CreateServiceAction::run(
                $placement,
                $args['type'] ?? null,
                $args['docker_compose_raw'] ?? null,
                array_filter(['name' => $args['name'] ?? null]),
                filter_var($args['instant_deploy'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
        } catch (ResourcePlacementException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return 'Could not create service: '.$e->getMessage();
        }

        $this->auditToolCall('create_service', 'success', ['resource_uuid' => $service->uuid]);

        return "Created service [{$service->uuid}].";
    }
}
