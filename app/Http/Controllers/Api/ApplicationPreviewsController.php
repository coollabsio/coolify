<?php

namespace App\Http\Controllers\Api;

use App\Enums\BuildPackTypes;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class ApplicationPreviewsController extends Controller
{
    private const GIT_TYPES = ['github', 'gitlab', 'gitea', 'bitbucket'];

    #[OA\Get(
        summary: 'List Preview Deployments',
        description: 'List the preview deployments of an application, newest pull request first.',
        path: '/applications/{uuid}/previews',
        operationId: 'list-preview-deployments-by-application-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the application.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of preview deployments.', content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(ref: '#/components/schemas/ApplicationPreview'),
            )),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('view', $application);

        $previews = $application->previews()
            ->get()
            ->map(fn (ApplicationPreview $preview): array => $this->formatPreview($preview));

        return response()->json($previews);
    }

    #[OA\Get(
        summary: 'Get Preview Deployment',
        description: 'Get a preview deployment by pull request ID.',
        path: '/applications/{uuid}/previews/{pull_request_id}',
        operationId: 'get-preview-deployment-by-pull-request-id',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the application.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'pull_request_id', in: 'path', description: 'Pull request ID of the preview.', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Preview deployment.', content: new OA\JsonContent(ref: '#/components/schemas/ApplicationPreview')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('view', $application);

        $pullRequestIdRaw = $request->route('pull_request_id');
        if (! ctype_digit((string) $pullRequestIdRaw) || (int) $pullRequestIdRaw <= 0 || (int) $pullRequestIdRaw > 2147483647) {
            return response()->json(['message' => 'Invalid pull_request_id.'], 422);
        }

        $preview = $application->previews()->where('pull_request_id', (int) $pullRequestIdRaw)->first();
        if (! $preview) {
            return response()->json(['message' => 'Preview not found.'], 404);
        }

        return response()->json($this->formatPreview($preview));
    }

    #[OA\Post(
        summary: 'Create Preview Deployment',
        description: 'Open a preview deployment for a pull request and queue its deployment. When the preview already exists, it is redeployed. Git based applications need git_type unless they use a GitHub or GitLab App source; Bitbucket also needs commit. Docker Image applications need docker_tag for a new preview.',
        path: '/applications/{uuid}/previews',
        operationId: 'create-preview-deployment-by-application-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the application.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['pull_request_id'],
            properties: [
                new OA\Property(property: 'pull_request_id', type: 'integer', minimum: 1, maximum: 2147483647, example: 42),
                new OA\Property(property: 'pull_request_html_url', type: 'string', nullable: true, example: 'https://github.com/org/repo/pull/42'),
                new OA\Property(property: 'git_type', type: 'string', nullable: true, enum: self::GIT_TYPES, description: 'Git provider that hosts the pull request. Sets the ref Coolify fetches (pull/{id}/head or merge-requests/{id}/head).'),
                new OA\Property(property: 'commit', type: 'string', nullable: true, description: 'Commit SHA to deploy. Required for Bitbucket.'),
                new OA\Property(property: 'docker_tag', type: 'string', nullable: true, description: 'Docker image tag. Docker Image applications only.'),
                new OA\Property(property: 'force', type: 'boolean', default: false, description: 'Rebuild without cache.'),
                new OA\Property(property: 'instant_deploy', type: 'boolean', default: true, description: 'Queue a deployment. Set to false to only create the preview, for example to set its domains first.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Existing preview deployment updated.', content: new OA\JsonContent(ref: '#/components/schemas/ApplicationPreviewDeployment')),
            new OA\Response(response: 201, description: 'Preview deployment created.', content: new OA\JsonContent(ref: '#/components/schemas/ApplicationPreviewDeployment')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(response: 429, description: 'Deployment queue is full.'),
        ],
    )]
    #[OA\Schema(
        schema: 'ApplicationPreview',
        type: 'object',
        properties: [
            new OA\Property(property: 'uuid', type: 'string'),
            new OA\Property(property: 'pull_request_id', type: 'integer'),
            new OA\Property(property: 'pull_request_html_url', type: 'string', nullable: true),
            new OA\Property(property: 'git_type', type: 'string', nullable: true),
            new OA\Property(property: 'status', type: 'string'),
            new OA\Property(property: 'domains', type: 'string', nullable: true),
            new OA\Property(property: 'docker_compose_domains', type: 'array', nullable: true, items: new OA\Items(properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'domain', type: 'string', nullable: true),
                new OA\Property(property: 'redirect', type: 'string', nullable: true),
            ], type: 'object')),
            new OA\Property(property: 'domain_port_overrides', type: 'object', nullable: true),
            new OA\Property(property: 'docker_registry_image_tag', type: 'string', nullable: true),
            new OA\Property(property: 'last_online_at', type: 'string', nullable: true),
            new OA\Property(property: 'created_at', type: 'string'),
            new OA\Property(property: 'updated_at', type: 'string'),
        ],
    )]
    #[OA\Schema(
        schema: 'ApplicationPreviewDeployment',
        type: 'object',
        properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'deployment_uuid', type: 'string', nullable: true),
            new OA\Property(property: 'preview', ref: '#/components/schemas/ApplicationPreview'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('deploy', $application);

        $isDockerImage = $application->build_pack === 'dockerimage';
        if (! $isDockerImage && ! $application->git_based()) {
            return response()->json(['message' => 'Preview deployments need a Git based or Docker Image application.'], 400);
        }

        $rules = [
            'pull_request_id' => 'required|integer|min:1|max:2147483647',
            'pull_request_html_url' => 'nullable|url|max:2048',
            'force' => 'boolean',
            'instant_deploy' => 'boolean',
        ];
        if ($isDockerImage) {
            $rules['git_type'] = 'missing';
            $rules['commit'] = 'missing';
            $rules['docker_tag'] = ['nullable', 'string', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/'];
        } else {
            $rules['git_type'] = ['nullable', 'string', Rule::in(self::GIT_TYPES)];
            $rules['commit'] = ['nullable', 'string', 'regex:/^[0-9a-fA-F]{7,40}$/', 'required_if:git_type,bitbucket'];
            $rules['docker_tag'] = 'missing';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $pullRequestId = (int) $request->input('pull_request_id');
        $dockerTag = $request->string('docker_tag')->trim()->value() ?: null;
        $preview = $application->previews()->where('pull_request_id', $pullRequestId)->first();
        $gitType = $request->input('git_type') ?? $preview?->git_type ?? $this->sourceGitType($application);

        if (! $isDockerImage && $gitType === null) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['git_type' => ['The git type field is required for applications without a GitHub or GitLab App source.']],
            ], 422);
        }
        if ($isDockerImage && $dockerTag === null && blank($preview?->docker_registry_image_tag)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['docker_tag' => ['The docker tag field is required for a new Docker Image preview.']],
            ], 422);
        }

        $created = ! $preview;
        if ($created) {
            $isCompose = $application->build_pack === BuildPackTypes::DOCKERCOMPOSE->value;
            $preview = ApplicationPreview::create([
                'application_id' => $application->id,
                'pull_request_id' => $pullRequestId,
                'pull_request_html_url' => $request->input('pull_request_html_url') ?? '',
                'git_type' => $isDockerImage ? null : $gitType,
                'docker_compose_domains' => $isCompose ? $application->docker_compose_domains : null,
                'docker_registry_image_tag' => $dockerTag,
            ]);
            $isCompose
                ? $preview->generate_preview_fqdn_compose()
                : $preview->generate_preview_fqdn();
        } else {
            $preview->fill(array_filter([
                'pull_request_html_url' => $request->input('pull_request_html_url'),
                'git_type' => $isDockerImage ? null : $gitType,
                'docker_registry_image_tag' => $dockerTag,
            ], fn (mixed $value): bool => $value !== null));
            $preview->save();
        }

        if ($created) {
            auditLog('api.application.preview_created', [
                'team_id' => $teamId,
                'application_uuid' => $application->uuid,
                'pull_request_id' => $pullRequestId,
            ]);
        }

        $status = $created ? 201 : 200;
        if (! $request->boolean('instant_deploy', true)) {
            return response()->json([
                'message' => $created ? 'Preview created.' : 'Preview updated.',
                'deployment_uuid' => null,
                'preview' => $this->formatPreview($preview->refresh()),
            ], $status);
        }

        $deploymentUuid = new_public_id();
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            pull_request_id: $pullRequestId,
            commit: $request->input('commit'),
            force_rebuild: $request->boolean('force'),
            is_api: true,
            git_type: $preview->git_type,
            docker_registry_image_tag: $preview->docker_registry_image_tag,
        );
        if ($result['status'] === 'queue_full') {
            return response()->json(['message' => $result['message']], 429)->header('Retry-After', 60);
        }

        $queued = $result['status'] !== 'skipped';
        if ($queued) {
            auditLog('api.deployment.triggered', [
                'resource_type' => 'application',
                'application_uuid' => $application->uuid,
                'application_name' => $application->name,
                'deployment_uuid' => $deploymentUuid,
                'force_rebuild' => $request->boolean('force'),
                'pull_request_id' => $pullRequestId,
            ]);
        }

        return response()->json([
            'message' => $queued ? 'Preview deployment queued.' : $result['message'],
            'deployment_uuid' => $queued ? $deploymentUuid : null,
            'preview' => $this->formatPreview($preview->refresh()),
        ], $status);
    }

    private function sourceGitType(Application $application): ?string
    {
        return match ($application->source?->getMorphClass()) {
            GithubApp::class => 'github',
            GitlabApp::class => 'gitlab',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPreview(ApplicationPreview $preview): array
    {
        $composeDomains = json_decode($preview->docker_compose_domains ?: 'null', true);

        return [
            'uuid' => $preview->uuid,
            'pull_request_id' => $preview->pull_request_id,
            'pull_request_html_url' => $preview->pull_request_html_url ?: null,
            'git_type' => $preview->git_type,
            'status' => $preview->status,
            'domains' => $preview->fqdn,
            'docker_compose_domains' => is_array($composeDomains)
                ? collect($composeDomains)
                    ->map(fn (mixed $entry, string|int $name): array => ['name' => (string) $name, 'domain' => data_get($entry, 'domain') ?: null, 'redirect' => data_get($entry, 'redirect')])
                    ->values()
                    ->all()
                : null,
            'domain_port_overrides' => $preview->domain_port_overrides,
            'docker_registry_image_tag' => $preview->docker_registry_image_tag,
            'last_online_at' => $preview->last_online_at,
            'created_at' => $preview->created_at,
            'updated_at' => $preview->updated_at,
        ];
    }
}
