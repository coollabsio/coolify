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
use App\Rules\DockerImageFormat;
use App\Rules\ValidGitBranch;
use App\Rules\ValidGitRepositoryUrl;
use App\Support\CreationErrorMessage;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateApplication implements Approvable, HasApprovalForm, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;

    /** Data keys the shared Action accepts from this tool. */
    public const DATA_KEYS = [
        'name', 'description', 'domains', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes',
        'dockerfile', 'docker_registry_image_name', 'docker_registry_image_tag', 'github_app_uuid', 'private_key_uuid',
    ];

    /**
     * Validation rules for the tool inputs, per application type.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(?string $type): array
    {
        $git = in_array($type, ['public', 'private-gh-app', 'private-deploy-key'], true);

        return [
            'type' => ['required', Rule::in(CreateApplicationAction::TYPES)],
            'project_uuid' => 'required|string',
            'server_uuid' => 'required|string',
            'environment_name' => 'nullable|string',
            'environment_uuid' => 'nullable|string',
            'destination_uuid' => 'nullable|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'domains' => 'nullable|string',
            'instant_deploy' => 'nullable|boolean',
            'git_repository' => $git ? ['required', 'string', ...($type === 'private-gh-app' ? [] : [new ValidGitRepositoryUrl])] : 'nullable|string',
            'git_branch' => $git ? ['required', 'string', new ValidGitBranch] : 'nullable|string',
            'build_pack' => $git ? ['required', Rule::enum(BuildPackTypes::class)] : 'nullable|string',
            'ports_exposes' => 'nullable|string|regex:/^(\d+)(,\d+)*$/',
            'dockerfile' => $type === 'dockerfile' ? 'required|string' : 'nullable|string',
            'docker_registry_image_name' => $type === 'dockerimage' ? ['required', 'string', 'max:255', new DockerImageFormat] : 'nullable|string',
            'docker_registry_image_tag' => $type === 'dockerimage' ? ValidationPatterns::dockerImageTagRules() : 'nullable|string',
            'github_app_uuid' => $type === 'private-gh-app' ? 'required|string' : 'nullable|string',
            'private_key_uuid' => $type === 'private-deploy-key' ? 'required|string' : 'nullable|string',
        ];
    }

    public function description(): string
    {
        return 'Create an application in a project/environment on a server in the current team. type is one of public '
            .'(git URL), private-gh-app (github_app_uuid from list_github_apps), private-deploy-key (private_key_uuid from '
            .'list_private_keys), dockerfile (plain-text dockerfile), or dockerimage (docker_registry_image_name). '
            .'Requires admin or owner role and human approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('One of: public, private-gh-app, private-deploy-key, dockerfile, dockerimage.')->required(),
            'project_uuid' => $schema->string()->description('Project UUID.')->required(),
            'server_uuid' => $schema->string()->description('Server UUID.')->required(),
            'environment_name' => $schema->string()->description('Provide this or environment_uuid.'),
            'environment_uuid' => $schema->string()->description('Provide this or environment_name.'),
            'destination_uuid' => $schema->string()->description('Required only when the server has multiple destinations.'),
            'name' => $schema->string()->description('Optional name.'),
            'domains' => $schema->string()->description('Comma-separated URLs (https://app.example.com). Omit to autogenerate. Not allowed for dockercompose.'),
            'git_repository' => $schema->string()->description('Git types: repository URL (public/private-deploy-key) or owner/repo (private-gh-app).'),
            'git_branch' => $schema->string()->description('Git types: branch name.'),
            'build_pack' => $schema->string()->description('Git types: nixpacks, railpack, static, dockerfile, or dockercompose.'),
            'ports_exposes' => $schema->string()->description('Comma-separated ports, e.g. "3000". Required for dockerimage; ignored for dockerfile (read from EXPOSE) and dockercompose.'),
            'dockerfile' => $schema->string()->description('dockerfile type: plain-text Dockerfile content.'),
            'docker_registry_image_name' => $schema->string()->description('dockerimage type: image name, e.g. nginx or ghcr.io/org/app.'),
            'docker_registry_image_tag' => $schema->string()->description('dockerimage type: tag (default latest).'),
            'github_app_uuid' => $schema->string()->description('private-gh-app type: GitHub app UUID (see list_github_apps).'),
            'private_key_uuid' => $schema->string()->description('private-deploy-key type: private key UUID (see list_private_keys).'),
            'instant_deploy' => $schema->boolean()->description('Deploy immediately after creation.'),
        ];
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
        $validator = Validator::make($all, self::rulesFor($type));
        if ($validator->fails()) {
            return 'Validation failed: '.collect($validator->errors()->toArray())->map(fn ($m, $k) => "{$k}: {$m[0]}")->implode(' ');
        }
        $args = $validator->validated();
        if (blank($args['environment_name'] ?? null) && blank($args['environment_uuid'] ?? null)) {
            return 'Provide environment_name or environment_uuid.';
        }

        $data = array_filter(array_intersect_key($args, array_flip(self::DATA_KEYS)), fn ($v) => $v !== null && $v !== '');
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
        } catch (\Throwable $e) {
            return CreationErrorMessage::for($e, 'Could not create application.');
        }

        $this->auditToolCall('create_application', 'success', ['resource_uuid' => $application->uuid, 'type' => $type]);

        return "Created {$type} application [{$application->uuid}]".(filled($application->fqdn) ? " at {$application->fqdn}." : '.');
    }
}
