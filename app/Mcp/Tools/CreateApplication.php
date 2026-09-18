<?php

namespace App\Mcp\Tools;

use App\Actions\Application\CreateApplication as CreateApplicationAction;
use App\Actions\Shared\ResolveResourcePlacement;
use App\Enums\BuildPackTypes;
use App\Exceptions\ResourceCreationException;
use App\Exceptions\ResourcePlacementException;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Rules\DockerImageFormat;
use App\Rules\ValidGitBranch;
use App\Rules\ValidGitRepositoryUrl;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateApplication extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    protected string $name = 'create_application';

    protected string $description = 'Create an application (type: public, private-gh-app, private-deploy-key, dockerfile, dockerimage) in a project/environment on a server owned by the authenticated team. dockerfile is plain text. Requires write ability (deploy ability when instant_deploy=true). Use list_github_apps / list_private_keys for the private git types.';

    /** Data keys the shared Action accepts from this tool. */
    public const DATA_KEYS = [
        'name', 'description', 'domains', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes',
        'dockerfile', 'docker_registry_image_name', 'docker_registry_image_tag', 'github_app_uuid', 'private_key_uuid',
    ];

    /**
     * Validation rules for the tool inputs, per application type. Shared with the AI tool.
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

        $type = $request->get('type');
        $validator = Validator::make($request->all(), self::rulesFor($type));
        if ($validator->fails()) {
            return $this->mcpError($request, 'Validation failed: '.collect($validator->errors()->toArray())->map(fn ($m, $k) => "{$k}: {$m[0]}")->implode(' '));
        }
        $args = $validator->validated();

        if (blank($args['environment_name'] ?? null) && blank($args['environment_uuid'] ?? null)) {
            return $this->mcpError($request, 'Provide environment_name or environment_uuid.');
        }

        $data = array_filter(array_intersect_key($args, array_flip(self::DATA_KEYS)), fn ($v) => $v !== null);
        if (isset($data['domains'])) {
            $data['domains'] = ValidationPatterns::normalizeApplicationDomains($data['domains']);
        }

        try {
            $placement = ResolveResourcePlacement::run(
                $teamId, $args['project_uuid'], $args['environment_name'] ?? null,
                $args['environment_uuid'] ?? null, $args['server_uuid'], $args['destination_uuid'] ?? null,
            );
            $application = CreateApplicationAction::run($placement, $type, $data, $instantDeploy);
        } catch (ResourcePlacementException|ResourceCreationException $e) {
            return $this->mcpError($request, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->mcpError($request, $e->getMessage());
        }

        auditLog('mcp.create_application', ['team_id' => $teamId, 'uuid' => $application->uuid, 'type' => $type, 'build_pack' => $application->build_pack, 'instant_deploy' => $instantDeploy]);

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'uuid' => $application->uuid,
            'domains' => $application->fqdn,
            'next_tools' => [['tool' => 'get_application', 'args' => ['uuid' => $application->uuid]]],
        ]), ['resource_uuid' => $application->uuid]);
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
            'description' => $schema->string()->description('Optional description.'),
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
}
