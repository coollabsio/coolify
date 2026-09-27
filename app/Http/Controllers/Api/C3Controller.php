<?php

namespace App\Http\Controllers\Api;

use App\Actions\C3\DemoteSite;
use App\Actions\C3\PromoteSite;
use App\Actions\C3\RegenerateStagingCredentials;
use App\Exceptions\C3PromotionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

/**
 * Connect3 fork: project-level staging/live controls used by redesign-engine (PRD section 9).
 */
class C3Controller extends Controller
{
    #[OA\Get(
        summary: 'Connect3 site status',
        description: 'Slug, state, staging URL and credentials (credentials only with read:sensitive or root).',
        path: '/projects/{uuid}/c3',
        operationId: 'c3-get-project-site',
        security: [['bearerAuth' => []]],
        tags: ['Connect3'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Site status'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $project = $this->findProject($teamId, $request->uuid);
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    #[OA\Patch(
        summary: 'Update Connect3 fields',
        description: 'Set or change client_slug and ai_gateway_key_id.',
        path: '/projects/{uuid}/c3',
        operationId: 'c3-update-project-site',
        security: [['bearerAuth' => []]],
        tags: ['Connect3'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(type: 'object', properties: [
            'client_slug' => ['type' => 'string', 'description' => 'DNS label, lowercase, no "--".'],
            'ai_gateway_key_id' => ['type' => 'string'],
        ]))),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $allowedFields = ['client_slug', 'ai_gateway_key_id'];
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $project = $this->findProject($teamId, $request->uuid);
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $this->authorize('update', $project);

        $validator = Validator::make($request->all(), [
            'client_slug' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', 'not_regex:/--/', 'unique:projects,client_slug,'.$project->id],
            'ai_gateway_key_id' => ['nullable', 'string', 'max:255'],
        ]);
        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }
        if ($project->isLive() && $request->has('client_slug') && $request->client_slug !== $project->client_slug) {
            return response()->json(['message' => 'Demote the site before changing its slug.'], 422);
        }

        $project->fill($request->only($allowedFields));
        $project->save();

        return response()->json($this->payload($project->fresh()));
    }

    #[OA\Post(
        summary: 'Promote to live',
        description: 'Runs the DNS pre-flight, then routes the given client domains to the project. Blocked (422) when DNS does not point at this server.',
        path: '/projects/{uuid}/c3/promote',
        operationId: 'c3-promote-project-site',
        security: [['bearerAuth' => []]],
        tags: ['Connect3'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(type: 'object', properties: [
            'domains' => ['oneOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]], 'description' => 'Client domain(s), comma separated string or array.'],
        ]))),
        responses: [
            new OA\Response(response: 200, description: 'Promoted'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, description: 'Pre-flight blocked; nothing changed'),
        ]
    )]
    public function promote(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $project = $this->findProject($teamId, $request->uuid);
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $this->authorize('update', $project);

        $validator = Validator::make($request->all(), [
            'domains' => ['required'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        try {
            $dns = PromoteSite::run($project, $request->input('domains'));
        } catch (C3PromotionBlockedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'dns' => $e->dnsResults,
            ], 422);
        }

        return response()->json($this->payload($project->fresh()) + ['dns' => $dns]);
    }

    #[OA\Post(
        summary: 'Demote to staged',
        description: 'Removes the live routers. Staging URL and credentials are unchanged.',
        path: '/projects/{uuid}/c3/demote',
        operationId: 'c3-demote-project-site',
        security: [['bearerAuth' => []]],
        tags: ['Connect3'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(type: 'object', properties: [
            'reason' => ['type' => 'string'],
        ]))),
        responses: [
            new OA\Response(response: 200, description: 'Demoted'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function demote(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $project = $this->findProject($teamId, $request->uuid);
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $this->authorize('update', $project);

        DemoteSite::run($project, is_string($request->input('reason')) ? $request->input('reason') : null);

        return response()->json($this->payload($project->fresh()));
    }

    #[OA\Post(
        summary: 'Regenerate staging credentials',
        description: 'Rotates the staging basic-auth password. Effective immediately, no redeploy.',
        path: '/projects/{uuid}/c3/regenerate-credentials',
        operationId: 'c3-regenerate-staging-credentials',
        security: [['bearerAuth' => []]],
        tags: ['Connect3'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Regenerated'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function regenerateCredentials(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $project = $this->findProject($teamId, $request->uuid);
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $this->authorize('update', $project);
        if (blank($project->client_slug)) {
            return response()->json(['message' => 'Project has no client slug.'], 422);
        }

        RegenerateStagingCredentials::run($project);

        return response()->json($this->payload($project->fresh()));
    }

    private function findProject(int $teamId, ?string $uuid): ?Project
    {
        if (blank($uuid)) {
            return null;
        }

        return Project::whereTeamId($teamId)->whereUuid($uuid)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Project $project): array
    {
        $canReadSensitive = request()->attributes->get('can_read_sensitive', false) === true;
        $primary = $project->primaryApplication();

        $data = [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'client_slug' => $project->client_slug,
            'site_state' => $project->site_state,
            'staging_apex' => c3_stagingApex(),
            'staging_url' => $project->stagingUrl(),
            'staging_auth_user' => $project->staging_auth_user,
            'live_domains' => $project->liveDomainsList(),
            'live_urls' => $project->isLive() ? array_map(fn ($d) => "https://{$d}", $project->liveDomainsList()) : [],
            'ai_gateway_key_id' => $project->ai_gateway_key_id,
            'primary_application_uuid' => $primary?->uuid,
        ];
        if ($canReadSensitive) {
            $data['staging_auth_pass'] = $project->staging_auth_pass;
        }

        return $data;
    }
}
