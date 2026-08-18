<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SharedEnvironmentVariablesController extends Controller
{
    private const ALLOWED_FIELDS = ['key', 'value', 'is_literal', 'is_multiline', 'is_shown_once', 'comment'];

    private function removeSensitiveData(SharedEnvironmentVariable $env): mixed
    {
        $env->makeHidden([
            'team_id',
            'project_id',
            'environment_id',
            'server_id',
            'version',
        ]);

        if (request()->attributes->get('can_read_sensitive', false) === true) {
            $env->makeVisible(['value']);
        }

        if ($env->is_shown_once ?? false) {
            $env->makeHidden(['value']);
        }

        return serializeApiResponse($env);
    }

    private function teamIdOrAbort(): int|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        return $teamId;
    }

    private function validateEnvPayload(Request $request, bool $requireKey = true): JsonResponse|true
    {
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        if ($request->has('key')) {
            $request->merge(['key' => ValidationPatterns::normalizeEnvironmentVariableKey((string) $request->key)]);
        }

        $validator = customApiValidator($request->all(), [
            'key' => ValidationPatterns::environmentVariableKeyRules(required: $requireKey),
            'value' => 'string|nullable',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
            'comment' => 'string|nullable|max:256',
        ]);

        $extraFields = array_diff(array_keys($request->all()), self::ALLOWED_FIELDS);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        if (! $requireKey && $request->all() === []) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        return true;
    }

    private function findEnvInScope(int $teamId, int|string $envId, string $type, array $scope = []): ?SharedEnvironmentVariable
    {
        $query = SharedEnvironmentVariable::ownedByCurrentTeamAPI($teamId)
            ->where('type', $type)
            ->where('id', $envId);

        if (array_key_exists('project_id', $scope)) {
            $query->where('project_id', $scope['project_id']);
        }
        if (array_key_exists('environment_id', $scope)) {
            $query->where('environment_id', $scope['environment_id']);
        }
        if (array_key_exists('server_id', $scope)) {
            $query->where('server_id', $scope['server_id']);
        }

        return $query->first();
    }

    private function keyExistsInScope(int $teamId, string $key, string $type, array $scope = [], ?int $exceptId = null): bool
    {
        $query = SharedEnvironmentVariable::ownedByCurrentTeamAPI($teamId)
            ->where('type', $type)
            ->where('key', $key);

        if (array_key_exists('project_id', $scope)) {
            $query->where('project_id', $scope['project_id']);
        } else {
            $query->whereNull('project_id');
        }
        if (array_key_exists('environment_id', $scope)) {
            $query->where('environment_id', $scope['environment_id']);
        } else {
            $query->whereNull('environment_id');
        }
        if (array_key_exists('server_id', $scope)) {
            $query->where('server_id', $scope['server_id']);
        } else {
            $query->whereNull('server_id');
        }

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    private function listEnvs(int $teamId, string $type, array $scope = []): JsonResponse
    {
        $query = SharedEnvironmentVariable::ownedByCurrentTeamAPI($teamId)
            ->where('type', $type)
            ->orderBy('id');

        if (array_key_exists('project_id', $scope)) {
            $query->where('project_id', $scope['project_id']);
        }
        if (array_key_exists('environment_id', $scope)) {
            $query->where('environment_id', $scope['environment_id']);
        }
        if (array_key_exists('server_id', $scope)) {
            $query->where('server_id', $scope['server_id']);
        }

        $envs = $query->get()->map(fn (SharedEnvironmentVariable $env) => $this->removeSensitiveData($env));

        return response()->json($envs);
    }

    private function createEnv(Request $request, int $teamId, string $type, array $attributes = []): JsonResponse
    {
        $validated = $this->validateEnvPayload($request, requireKey: true);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $this->authorize('create', SharedEnvironmentVariable::class);

        $scope = array_filter([
            'project_id' => $attributes['project_id'] ?? null,
            'environment_id' => $attributes['environment_id'] ?? null,
            'server_id' => $attributes['server_id'] ?? null,
        ], fn ($value) => ! is_null($value));

        if ($this->keyExistsInScope($teamId, $request->key, $type, $scope)) {
            return response()->json([
                'message' => 'Environment variable already exists. Use PATCH request to update it.',
            ], 409);
        }

        $env = SharedEnvironmentVariable::create([
            'key' => $request->key,
            'value' => $request->value,
            'is_literal' => $request->boolean('is_literal'),
            'is_multiline' => $request->boolean('is_multiline'),
            'is_shown_once' => $request->boolean('is_shown_once'),
            'comment' => $request->comment,
            'type' => $type,
            'team_id' => $teamId,
            'project_id' => $attributes['project_id'] ?? null,
            'environment_id' => $attributes['environment_id'] ?? null,
            'server_id' => $attributes['server_id'] ?? null,
        ]);

        auditLog('api.shared_env.created', [
            'team_id' => $teamId,
            'env_id' => $env->id,
            'env_key' => $env->key,
            'type' => $type,
        ]);

        return response()->json([
            'id' => $env->id,
        ], 201);
    }

    private function updateEnv(Request $request, int $teamId, int|string $envId, string $type, array $scope = []): JsonResponse
    {
        $env = $this->findEnvInScope($teamId, $envId, $type, $scope);
        if (! $env) {
            return response()->json(['message' => 'Environment variable not found.'], 404);
        }

        $this->authorize('update', $env);

        $validated = $this->validateEnvPayload($request, requireKey: false);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        if ($request->has('key') && $request->key !== $env->key) {
            if ($this->keyExistsInScope($teamId, $request->key, $type, $scope, exceptId: $env->id)) {
                return response()->json([
                    'message' => 'Environment variable already exists with this key.',
                ], 409);
            }
            $env->key = $request->key;
        }

        if ($request->has('value')) {
            $env->value = $request->value;
        }
        if ($request->has('is_literal')) {
            $env->is_literal = $request->boolean('is_literal');
        }
        if ($request->has('is_multiline')) {
            $env->is_multiline = $request->boolean('is_multiline');
        }
        if ($request->has('is_shown_once')) {
            $env->is_shown_once = $request->boolean('is_shown_once');
        }
        if ($request->has('comment')) {
            $env->comment = $request->comment;
        }

        $env->save();

        auditLog('api.shared_env.updated', [
            'team_id' => $teamId,
            'env_id' => $env->id,
            'env_key' => $env->key,
            'type' => $type,
        ]);

        return response()->json($this->removeSensitiveData($env->fresh()));
    }

    private function deleteEnv(int $teamId, int|string $envId, string $type, array $scope = []): JsonResponse
    {
        $env = $this->findEnvInScope($teamId, $envId, $type, $scope);
        if (! $env) {
            return response()->json(['message' => 'Environment variable not found.'], 404);
        }

        $this->authorize('delete', $env);

        $envKey = $env->key;
        $envIdValue = $env->id;
        $env->delete();

        auditLog('api.shared_env.deleted', [
            'team_id' => $teamId,
            'env_id' => $envIdValue,
            'env_key' => $envKey,
            'type' => $type,
        ]);

        return response()->json([
            'message' => 'Environment variable deleted.',
        ]);
    }

    private function resolveProject(int $teamId, string $uuid): Project|JsonResponse
    {
        $project = Project::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        return $project;
    }

    private function resolveServer(int $teamId, string $uuid): Server|JsonResponse
    {
        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        return $server;
    }

    private function resolveEnvironment(Project $project, string $environmentNameOrUuid): Environment|JsonResponse
    {
        $environment = $project->environments()->whereName($environmentNameOrUuid)->first();
        if (! $environment) {
            $environment = $project->environments()->whereUuid($environmentNameOrUuid)->first();
        }
        if (! $environment) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }

        return $environment;
    }

    // ── Team ──────────────────────────────────────────────────────────

    #[OA\Get(
        summary: 'List Team Shared Envs',
        description: 'List shared environment variables for the current team (type=team).',
        path: '/team/envs',
        operationId: 'list-team-shared-envs',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        responses: [
            new OA\Response(response: 200, description: 'Team shared environment variables.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ],
    )]
    public function team_envs(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $this->authorize('viewAny', SharedEnvironmentVariable::class);

        return $this->listEnvs($teamId, 'team');
    }

    #[OA\Post(
        summary: 'Create Team Shared Env',
        description: 'Create a shared environment variable for the current team (type=team).',
        path: '/team/envs',
        operationId: 'create-team-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['key'],
                properties: [
                    new OA\Property(property: 'key', type: 'string'),
                    new OA\Property(property: 'value', type: 'string', nullable: true),
                    new OA\Property(property: 'is_literal', type: 'boolean'),
                    new OA\Property(property: 'is_multiline', type: 'boolean'),
                    new OA\Property(property: 'is_shown_once', type: 'boolean'),
                    new OA\Property(property: 'comment', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Environment variable created.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 409, description: 'Environment variable already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function team_create_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        return $this->createEnv($request, $teamId, 'team');
    }

    #[OA\Patch(
        summary: 'Update Team Shared Env',
        description: 'Update a team shared environment variable by id.',
        path: '/team/envs/{env_id}',
        operationId: 'update-team-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'env_id', in: 'path', required: true, description: 'Shared env id (integer).', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Environment variable updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function team_update_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        return $this->updateEnv($request, $teamId, $request->route('env_id'), 'team');
    }

    #[OA\Delete(
        summary: 'Delete Team Shared Env',
        description: 'Delete a team shared environment variable by id.',
        path: '/team/envs/{env_id}',
        operationId: 'delete-team-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'env_id', in: 'path', required: true, description: 'Shared env id (integer).', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Environment variable deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function team_delete_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        return $this->deleteEnv($teamId, $request->route('env_id'), 'team');
    }

    // ── Project ───────────────────────────────────────────────────────

    #[OA\Get(
        summary: 'List Project Shared Envs',
        description: 'List shared environment variables for a project (type=project).',
        path: '/projects/{uuid}/envs',
        operationId: 'list-project-shared-envs',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Project shared environment variables.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function project_envs(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $project = $this->resolveProject($teamId, $request->route('uuid'));
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('view', $project);

        return $this->listEnvs($teamId, 'project', ['project_id' => $project->id]);
    }

    #[OA\Post(
        summary: 'Create Project Shared Env',
        description: 'Create a shared environment variable for a project (type=project).',
        path: '/projects/{uuid}/envs',
        operationId: 'create-project-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Environment variable created.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Environment variable already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function project_create_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $project = $this->resolveProject($teamId, $request->route('uuid'));
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('view', $project);

        return $this->createEnv($request, $teamId, 'project', ['project_id' => $project->id]);
    }

    #[OA\Patch(
        summary: 'Update Project Shared Env',
        description: 'Update a project shared environment variable by id.',
        path: '/projects/{uuid}/envs/{env_id}',
        operationId: 'update-project-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'env_id', in: 'path', required: true, description: 'Shared env id (integer).', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Environment variable updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function project_update_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $project = $this->resolveProject($teamId, $request->route('uuid'));
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('view', $project);

        return $this->updateEnv(
            $request,
            $teamId,
            $request->route('env_id'),
            'project',
            ['project_id' => $project->id],
        );
    }

    #[OA\Delete(
        summary: 'Delete Project Shared Env',
        description: 'Delete a project shared environment variable by id.',
        path: '/projects/{uuid}/envs/{env_id}',
        operationId: 'delete-project-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'env_id', in: 'path', required: true, description: 'Shared env id (integer).', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Environment variable deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function project_delete_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $project = $this->resolveProject($teamId, $request->route('uuid'));
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('view', $project);

        return $this->deleteEnv(
            $teamId,
            $request->route('env_id'),
            'project',
            ['project_id' => $project->id],
        );
    }

    // ── Environment ───────────────────────────────────────────────────

    #[OA\Get(
        summary: 'List Environment Shared Envs',
        description: 'List shared environment variables for a project environment (type=environment).',
        path: '/projects/{uuid}/environments/{environment_name_or_uuid}/envs',
        operationId: 'list-environment-shared-envs',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'environment_name_or_uuid', in: 'path', required: true, description: 'Environment name or UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Environment shared environment variables.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function environment_envs(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $project = $this->resolveProject($teamId, $request->route('uuid'));
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $environment = $this->resolveEnvironment($project, $request->route('environment_name_or_uuid'));
        if ($environment instanceof JsonResponse) {
            return $environment;
        }

        $this->authorize('view', $project);

        return $this->listEnvs($teamId, 'environment', ['environment_id' => $environment->id]);
    }

    #[OA\Post(
        summary: 'Create Environment Shared Env',
        description: 'Create a shared environment variable for a project environment (type=environment).',
        path: '/projects/{uuid}/environments/{environment_name_or_uuid}/envs',
        operationId: 'create-environment-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'environment_name_or_uuid', in: 'path', required: true, description: 'Environment name or UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Environment variable created.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Environment variable already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function environment_create_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $project = $this->resolveProject($teamId, $request->route('uuid'));
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $environment = $this->resolveEnvironment($project, $request->route('environment_name_or_uuid'));
        if ($environment instanceof JsonResponse) {
            return $environment;
        }

        $this->authorize('view', $project);

        return $this->createEnv($request, $teamId, 'environment', ['environment_id' => $environment->id]);
    }

    #[OA\Patch(
        summary: 'Update Environment Shared Env',
        description: 'Update an environment shared environment variable by id.',
        path: '/projects/{uuid}/environments/{environment_name_or_uuid}/envs/{env_id}',
        operationId: 'update-environment-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'environment_name_or_uuid', in: 'path', required: true, description: 'Environment name or UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'env_id', in: 'path', required: true, description: 'Shared env id (integer).', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Environment variable updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function environment_update_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $project = $this->resolveProject($teamId, $request->route('uuid'));
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $environment = $this->resolveEnvironment($project, $request->route('environment_name_or_uuid'));
        if ($environment instanceof JsonResponse) {
            return $environment;
        }

        $this->authorize('view', $project);

        return $this->updateEnv(
            $request,
            $teamId,
            $request->route('env_id'),
            'environment',
            ['environment_id' => $environment->id],
        );
    }

    #[OA\Delete(
        summary: 'Delete Environment Shared Env',
        description: 'Delete an environment shared environment variable by id.',
        path: '/projects/{uuid}/environments/{environment_name_or_uuid}/envs/{env_id}',
        operationId: 'delete-environment-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'environment_name_or_uuid', in: 'path', required: true, description: 'Environment name or UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'env_id', in: 'path', required: true, description: 'Shared env id (integer).', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Environment variable deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function environment_delete_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $project = $this->resolveProject($teamId, $request->route('uuid'));
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $environment = $this->resolveEnvironment($project, $request->route('environment_name_or_uuid'));
        if ($environment instanceof JsonResponse) {
            return $environment;
        }

        $this->authorize('view', $project);

        return $this->deleteEnv(
            $teamId,
            $request->route('env_id'),
            'environment',
            ['environment_id' => $environment->id],
        );
    }

    // ── Server ────────────────────────────────────────────────────────

    #[OA\Get(
        summary: 'List Server Shared Envs',
        description: 'List shared environment variables for a server (type=server).',
        path: '/servers/{uuid}/envs',
        operationId: 'list-server-shared-envs',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Server shared environment variables.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function server_envs(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $server = $this->resolveServer($teamId, $request->route('uuid'));
        if ($server instanceof JsonResponse) {
            return $server;
        }

        $this->authorize('view', $server);

        return $this->listEnvs($teamId, 'server', ['server_id' => $server->id]);
    }

    #[OA\Post(
        summary: 'Create Server Shared Env',
        description: 'Create a shared environment variable for a server (type=server).',
        path: '/servers/{uuid}/envs',
        operationId: 'create-server-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Environment variable created.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Environment variable already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function server_create_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $server = $this->resolveServer($teamId, $request->route('uuid'));
        if ($server instanceof JsonResponse) {
            return $server;
        }

        $this->authorize('view', $server);

        return $this->createEnv($request, $teamId, 'server', ['server_id' => $server->id]);
    }

    #[OA\Patch(
        summary: 'Update Server Shared Env',
        description: 'Update a server shared environment variable by id.',
        path: '/servers/{uuid}/envs/{env_id}',
        operationId: 'update-server-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'env_id', in: 'path', required: true, description: 'Shared env id (integer).', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Environment variable updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function server_update_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $server = $this->resolveServer($teamId, $request->route('uuid'));
        if ($server instanceof JsonResponse) {
            return $server;
        }

        $this->authorize('view', $server);

        return $this->updateEnv(
            $request,
            $teamId,
            $request->route('env_id'),
            'server',
            ['server_id' => $server->id],
        );
    }

    #[OA\Delete(
        summary: 'Delete Server Shared Env',
        description: 'Delete a server shared environment variable by id.',
        path: '/servers/{uuid}/envs/{env_id}',
        operationId: 'delete-server-shared-env',
        security: [['bearerAuth' => []]],
        tags: ['Shared Environment Variables'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'env_id', in: 'path', required: true, description: 'Shared env id (integer).', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Environment variable deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function server_delete_env(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $server = $this->resolveServer($teamId, $request->route('uuid'));
        if ($server instanceof JsonResponse) {
            return $server;
        }

        $this->authorize('view', $server);

        return $this->deleteEnv(
            $teamId,
            $request->route('env_id'),
            'server',
            ['server_id' => $server->id],
        );
    }
}
