<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProxyTypes;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Rules\ValidProxyConfigFilename;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ProxyController extends Controller
{
    /**
     * Files managed by Coolify itself that must never be modified or
     * deleted through this API (mirrors ValidProxyConfigFilename).
     */
    private const RESERVED_FILENAMES = [
        'coolify.yaml',
        'coolify.yml',
        'Caddyfile',
    ];

    private function findServer(Request $request, int $teamId): ?Server
    {
        return Server::whereTeamId($teamId)->whereUuid($request->uuid)->first();
    }

    /**
     * Normalize the filename the same way the dashboard does: append the
     * proxy-specific extension when missing.
     */
    private function normalizeFilename(string $fileName, string $proxyType): string
    {
        if ($proxyType === ProxyTypes::TRAEFIK->value) {
            if (! str($fileName)->endsWith('.yaml') && ! str($fileName)->endsWith('.yml')) {
                return "{$fileName}.yaml";
            }
        } elseif ($proxyType === 'CADDY') {
            if (! str($fileName)->endsWith('.caddy')) {
                return "{$fileName}.caddy";
            }
        }

        return $fileName;
    }

    private function fileExists(Server $server, string $path): bool
    {
        $escaped = escapeshellarg($path);

        return trim(instant_remote_process(["test -f {$escaped} && echo 1 || echo 0"], $server)) === '1';
    }

    #[OA\Get(
        summary: 'List Proxy Dynamic Configurations',
        description: 'List all dynamic configuration files of the proxy on the server.',
        path: '/servers/{uuid}/proxy/dynamic-configurations',
        operationId: 'list-proxy-dynamic-configurations-by-server-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get the dynamic configurations of the proxy.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'filename', type: 'string'),
                                    new OA\Property(property: 'content', type: 'string'),
                                ]
                            )
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function dynamic_configurations(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $server = $this->findServer($request, $teamId);
        if (is_null($server)) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $proxyPath = $server->proxyPath();
        $files = instant_remote_process(["mkdir -p {$proxyPath}/dynamic && ls -1 {$proxyPath}/dynamic"], $server);
        $configurations = collect(explode("\n", (string) $files))
            ->map(fn ($file) => trim($file))
            ->filter()
            ->map(function ($file) use ($proxyPath, $server) {
                $escaped = escapeshellarg("{$proxyPath}/dynamic/{$file}");

                return [
                    'filename' => $file,
                    'content' => instant_remote_process(["cat {$escaped}"], $server),
                ];
            })
            ->values();

        return response()->json(serializeApiResponse($configurations));
    }

    #[OA\Post(
        summary: 'Create Proxy Dynamic Configuration',
        description: 'Create a new dynamic configuration file for the proxy on the server. For Traefik the content must be valid YAML; for Caddy the proxy is reloaded automatically.',
        path: '/servers/{uuid}/proxy/dynamic-configurations',
        operationId: 'create-proxy-dynamic-configuration-by-server-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['filename', 'content'],
                    properties: [
                        new OA\Property(property: 'filename', type: 'string', description: 'The configuration filename. The proxy specific extension (.yaml / .caddy) is appended when missing.'),
                        new OA\Property(property: 'content', type: 'string', description: 'The configuration content.'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Dynamic configuration created.',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function create_dynamic_configuration(Request $request)
    {
        return $this->saveDynamicConfiguration($request, isNew: true);
    }

    #[OA\Patch(
        summary: 'Update Proxy Dynamic Configuration',
        description: 'Update an existing dynamic configuration file of the proxy on the server.',
        path: '/servers/{uuid}/proxy/dynamic-configurations/{filename}',
        operationId: 'update-proxy-dynamic-configuration-by-server-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filename', in: 'path', required: true, description: 'Configuration filename', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['content'],
                    properties: [
                        new OA\Property(property: 'content', type: 'string', description: 'The configuration content.'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dynamic configuration updated.',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function update_dynamic_configuration(Request $request)
    {
        return $this->saveDynamicConfiguration($request, isNew: false);
    }

    #[OA\Delete(
        summary: 'Delete Proxy Dynamic Configuration',
        description: 'Delete a dynamic configuration file of the proxy on the server. Files managed by Coolify (coolify.yaml, Caddyfile) cannot be deleted.',
        path: '/servers/{uuid}/proxy/dynamic-configurations/{filename}',
        operationId: 'delete-proxy-dynamic-configuration-by-server-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filename', in: 'path', required: true, description: 'Configuration filename', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dynamic configuration deleted.',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function delete_dynamic_configuration(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $server = $this->findServer($request, $teamId);
        if (is_null($server)) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $fileName = $request->route('filename');

        try {
            validateFilenameSafe($fileName, 'proxy configuration filename');
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (in_array($fileName, self::RESERVED_FILENAMES, true)) {
            return response()->json(['message' => 'This file is managed by Coolify and cannot be deleted.'], 400);
        }

        $proxyPath = $server->proxyPath();
        $fullPath = "{$proxyPath}/dynamic/{$fileName}";
        if (! $this->fileExists($server, $fullPath)) {
            return response()->json(['message' => 'Dynamic configuration not found.'], 404);
        }

        $escapedPath = escapeshellarg($fullPath);
        instant_remote_process(["rm -f {$escapedPath}"], $server);
        if ($server->proxyType() === 'CADDY') {
            $server->reloadCaddy();
        }

        return response()->json(['message' => 'Dynamic configuration deleted.']);
    }

    private function saveDynamicConfiguration(Request $request, bool $isNew)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $server = $this->findServer($request, $teamId);
        if (is_null($server)) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $fileName = $isNew ? $request->get('filename') : $request->route('filename');
        $validator = customApiValidator([
            'filename' => $fileName,
            'content' => $request->get('content'),
        ], [
            'filename' => ['required', new ValidProxyConfigFilename],
            'content' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            validateFilenameSafe($fileName, 'proxy configuration filename');
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $proxyType = $server->proxyType();
        $fileName = $this->normalizeFilename($fileName, $proxyType);
        if (in_array($fileName, self::RESERVED_FILENAMES, true)) {
            return response()->json(['message' => 'This file is managed by Coolify and cannot be modified.'], 400);
        }

        $content = $request->get('content');
        if ($proxyType === ProxyTypes::TRAEFIK->value) {
            try {
                $content = Yaml::dump(Yaml::parse($content), 10, 2);
            } catch (ParseException $e) {
                return response()->json(['message' => "Invalid YAML: {$e->getMessage()}"], 422);
            }
        }

        $proxyPath = $server->proxyPath();
        $fullPath = "{$proxyPath}/dynamic/{$fileName}";
        $exists = $this->fileExists($server, $fullPath);
        if ($isNew && $exists) {
            return response()->json(['message' => 'Dynamic configuration already exists. Use PATCH to update it.'], 409);
        }
        if (! $isNew && ! $exists) {
            return response()->json(['message' => 'Dynamic configuration not found.'], 404);
        }

        $escapedFile = escapeshellarg($fullPath);
        $base64Value = base64_encode($content);
        instant_remote_process([
            "mkdir -p {$proxyPath}/dynamic",
            "echo '{$base64Value}' | base64 -d | tee {$escapedFile} > /dev/null",
        ], $server);
        if ($proxyType === 'CADDY') {
            $server->reloadCaddy();
        }

        return response()->json([
            'message' => $isNew ? 'Dynamic configuration created.' : 'Dynamic configuration updated.',
            'filename' => $fileName,
        ], $isNew ? 201 : 200);
    }
}
