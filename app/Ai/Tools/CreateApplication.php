<?php

namespace App\Ai\Tools;

use App\Actions\Application\CreateApplication as CreateApplicationAction;
use App\Actions\Shared\ResolveResourcePlacement;
use App\Ai\Concerns\AuthorizesToolAction;
use App\Ai\Contracts\HasApprovalForm;
use App\Ai\Ui\ApprovalForm;
use App\Ai\Ui\Field;
use App\Ai\Ui\FieldType;
use App\Enums\BuildPackTypes;
use App\Exceptions\ResourceCreationException;
use App\Exceptions\ResourcePlacementException;
use App\Mcp\Tools\CreateApplication as McpCreateApplication;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateApplication implements Approvable, HasApprovalForm, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Create an application in a project/environment on a server in the current team. type is one of public '
            .'(git URL), private-gh-app (github_app_uuid from list_github_apps), private-deploy-key (private_key_uuid from '
            .'list_private_keys), dockerfile (plain-text dockerfile), or dockerimage (docker_registry_image_name). '
            .'Requires admin or owner role and human approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        $mcp = (new McpCreateApplication)->schema($schema);
        unset($mcp['description']);

        return $mcp;
    }

    public function approvalForm(array $arguments): ApprovalForm
    {
        $type = (string) ($arguments['type'] ?? '');
        $text = fn (string $key, string $label, bool $required = false, ?string $help = null) => new Field(FieldType::Text, $key, $label, $arguments[$key] ?? '', required: $required, help: $help);

        $fields = [
            $text('name', 'Name', help: 'Leave blank for an auto-generated name.'),
            $text('domains', 'Domains', help: 'Comma-separated URLs. Leave blank to autogenerate.'),
            new Field(FieldType::Toggle, 'instant_deploy', 'Deploy immediately', filter_var($arguments['instant_deploy'] ?? false, FILTER_VALIDATE_BOOLEAN)),
        ];

        if (in_array($type, ['public', 'private-gh-app', 'private-deploy-key'], true)) {
            $fields[] = $text('git_repository', 'Repository', true);
            $fields[] = $text('git_branch', 'Branch', true);
            $fields[] = new Field(FieldType::Select, 'build_pack', 'Build pack', $arguments['build_pack'] ?? 'nixpacks',
                array_map(fn (BuildPackTypes $b) => $b->value, BuildPackTypes::cases()), required: true);
            $fields[] = $text('ports_exposes', 'Exposed ports', help: 'Comma-separated. Ignored for dockercompose.');
        } elseif ($type === 'dockerfile') {
            $fields[] = new Field(FieldType::Textarea, 'dockerfile', 'Dockerfile', $arguments['dockerfile'] ?? '', required: true);
        } elseif ($type === 'dockerimage') {
            $fields[] = $text('docker_registry_image_name', 'Image', true);
            $fields[] = $text('docker_registry_image_tag', 'Tag', help: 'Defaults to latest.');
            $fields[] = $text('ports_exposes', 'Exposed ports', true);
        }

        $fields[] = new Field(FieldType::Locked, 'type', 'Type', $type);
        if ($type === 'private-gh-app') {
            $fields[] = new Field(FieldType::Locked, 'github_app_uuid', 'GitHub app', $arguments['github_app_uuid'] ?? '');
        }
        if ($type === 'private-deploy-key') {
            $fields[] = new Field(FieldType::Locked, 'private_key_uuid', 'Private key', $arguments['private_key_uuid'] ?? '');
        }
        $fields[] = new Field(FieldType::Locked, 'project_uuid', 'Project', $arguments['project_uuid'] ?? '');
        $fields[] = new Field(FieldType::Locked, 'environment', 'Environment', $arguments['environment_name'] ?? ($arguments['environment_uuid'] ?? ''));
        $fields[] = new Field(FieldType::Locked, 'server_uuid', 'Server', $arguments['server_uuid'] ?? '');

        return new ApprovalForm('Create application', false, $fields);
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $this->authorizeToolGate('createAnyResource', 'create_application');

        $args = $request->all();
        $type = $args['type'] ?? 'application';
        $name = filled($args['name'] ?? null) ? "\"{$args['name']}\"" : '(auto-named)';
        $instant = filter_var($args['instant_deploy'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $where = ($args['project_uuid'] ?? '?').'/'.($args['environment_name'] ?? $args['environment_uuid'] ?? '?');

        return Approval::required("Create {$type} application {$name} in {$where} on server ".($args['server_uuid'] ?? '?').'.'
            .($instant ? ' It will deploy immediately.' : ''));
    }

    public function handle(Request $request): string
    {
        $this->authorizeToolGate('createAnyResource', 'create_application');

        $all = $request->all();
        $type = $all['type'] ?? null;
        $validator = Validator::make($all, McpCreateApplication::rulesFor($type));
        if ($validator->fails()) {
            return 'Validation failed: '.collect($validator->errors()->toArray())->map(fn ($m, $k) => "{$k}: {$m[0]}")->implode(' ');
        }
        $args = $validator->validated();
        if (blank($args['environment_name'] ?? null) && blank($args['environment_uuid'] ?? null)) {
            return 'Provide environment_name or environment_uuid.';
        }

        $data = array_filter(array_intersect_key($args, array_flip(McpCreateApplication::DATA_KEYS)), fn ($v) => $v !== null && $v !== '');
        if (isset($data['domains'])) {
            $data['domains'] = ValidationPatterns::normalizeApplicationDomains($data['domains']);
        }
        $instantDeploy = filter_var($args['instant_deploy'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $placement = ResolveResourcePlacement::run(
                $this->actingTeamId(), $args['project_uuid'], $args['environment_name'] ?? null,
                $args['environment_uuid'] ?? null, $args['server_uuid'], $args['destination_uuid'] ?? null,
            );
            $application = CreateApplicationAction::run($placement, $type, $data, $instantDeploy);
        } catch (ResourcePlacementException|ResourceCreationException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return 'Could not create application: '.$e->getMessage();
        }

        $this->auditToolCall('create_application', 'success', ['resource_uuid' => $application->uuid, 'type' => $type]);

        return "Created {$type} application [{$application->uuid}]".(filled($application->fqdn) ? " at {$application->fqdn}." : '.');
    }
}
