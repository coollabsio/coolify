<?php

namespace App\Ai\Tools;

use App\Actions\Project\CreateProject as CreateProjectAction;
use App\Ai\Concerns\AuthorizesToolAction;
use App\Ai\Contracts\HasApprovalForm;
use App\Ai\Ui\ApprovalForm;
use App\Ai\Ui\Field;
use App\Ai\Ui\FieldType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateProject implements Approvable, HasApprovalForm, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Create a project in the current team. A default "production" environment is created automatically. '
            .'Requires admin or owner role and human approval. Provide name and optional description.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Project name.')->required(),
            'description' => $schema->string()->description('Optional description.'),
        ];
    }

    public function approvalForm(array $arguments): ApprovalForm
    {
        return new ApprovalForm('Create project', false, [
            new Field(FieldType::Text, 'name', 'Name', $arguments['name'] ?? '', required: true),
            new Field(FieldType::Text, 'description', 'Description', $arguments['description'] ?? ''),
        ]);
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $this->authorizeToolGate('createAnyResource', 'create_project');

        return Approval::required('Create project "'.($request->all()['name'] ?? '?').'" in the current team.');
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
        ]);

        $this->authorizeToolGate('createAnyResource', 'create_project');

        $project = CreateProjectAction::run($this->actingTeamId(), $args['name'], $args['description'] ?? null);

        $this->auditToolCall('create_project', 'success', ['resource_uuid' => $project->uuid]);

        return "Created project \"{$project->name}\" [{$project->uuid}].";
    }
}
