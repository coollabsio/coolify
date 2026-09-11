<?php

namespace App\Ai\Tools;

use App\Actions\Project\CreateEnvironment as CreateEnvironmentAction;
use App\Ai\Concerns\AuthorizesToolAction;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateEnvironment implements Approvable, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Create an environment inside a project in the current team. Requires admin or owner role and human '
            .'approval. Provide project_uuid and name.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_uuid' => $schema->string()->description('Project UUID.')->required(),
            'name' => $schema->string()->description('Environment name.')->required(),
        ];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $this->authorizeToolGate('createAnyResource', 'create_environment');

        $args = $request->all();

        return Approval::required('Create environment "'.($args['name'] ?? '?').'" in project '.($args['project_uuid'] ?? '?').'.');
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'project_uuid' => 'required|string',
            'name' => 'required|string',
        ]);

        $this->authorizeToolGate('createAnyResource', 'create_environment');

        $project = Project::whereTeamId($this->actingTeamId())->whereUuid($args['project_uuid'])->first();
        if (! $project) {
            return "Project [{$args['project_uuid']}] was not found in this team.";
        }

        try {
            $environment = CreateEnvironmentAction::run($project, $args['name']);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        $this->auditToolCall('create_environment', 'success', ['resource_uuid' => $environment->uuid]);

        return "Created environment \"{$environment->name}\" [{$environment->uuid}].";
    }
}
