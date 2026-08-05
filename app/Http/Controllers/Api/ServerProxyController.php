<?php

namespace App\Http\Controllers\Api;

use App\Actions\Proxy\DeleteProxyDynamicConfiguration;
use App\Actions\Proxy\ListProxyDynamicConfigurations;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Proxy\SaveProxyDynamicConfiguration;
use App\Enums\ProxyTypes;
use App\Http\Controllers\Controller;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Rules\SafeExternalUrl;
use App\Rules\ValidProxyConfigFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ServerProxyController extends Controller
{
    private const MAX_DYNAMIC_CONFIGURATION_SIZE = 1_048_576;

    private function teamIdOrAbort(): int|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        return $teamId;
    }

    private function findServerForTeam(int $teamId, string $uuid): ?Server
    {
        return Server::whereTeamId($teamId)->whereUuid($uuid)->first();
    }

    private function canReadSensitive(): bool
    {
        return request()->attributes->get('can_read_sensitive', false) === true;
    }

    /**
     * @return array{
     *     proxy_type: string|null,
     *     status: string|null,
     *     redirect_enabled: bool,
     *     redirect_url: string|null,
     *     generate_exact_labels: bool,
     *     configuration?: string|null
     * }
     */
    private function payload(Server $server, bool $includeConfiguration = true): array
    {
        $payload = [
            'proxy_type' => $server->proxyType(),
            'status' => data_get($server->proxy, 'status'),
            'redirect_enabled' => (bool) data_get($server->proxy, 'redirect_enabled', true),
            'redirect_url' => data_get($server->proxy, 'redirect_url'),
            'generate_exact_labels' => (bool) ($server->settings->generate_exact_labels ?? false),
        ];

        // Proxy compose can contain secrets; only expose with read:sensitive (and admin) like other APIs.
        if ($includeConfiguration && $this->canReadSensitive()) {
            // Prefer DB-stored config only — never SSH or regenerate for GET.
            $configuration = $server->proxy->get('last_saved_proxy_configuration');
            $payload['configuration'] = filled($configuration) ? $configuration : null;
        }

        return $payload;
    }

    private function normalizeDynamicFilename(Server $server, string $filename): string
    {
        if ($server->proxyType() === ProxyTypes::TRAEFIK->value && ! str($filename)->endsWith(['.yaml', '.yml'])) {
            return "{$filename}.yaml";
        }

        if ($server->proxyType() === ProxyTypes::CADDY->value && ! str($filename)->endsWith('.caddy')) {
            return "{$filename}.caddy";
        }

        return $filename;
    }

    private function validateDynamicFilename(string $filename): ?JsonResponse
    {
        $validator = customApiValidator(
            ['filename' => $filename],
            ['filename' => ['required', 'string', new ValidProxyConfigFilename]],
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            validateFilenameSafe($filename, 'proxy configuration filename');
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['filename' => ['The filename is not safe.']],
            ], 422);
        }

        return null;
    }

    private function validateDynamicConfigurationRequest(Request $request, mixed $filename, array $allowedFields): ?JsonResponse
    {
        if (! is_string($filename)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['filename' => ['The filename must be a string.']],
            ], 422);
        }

        $validator = customApiValidator([
            'filename' => $filename,
            'content' => $request->input('content'),
        ], [
            'filename' => ['required', 'string', new ValidProxyConfigFilename],
            'content' => ['required', 'string', 'max:'.self::MAX_DYNAMIC_CONFIGURATION_SIZE],
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        $content = $request->input('content');
        $validationFailed = $validator->fails();
        $contentTooLarge = is_string($content) && strlen($content) > self::MAX_DYNAMIC_CONFIGURATION_SIZE;
        if ($contentTooLarge) {
            $validator->errors()->add('content', 'The content must not exceed 1 MiB.');
        }

        if ($validationFailed || $contentTooLarge || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        try {
            validateFilenameSafe($filename, 'proxy configuration filename');
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['filename' => ['The filename is not safe.']],
            ], 422);
        }

        return null;
    }

    private function normalizeDynamicContent(Server $server, string $content): string|JsonResponse
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return $content;
        }

        try {
            return Yaml::dump(Yaml::parse($content), 10, 2);
        } catch (ParseException) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['content' => ['The content must be valid YAML.']],
            ], 422);
        }
    }

    #[OA\Get(
        summary: 'Get server proxy',
        description: 'Get proxy settings for a server owned by the authenticated team. The raw proxy configuration is only returned when the token has `read:sensitive` (or `root`) and the user is a team admin/owner, and only when already stored in the database (no remote fetch).',
        path: '/servers/{uuid}/proxy',
        operationId: 'get-server-proxy',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Server proxy settings.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'proxy_type', type: 'string', nullable: true, example: 'TRAEFIK'),
                        new OA\Property(property: 'status', type: 'string', nullable: true, example: 'running'),
                        new OA\Property(property: 'redirect_enabled', type: 'boolean', example: true),
                        new OA\Property(property: 'redirect_url', type: 'string', nullable: true, example: 'https://example.com'),
                        new OA\Property(property: 'generate_exact_labels', type: 'boolean', example: false),
                        new OA\Property(property: 'configuration', type: 'string', nullable: true, description: 'Docker Compose proxy configuration when stored in the database. Only present with read:sensitive.'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function show(Request $request, string $uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('view', $server);

        return response()->json($this->payload($server));
    }

    #[OA\Patch(
        summary: 'Update server proxy',
        description: 'Update proxy redirect settings, exact labels generation, and optionally the proxy type for a team-owned server.',
        path: '/servers/{uuid}/proxy',
        operationId: 'update-server-proxy',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'redirect_enabled', type: 'boolean'),
                    new OA\Property(property: 'redirect_url', type: 'string', nullable: true, description: 'Public http(s) redirect URL, or null to clear.'),
                    new OA\Property(property: 'generate_exact_labels', type: 'boolean'),
                    new OA\Property(property: 'proxy_type', type: 'string', enum: ['traefik', 'caddy', 'nginx', 'none'], description: 'Proxy type (case-insensitive).'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Proxy settings updated.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'proxy_type', type: 'string', nullable: true),
                        new OA\Property(property: 'status', type: 'string', nullable: true),
                        new OA\Property(property: 'redirect_enabled', type: 'boolean'),
                        new OA\Property(property: 'redirect_url', type: 'string', nullable: true),
                        new OA\Property(property: 'generate_exact_labels', type: 'boolean'),
                        new OA\Property(property: 'configuration', type: 'string', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function update(Request $request, string $uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $allowedFields = ['redirect_enabled', 'redirect_url', 'generate_exact_labels', 'proxy_type'];
        $validator = customApiValidator($request->all(), [
            'redirect_enabled' => 'boolean',
            'redirect_url' => ['nullable', 'string', new SafeExternalUrl],
            'generate_exact_labels' => 'boolean',
            'proxy_type' => 'string|nullable',
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        if ($request->has('proxy_type') && filled($request->proxy_type)) {
            $validProxyTypes = collect(ProxyTypes::cases())->map(fn (ProxyTypes $type) => str($type->value)->lower());
            if (! $validProxyTypes->contains(str($request->proxy_type)->lower())) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['proxy_type' => ['Invalid proxy type.']],
                ], 422);
            }
        }

        $changedFields = array_values(array_intersect($allowedFields, array_keys($request->all())));
        $redirectChanged = false;

        if ($request->has('redirect_enabled')) {
            $server->proxy->redirect_enabled = $request->boolean('redirect_enabled');
            $redirectChanged = true;
        }

        if ($request->exists('redirect_url')) {
            $server->proxy->redirect_url = $request->input('redirect_url') ?: null;
            $redirectChanged = true;
        }

        if ($redirectChanged) {
            $server->save();
        }

        if ($request->has('generate_exact_labels')) {
            $server->settings->generate_exact_labels = $request->boolean('generate_exact_labels');
            $server->settings->save();
        }

        if ($request->has('proxy_type') && filled($request->proxy_type)) {
            $server->changeProxy($request->proxy_type, async: true);
            $server->refresh();
        }

        // Apply redirect file on the server only when reachable (DB settings always saved above).
        if ($redirectChanged && $server->isFunctional()) {
            $server->setupDefaultRedirect();
        }

        auditLog('api.server.proxy.updated', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'changed_fields' => $changedFields,
        ]);

        return response()->json($this->payload($server->fresh()));
    }

    #[OA\Put(
        summary: 'Save server proxy configuration',
        description: 'Save the raw proxy Docker Compose configuration for a team-owned server. Multi-line configuration must be base64 encoded (same pattern as other compose payloads).',
        path: '/servers/{uuid}/proxy/configuration',
        operationId: 'save-server-proxy-configuration',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['configuration'],
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'configuration',
                        type: 'string',
                        description: 'Proxy docker-compose YAML. Prefer base64 encoding for multi-line content.'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Proxy configuration saved.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Proxy configuration saved.'),
                        new OA\Property(property: 'proxy_type', type: 'string', nullable: true),
                        new OA\Property(property: 'status', type: 'string', nullable: true),
                        new OA\Property(property: 'redirect_enabled', type: 'boolean'),
                        new OA\Property(property: 'redirect_url', type: 'string', nullable: true),
                        new OA\Property(property: 'generate_exact_labels', type: 'boolean'),
                        new OA\Property(property: 'configuration', type: 'string', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function saveConfiguration(Request $request, string $uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $allowedFields = ['configuration'];
        $validator = customApiValidator($request->all(), [
            'configuration' => 'required|string',
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $configuration = $request->input('configuration');
        if (isBase64Encoded($configuration)) {
            $decoded = base64_decode($configuration, true);
            if ($decoded === false || mb_detect_encoding($decoded, 'UTF-8', true) === false) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'configuration' => ['The configuration should be valid base64-encoded UTF-8 text.'],
                    ],
                ], 422);
            }
            $configuration = $decoded;
        }

        if (! filled(trim($configuration))) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'configuration' => ['The configuration field is required.'],
                ],
            ], 422);
        }

        SaveProxyConfiguration::run($server, $configuration);

        auditLog('api.server.proxy.configuration_saved', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
        ]);

        $payload = $this->payload($server->fresh());
        $payload['message'] = 'Proxy configuration saved.';

        return response()->json($payload);
    }

    #[OA\Post(
        summary: 'Restart server proxy',
        description: 'Queue a proxy restart for a team-owned server.',
        path: '/servers/{uuid}/proxy/restart',
        operationId: 'restart-server-proxy',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Proxy restart queued.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Proxy restart queued.'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function restart(Request $request, string $uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('manageProxy', $server);

        RestartProxyJob::dispatch($server);

        auditLog('api.server.proxy.restarted', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
        ]);

        return response()->json(['message' => 'Proxy restart queued.']);
    }

    #[OA\Get(
        summary: 'List proxy dynamic configurations',
        description: 'List safe dynamic configuration files for a team-owned server. File contents are only included when the token has `read:sensitive` (or `root`) and the user is a team admin or owner.',
        path: '/servers/{uuid}/proxy/dynamic-configurations',
        operationId: 'list-server-proxy-dynamic-configurations',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dynamic configuration files.',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        required: ['filename'],
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'filename', type: 'string', example: 'pos.yaml'),
                            new OA\Property(property: 'content', type: 'string', description: 'Only present with read:sensitive.', example: "http:\n  routers: {}\n"),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function dynamicConfigurations(string $uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('view', $server);

        return response()->json(ListProxyDynamicConfigurations::run($server, $this->canReadSensitive()));
    }

    #[OA\Post(
        summary: 'Create proxy dynamic configuration',
        description: 'Create a dynamic configuration file. Traefik content must be valid YAML. Proxy-specific extensions are appended when omitted, and Caddy is reloaded after a successful write.',
        path: '/servers/{uuid}/proxy/dynamic-configurations',
        operationId: 'create-server-proxy-dynamic-configuration',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['filename', 'content'],
                type: 'object',
                properties: [
                    new OA\Property(property: 'filename', type: 'string', maxLength: 255, example: 'pos.yaml'),
                    new OA\Property(property: 'content', type: 'string', maxLength: self::MAX_DYNAMIC_CONFIGURATION_SIZE, example: "http:\n  routers: {}\n"),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Dynamic configuration created.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Dynamic configuration already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function createDynamicConfiguration(Request $request, string $uuid): JsonResponse
    {
        return $this->saveDynamicConfiguration($request, $uuid, $request->input('filename'), true);
    }

    #[OA\Patch(
        summary: 'Update proxy dynamic configuration',
        description: 'Replace an existing dynamic configuration atomically. Traefik content must be valid YAML, and Caddy is reloaded after a successful write.',
        path: '/servers/{uuid}/proxy/dynamic-configurations/{filename}',
        operationId: 'update-server-proxy-dynamic-configuration',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filename', in: 'path', required: true, description: 'Configuration filename', schema: new OA\Schema(type: 'string', maxLength: 255)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['content'],
                type: 'object',
                properties: [
                    new OA\Property(property: 'content', type: 'string', maxLength: self::MAX_DYNAMIC_CONFIGURATION_SIZE, example: "http:\n  routers: {}\n"),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Dynamic configuration updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function updateDynamicConfiguration(Request $request, string $uuid, string $filename): JsonResponse
    {
        return $this->saveDynamicConfiguration($request, $uuid, $filename, false);
    }

    private function saveDynamicConfiguration(Request $request, string $uuid, mixed $filename, bool $create): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $allowedFields = $create ? ['filename', 'content'] : ['content'];
        $validationResponse = $this->validateDynamicConfigurationRequest($request, $filename, $allowedFields);
        if ($validationResponse) {
            return $validationResponse;
        }

        if (! is_string($filename)) {
            return response()->json(['message' => 'Validation failed.'], 422);
        }

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $filename = $this->normalizeDynamicFilename($server, $filename);
        $validationResponse = $this->validateDynamicFilename($filename);
        if ($validationResponse) {
            return $validationResponse;
        }

        $requestedContent = $request->input('content');
        if (! is_string($requestedContent)) {
            return response()->json(['message' => 'Validation failed.'], 422);
        }

        $content = $this->normalizeDynamicContent($server, $requestedContent);
        if ($content instanceof JsonResponse) {
            return $content;
        }

        $saved = SaveProxyDynamicConfiguration::run($server, $filename, $content, $create);
        if (! $saved) {
            return response()->json([
                'message' => $create
                    ? 'Dynamic configuration already exists. Use PATCH to update it.'
                    : 'Dynamic configuration not found.',
            ], $create ? 409 : 404);
        }

        auditLog($create ? 'api.server.proxy.dynamic_configuration_created' : 'api.server.proxy.dynamic_configuration_updated', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'filename' => $filename,
        ]);

        return response()->json([
            'message' => $create ? 'Dynamic configuration created.' : 'Dynamic configuration updated.',
            'filename' => $filename,
        ], $create ? 201 : 200);
    }

    #[OA\Delete(
        summary: 'Delete proxy dynamic configuration',
        description: 'Delete a dynamic configuration file. Coolify-managed filenames are rejected, and Caddy is reloaded after a successful deletion.',
        path: '/servers/{uuid}/proxy/dynamic-configurations/{filename}',
        operationId: 'delete-server-proxy-dynamic-configuration',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filename', in: 'path', required: true, description: 'Configuration filename', schema: new OA\Schema(type: 'string', maxLength: 255)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dynamic configuration deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function deleteDynamicConfiguration(string $uuid, string $filename): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $validationResponse = $this->validateDynamicFilename($filename);
        if ($validationResponse) {
            return $validationResponse;
        }

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $filename = $this->normalizeDynamicFilename($server, $filename);
        $validationResponse = $this->validateDynamicFilename($filename);
        if ($validationResponse) {
            return $validationResponse;
        }

        if (! DeleteProxyDynamicConfiguration::run($server, $filename)) {
            return response()->json(['message' => 'Dynamic configuration not found.'], 404);
        }

        auditLog('api.server.proxy.dynamic_configuration_deleted', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'filename' => $filename,
        ]);

        return response()->json(['message' => 'Dynamic configuration deleted.']);
    }
}
