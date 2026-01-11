<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProxyTypes;
use App\Exceptions\RateLimitException;
use App\Http\Controllers\Controller;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidCloudInitYaml;
use App\Rules\ValidHostname;
use App\Services\HetznerService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class HetznerController extends Controller
{
    /**
     * Get cloud provider token UUID from request.
     * Prefers cloud_provider_token_uuid over deprecated cloud_provider_token_id.
     */
    private function getCloudProviderTokenUuid(Request $request): ?string
    {
        return $request->cloud_provider_token_uuid ?? $request->cloud_provider_token_id;
    }

    #[OA\Get(
        summary: 'Get Hetzner Locations',
        description: 'Get all available Hetzner datacenter locations.',
        path: '/hetzner/locations',
        operationId: 'get-hetzner-locations',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        parameters: [
            new OA\Parameter(
                name: 'cloud_provider_token_uuid',
                in: 'query',
                required: false,
                description: 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'cloud_provider_token_id',
                in: 'query',
                required: false,
                deprecated: true,
                description: 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of Hetzner locations.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    'id' => ['type' => 'integer'],
                                    'name' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'country' => ['type' => 'string'],
                                    'city' => ['type' => 'string'],
                                    'latitude' => ['type' => 'number'],
                                    'longitude' => ['type' => 'number'],
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
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function locations(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        try {
            $hetznerService = new HetznerService($token->token);
            $locations = $hetznerService->getLocations();

            return response()->json($locations);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch locations: '.$e->getMessage()], 500);
        }
    }

    #[OA\Get(
        summary: 'Get Hetzner Server Types',
        description: 'Get all available Hetzner server types (instance sizes).',
        path: '/hetzner/server-types',
        operationId: 'get-hetzner-server-types',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        parameters: [
            new OA\Parameter(
                name: 'cloud_provider_token_uuid',
                in: 'query',
                required: false,
                description: 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'cloud_provider_token_id',
                in: 'query',
                required: false,
                deprecated: true,
                description: 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of Hetzner server types.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    'id' => ['type' => 'integer'],
                                    'name' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'cores' => ['type' => 'integer'],
                                    'memory' => ['type' => 'number'],
                                    'disk' => ['type' => 'integer'],
                                    'prices' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'location' => ['type' => 'string', 'description' => 'Datacenter location name'],
                                                'price_hourly' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'net' => ['type' => 'string'],
                                                        'gross' => ['type' => 'string'],
                                                    ],
                                                ],
                                                'price_monthly' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'net' => ['type' => 'string'],
                                                        'gross' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
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
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function serverTypes(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        try {
            $hetznerService = new HetznerService($token->token);
            $serverTypes = $hetznerService->getServerTypes();

            return response()->json($serverTypes);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch server types: '.$e->getMessage()], 500);
        }
    }

    #[OA\Get(
        summary: 'Get Hetzner Images',
        description: 'Get all available Hetzner system images (operating systems).',
        path: '/hetzner/images',
        operationId: 'get-hetzner-images',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        parameters: [
            new OA\Parameter(
                name: 'cloud_provider_token_uuid',
                in: 'query',
                required: false,
                description: 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'cloud_provider_token_id',
                in: 'query',
                required: false,
                deprecated: true,
                description: 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of Hetzner images.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    'id' => ['type' => 'integer'],
                                    'name' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'type' => ['type' => 'string'],
                                    'os_flavor' => ['type' => 'string'],
                                    'os_version' => ['type' => 'string'],
                                    'architecture' => ['type' => 'string'],
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
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function images(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        try {
            $hetznerService = new HetznerService($token->token);
            $images = $hetznerService->getImages();

            // Filter out deprecated images (same as UI)
            $filtered = array_filter($images, function ($image) {
                if (isset($image['type']) && $image['type'] !== 'system') {
                    return false;
                }

                if (isset($image['deprecated']) && $image['deprecated'] === true) {
                    return false;
                }

                return true;
            });

            return response()->json(array_values($filtered));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch images: '.$e->getMessage()], 500);
        }
    }

    #[OA\Get(
        summary: 'Get Hetzner SSH Keys',
        description: 'Get all SSH keys stored in the Hetzner account.',
        path: '/hetzner/ssh-keys',
        operationId: 'get-hetzner-ssh-keys',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        parameters: [
            new OA\Parameter(
                name: 'cloud_provider_token_uuid',
                in: 'query',
                required: false,
                description: 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'cloud_provider_token_id',
                in: 'query',
                required: false,
                deprecated: true,
                description: 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of Hetzner SSH keys.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    'id' => ['type' => 'integer'],
                                    'name' => ['type' => 'string'],
                                    'fingerprint' => ['type' => 'string'],
                                    'public_key' => ['type' => 'string'],
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
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function sshKeys(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        try {
            $hetznerService = new HetznerService($token->token);
            $sshKeys = $hetznerService->getSshKeys();

            return response()->json($sshKeys);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch SSH keys: '.$e->getMessage()], 500);
        }
    }

    #[OA\Post(
        summary: 'Create Hetzner Server',
        description: 'Create a new server on Hetzner and register it in Coolify.',
        path: '/servers/hetzner',
        operationId: 'create-hetzner-server',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Hetzner'],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Hetzner server creation parameters',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['location', 'server_type', 'image', 'private_key_uuid'],
                    properties: [
                        'cloud_provider_token_uuid' => ['type' => 'string', 'example' => 'abc123', 'description' => 'Cloud provider token UUID. Required if cloud_provider_token_id is not provided.'],
                        'cloud_provider_token_id' => ['type' => 'string', 'example' => 'abc123', 'description' => 'Deprecated: Use cloud_provider_token_uuid instead. Cloud provider token UUID.', 'deprecated' => true],
                        'location' => ['type' => 'string', 'example' => 'nbg1', 'description' => 'Hetzner location name'],
                        'server_type' => ['type' => 'string', 'example' => 'cx11', 'description' => 'Hetzner server type name'],
                        'image' => ['type' => 'integer', 'example' => 15512617, 'description' => 'Hetzner image ID'],
                        'name' => ['type' => 'string', 'example' => 'my-server', 'description' => 'Server name (auto-generated if not provided)'],
                        'private_key_uuid' => ['type' => 'string', 'example' => 'xyz789', 'description' => 'Private key UUID'],
                        'enable_ipv4' => ['type' => 'boolean', 'example' => true, 'description' => 'Enable IPv4 (default: true)'],
                        'enable_ipv6' => ['type' => 'boolean', 'example' => true, 'description' => 'Enable IPv6 (default: true)'],
                        'hetzner_ssh_key_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Additional Hetzner SSH key IDs'],
                        'cloud_init_script' => ['type' => 'string', 'description' => 'Cloud-init YAML script (optional)'],
                        'instant_validate' => ['type' => 'boolean', 'example' => false, 'description' => 'Validate server immediately after creation'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Hetzner server created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'example' => 'og888os', 'description' => 'The UUID of the server.'],
                                'hetzner_server_id' => ['type' => 'integer', 'description' => 'The Hetzner server ID.'],
                                'ip' => ['type' => 'string', 'description' => 'The server IP address.'],
                            ]
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
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
            new OA\Response(
                response: 429,
                ref: '#/components/responses/429',
            ),
        ]
    )]
    public function createServer(Request $request)
    {
        $allowedFields = [
            'cloud_provider_token_uuid',
            'cloud_provider_token_id',
            'location',
            'server_type',
            'image',
            'name',
            'private_key_uuid',
            'enable_ipv4',
            'enable_ipv6',
            'hetzner_ssh_key_ids',
            'cloud_init_script',
            'instant_validate',
        ];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
            'location' => 'required|string',
            'server_type' => 'required|string',
            'image' => 'required|integer',
            'name' => ['nullable', 'string', 'max:253', new ValidHostname],
            'private_key_uuid' => 'required|string',
            'enable_ipv4' => 'nullable|boolean',
            'enable_ipv6' => 'nullable|boolean',
            'hetzner_ssh_key_ids' => 'nullable|array',
            'hetzner_ssh_key_ids.*' => 'integer',
            'cloud_init_script' => ['nullable', 'string', new ValidCloudInitYaml],
            'instant_validate' => 'nullable|boolean',
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
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

        // Check server limit
        if (Team::serverLimitReached()) {
            return response()->json(['message' => 'Server limit reached for your subscription.'], 400);
        }

        // Set defaults
        if (! $request->name) {
            $request->offsetSet('name', generate_random_name());
        }
        if (is_null($request->enable_ipv4)) {
            $request->offsetSet('enable_ipv4', true);
        }
        if (is_null($request->enable_ipv6)) {
            $request->offsetSet('enable_ipv6', true);
        }
        if (is_null($request->hetzner_ssh_key_ids)) {
            $request->offsetSet('hetzner_ssh_key_ids', []);
        }
        if (is_null($request->instant_validate)) {
            $request->offsetSet('instant_validate', false);
        }

        // Validate cloud provider token
        $tokenUuid = $this->getCloudProviderTokenUuid($request);
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hetzner')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hetzner cloud provider token not found.'], 404);
        }

        // Validate private key
        $privateKey = PrivateKey::whereTeamId($teamId)->whereUuid($request->private_key_uuid)->first();
        if (! $privateKey) {
            return response()->json(['message' => 'Private key not found.'], 404);
        }

        try {
            $hetznerService = new HetznerService($token->token);

            // Get public key and MD5 fingerprint
            $publicKey = $privateKey->getPublicKey();
            $md5Fingerprint = PrivateKey::generateMd5Fingerprint($privateKey->private_key);

            // Check if SSH key already exists on Hetzner
            $existingSshKeys = $hetznerService->getSshKeys();
            $existingKey = null;

            foreach ($existingSshKeys as $key) {
                if ($key['fingerprint'] === $md5Fingerprint) {
                    $existingKey = $key;
                    break;
                }
            }

            // Upload SSH key if it doesn't exist
            if ($existingKey) {
                $sshKeyId = $existingKey['id'];
            } else {
                $sshKeyName = $privateKey->name;
                $uploadedKey = $hetznerService->uploadSshKey($sshKeyName, $publicKey);
                $sshKeyId = $uploadedKey['id'];
            }

            // Normalize server name to lowercase for RFC 1123 compliance
            $normalizedServerName = strtolower(trim($request->name));

            // Prepare SSH keys array: Coolify key + user-selected Hetzner keys
            $sshKeys = array_merge(
                [$sshKeyId],
                $request->hetzner_ssh_key_ids
            );

            // Remove duplicates
            $sshKeys = array_unique($sshKeys);
            $sshKeys = array_values($sshKeys);

            // Prepare server creation parameters
            $params = [
                'name' => $normalizedServerName,
                'server_type' => $request->server_type,
                'image' => $request->image,
                'location' => $request->location,
                'start_after_create' => true,
                'ssh_keys' => $sshKeys,
                'public_net' => [
                    'enable_ipv4' => $request->enable_ipv4,
                    'enable_ipv6' => $request->enable_ipv6,
                ],
            ];

            // Add cloud-init script if provided
            if (! empty($request->cloud_init_script)) {
                $params['user_data'] = $request->cloud_init_script;
            }

            // Create server on Hetzner
            $hetznerServer = $hetznerService->createServer($params);

            // Determine IP address to use (prefer IPv4, fallback to IPv6)
            $ipAddress = null;
            if ($request->enable_ipv4 && isset($hetznerServer['public_net']['ipv4']['ip'])) {
                $ipAddress = $hetznerServer['public_net']['ipv4']['ip'];
            } elseif ($request->enable_ipv6 && isset($hetznerServer['public_net']['ipv6']['ip'])) {
                $ipAddress = $hetznerServer['public_net']['ipv6']['ip'];
            }

            if (! $ipAddress) {
                throw new \Exception('No public IP address available. Enable at least one of IPv4 or IPv6.');
            }

            // Create server in Coolify database
            $server = Server::create([
                'name' => $normalizedServerName,
                'ip' => $ipAddress,
                'user' => 'root',
                'port' => 22,
                'team_id' => $teamId,
                'private_key_id' => $privateKey->id,
                'cloud_provider_token_id' => $token->id,
                'hetzner_server_id' => $hetznerServer['id'],
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            // Validate server if requested
            if ($request->instant_validate) {
                \App\Actions\Server\ValidateServer::dispatch($server);
            }

            return response()->json([
                'uuid' => $server->uuid,
                'hetzner_server_id' => $hetznerServer['id'],
                'ip' => $ipAddress,
            ])->setStatusCode(201);
        } catch (RateLimitException $e) {
            $response = response()->json(['message' => $e->getMessage()], 429);
            if ($e->retryAfter !== null) {
                $response->header('Retry-After', $e->retryAfter);
            }

            return $response;
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to create server: '.$e->getMessage()], 500);
        }
    }
}
