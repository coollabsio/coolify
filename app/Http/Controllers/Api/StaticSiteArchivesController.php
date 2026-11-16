<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Services\Dns\ResourceDnsRecords;
use App\Support\StaticSiteArchive;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class StaticSiteArchivesController extends Controller
{
    use Concerns\RequiresDeployAbility;

    #[OA\Post(
        path: '/applications/archive/uploads',
        operationId: 'upload-static-site-archive',
        summary: 'Upload static site archive',
        description: 'Upload a .tar, .tar.gz, or .tgz archive of a static site in DropZone chunks (dzuuid, dzchunkindex, dztotalchunkcount, dzchunkbyteoffset, dztotalfilesize). Send the same upload_id with every chunk. The last chunk validates the archive: at most 1 GiB, 5 GiB extracted, 100,000 entries, and only regular files and directories with relative paths. The upload is kept for 24 hours. Without application_uuid, the token user must be able to create applications.',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['upload_id', 'file'],
                    properties: [
                        'upload_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'A UUID that the client generates for this upload.'],
                        'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'The archive, or one chunk of it.'],
                        'application_uuid' => ['type' => 'string', 'description' => 'The archive site that this upload is a new version of.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Chunk received.'),
            new OA\Response(
                response: 201,
                description: 'Upload completed and validated.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'upload_id' => ['type' => 'string'],
                        'size' => ['type' => 'integer', 'description' => 'The size of the archive in bytes.'],
                        'sha' => ['type' => 'string', 'description' => 'The site version: the first 40 hex characters of the SHA-256 of the archive.'],
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token user cannot create applications or update and deploy the application.'),
            new OA\Response(response: 404, description: 'Application not found.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function upload(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $applicationUuid = $request->input('application_uuid');
        if (filled($applicationUuid)) {
            $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $applicationUuid)->where('is_archive_source', true)->first();
            if (! $application) {
                return response()->json(['message' => 'Application not found.'], 404);
            }
            $this->authorize('update', $application);
            $this->authorize('deploy', $application);
        } else {
            $this->authorize('create', Application::class);
        }

        return StaticSiteArchive::receiveUpload($request, $teamId);
    }

    #[OA\Post(
        path: '/applications/archive',
        operationId: 'create-static-site-archive-application',
        summary: 'Create (static site from an archive)',
        description: 'Create a static site that is deployed from uploaded archives instead of a Git repository. With upload_id, the uploaded archive becomes the first version of the site and is deployed unless instant_deploy is false. Deploying needs a token with the deploy ability.',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['project_uuid', 'server_uuid'],
                properties: [
                    'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                    'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                    'environment_name' => ['type' => 'string', 'description' => 'The environment name. You need to provide at least one of environment_name or environment_uuid.'],
                    'environment_uuid' => ['type' => 'string', 'description' => 'The environment UUID. You need to provide at least one of environment_name or environment_uuid.'],
                    'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID. Required when the server has more than one destination.'],
                    'name' => ['type' => 'string', 'description' => 'The application name.'],
                    'description' => ['type' => 'string', 'description' => 'The application description.'],
                    'domains' => ['type' => 'string', 'description' => 'The application URLs in a comma-separated list.'],
                    'autogenerate_domain' => ['type' => 'boolean', 'default' => true, 'description' => 'Generate a domain when domains is empty.'],
                    'force_domain_override' => ['type' => 'boolean', 'default' => false, 'description' => 'Use the domains even when another resource uses them.'],
                    'is_spa' => ['type' => 'boolean', 'default' => false, 'description' => 'Serve index.html for unknown paths (single-page application).'],
                    'upload_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'A completed upload from POST /applications/archive/uploads.'],
                    'instant_deploy' => ['type' => 'boolean', 'description' => 'Deploy the uploaded archive. The default is true when upload_id is set.'],
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Application created.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'uuid' => ['type' => 'string'],
                        'domains' => ['type' => 'string', 'nullable' => true],
                        'deployment_uuid' => ['type' => 'string', 'description' => 'Set when a deployment was queued.'],
                        'message' => ['type' => 'string', 'description' => 'Set when the deployment could not be queued.'],
                    ],
                ),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token needs the deploy ability to deploy the archive.'),
            new OA\Response(response: 404, description: 'Project, environment, server, or upload not found.'),
            new OA\Response(response: 409, description: 'Domain conflicts detected.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function create(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ($response = validateIncomingRequest($request)) {
            return $response;
        }
        $allowedFields = ['project_uuid', 'server_uuid', 'environment_name', 'environment_uuid', 'destination_uuid', 'name', 'description', 'domains', 'autogenerate_domain', 'force_domain_override', 'is_spa', 'upload_id', 'instant_deploy'];
        $validator = customApiValidator($request->all(), [
            'project_uuid' => 'string|required',
            'server_uuid' => 'string|required',
            'environment_name' => 'string|nullable',
            'environment_uuid' => 'string|nullable',
            'destination_uuid' => 'string|nullable',
            'name' => ValidationPatterns::nameRules(required: false, nullable: false),
            'description' => ValidationPatterns::descriptionRules(),
            'domains' => 'string|nullable',
            'autogenerate_domain' => 'boolean',
            'force_domain_override' => 'boolean',
            'is_spa' => 'boolean',
            'upload_id' => 'uuid|nullable',
            'instant_deploy' => 'boolean',
        ], ValidationPatterns::combinedMessages());
        if ($response = $this->validationResponse($validator, $request, $allowedFields)) {
            return $response;
        }
        if (blank($request->environment_uuid) && blank($request->environment_name)) {
            return response()->json(['message' => 'You need to provide at least one of environment_name or environment_uuid.'], 422);
        }
        $uploadId = $request->input('upload_id');
        $deploy = filled($uploadId) && $request->boolean('instant_deploy', true);
        if ($deploy && ($response = $this->deployAbilityForbiddenResponse($request, 'Deploying the archive needs a token with the deploy permission. Set instant_deploy to false to only create the application.'))) {
            return $response;
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->project_uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $environment = $project->environments()->where('name', $request->environment_name)->first()
            ?? $project->environments()->where('uuid', $request->environment_uuid)->first();
        if (! $environment) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }
        $this->authorize('create', [Application::class, $environment]);
        $server = Server::whereTeamId($teamId)->whereUuid($request->server_uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        $this->authorize('placeResource', $server);
        if (! $server->canHostResources()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['server_uuid' => ['The specified server is configured as a build server and cannot host resources.']],
            ], 422);
        }
        $destinations = $server->destinations();
        if ($destinations->count() === 0) {
            return response()->json(['message' => 'Server has no destinations.'], 400);
        }
        if ($destinations->count() > 1 && blank($request->destination_uuid)) {
            return response()->json(['message' => 'Server has multiple destinations and you do not set destination_uuid.'], 400);
        }
        $destination = filled($request->destination_uuid) ? $destinations->firstWhere('uuid', $request->destination_uuid) : $destinations->first();
        if (! $destination) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['destination_uuid' => 'Provided destination_uuid does not belong to the specified server.'],
            ], 422);
        }

        $fqdn = null;
        if (filled($request->domains)) {
            $fqdn = ValidationPatterns::normalizeApplicationDomains($request->domains);
            if ($response = $this->domainValidationResponse($fqdn, $teamId, $request->boolean('force_domain_override'))) {
                return $response;
            }
        }
        if (filled($uploadId) && ! StaticSiteArchive::hasStagedUpload($teamId, $uploadId)) {
            return response()->json(['message' => 'Upload not found. Upload the archive again.'], 404);
        }

        $application = StaticSiteArchive::createApplication(
            environment: $environment,
            destination: $destination,
            name: $request->input('name'),
            description: $request->input('description'),
            fqdn: $fqdn,
            autogenerateDomain: $request->boolean('autogenerate_domain', true),
            isSpa: $request->boolean('is_spa'),
        );
        app(ResourceDnsRecords::class)->queueAutomaticRecords($application, []);
        $application->isConfigurationChanged(true);

        $result = null;
        if (filled($uploadId)) {
            try {
                $result = StaticSiteArchive::publish($application, $teamId, $uploadId, deploy: $deploy, isApi: true);
            } catch (ValidationException $exception) {
                return response()->json(['message' => $exception->getMessage(), 'uuid' => $application->uuid], 422);
            }
        }

        auditLog('api.application.created', [
            'team_id' => $teamId,
            'application_uuid' => $application->uuid,
            'application_name' => $application->name,
            'application_type' => 'archive',
            'build_pack' => $application->build_pack,
            'instant_deploy' => $deploy,
        ]);

        $response = ['uuid' => $application->uuid, 'domains' => $application->fqdn];
        if (filled($result['deployment_uuid'] ?? null)) {
            $response['deployment_uuid'] = $result['deployment_uuid'];
        } elseif ($deploy) {
            $response['message'] = $result['message'] ?? 'The deployment could not be queued.';
        }

        return response()->json(serializeApiResponse($response), 201);
    }

    #[OA\Post(
        path: '/applications/{uuid}/archive',
        operationId: 'deploy-static-site-archive',
        summary: 'Deploy static site archive',
        description: 'Make an uploaded archive the current version of a static site that is deployed from archives, and deploy it. Needs a token with the write and deploy abilities.',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the application.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['upload_id'],
                properties: [
                    'upload_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'A completed upload from POST /applications/archive/uploads.'],
                    'force_rebuild' => ['type' => 'boolean', 'default' => false, 'description' => 'Build the image even when an image of this version exists.'],
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Deployment queued.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'deployment_uuid' => ['type' => 'string'],
                        'sha' => ['type' => 'string', 'description' => 'The site version.'],
                        'domains' => ['type' => 'string', 'nullable' => true],
                    ],
                ),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token needs the deploy ability.'),
            new OA\Response(response: 404, description: 'Application or upload not found.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(response: 429, description: 'The archive is stored, but the deployment queue is full.'),
        ],
    )]
    public function deploy(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ($response = validateIncomingRequest($request)) {
            return $response;
        }
        $validator = customApiValidator($request->all(), [
            'upload_id' => 'uuid|required',
            'force_rebuild' => 'boolean',
        ]);
        if ($response = $this->validationResponse($validator, $request, ['upload_id', 'force_rebuild'])) {
            return $response;
        }
        if ($response = $this->deployAbilityForbiddenResponse($request, 'Uploading a site archive deploys it.')) {
            return $response;
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        $this->authorize('update', $application);
        $this->authorize('deploy', $application);
        if (! $application->is_archive_source) {
            return response()->json(['message' => 'The application is not deployed from an uploaded archive.'], 422);
        }
        if (! StaticSiteArchive::hasStagedUpload($teamId, $request->input('upload_id'))) {
            return response()->json(['message' => 'Upload not found. Upload the archive again.'], 404);
        }

        try {
            $result = StaticSiteArchive::publish($application, $teamId, $request->input('upload_id'), deploy: true, forceRebuild: $request->boolean('force_rebuild'), isApi: true);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if (blank($result['deployment_uuid'])) {
            return response()->json(['message' => $result['message'] ?? 'The deployment could not be queued.', 'sha' => $result['sha']], 429);
        }

        auditLog('api.application.deployed', [
            'team_id' => $teamId,
            'application_uuid' => $application->uuid,
            'application_name' => $application->name,
            'deployment_uuid' => $result['deployment_uuid'],
            'force_rebuild' => $request->boolean('force_rebuild'),
            'site_version' => $result['sha'],
        ]);

        return response()->json([
            'deployment_uuid' => $result['deployment_uuid'],
            'sha' => $result['sha'],
            'domains' => $application->fqdn,
        ], 201);
    }

    /**
     * @param  list<string>  $allowedFields
     */
    private function validationResponse(Validator $validator, Request $request, array $allowedFields): ?JsonResponse
    {
        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if (! $validator->fails() && $extraFields === []) {
            return null;
        }
        $errors = $validator->errors();
        foreach ($extraFields as $field) {
            $errors->add($field, 'This field is not allowed.');
        }

        return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
    }

    private function domainValidationResponse(string $fqdn, int $teamId, bool $forceDomainOverride): ?JsonResponse
    {
        $errors = ValidationPatterns::validateApplicationDomains($fqdn);
        if (count($errors) > 0) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }
        $result = checkIfDomainIsAlreadyUsedViaAPI(collect(ValidationPatterns::applicationDomainList($fqdn)), (string) $teamId);
        if (isset($result['error'])) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['domains' => $result['error']]], 422);
        }
        if ($result['hasConflicts'] && ! $forceDomainOverride) {
            return response()->json([
                'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                'conflicts' => $result['conflicts'],
                'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
            ], 409);
        }

        return null;
    }
}
