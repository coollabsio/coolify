<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CloudProviderToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class CloudProviderTokensController extends Controller
{
    private function removeSensitiveData($token)
    {
        $token->makeHidden([
            'id',
            'token',
        ]);

        return serializeApiResponse($token);
    }

    /**
     * Validate a provider token against the provider's API.
     *
     * @return array{valid: bool, error: string|null}
     */
    private function validateProviderToken(string $provider, string $token): array
    {
        try {
            $response = match ($provider) {
                'hetzner' => Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers'),
                'digitalocean' => Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                ])->timeout(10)->get('https://api.digitalocean.com/v2/account'),
                default => null,
            };

            if ($response === null) {
                return ['valid' => false, 'error' => 'Unsupported provider.'];
            }

            if ($response->successful()) {
                return ['valid' => true, 'error' => null];
            }

            return ['valid' => false, 'error' => "Invalid {$provider} token. Please check your API token."];
        } catch (\Throwable $e) {
            Log::error('Failed to validate cloud provider token', [
                'provider' => $provider,
                'exception' => $e->getMessage(),
            ]);

            return ['valid' => false, 'error' => 'Failed to validate token with provider API.'];
        }
    }

    #[OA\Get(
        summary: 'List Cloud Provider Tokens',
        description: 'List all cloud provider tokens for the authenticated team.',
        path: '/cloud-tokens',
        operationId: 'list-cloud-tokens',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cloud Tokens'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all cloud provider tokens.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    'uuid' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                    'provider' => ['type' => 'string', 'enum' => ['hetzner', 'digitalocean']],
                                    'team_id' => ['type' => 'integer'],
                                    'servers_count' => ['type' => 'integer'],
                                    'created_at' => ['type' => 'string'],
                                    'updated_at' => ['type' => 'string'],
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
        ]
    )]
    public function index(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $tokens = CloudProviderToken::whereTeamId($teamId)
            ->withCount('servers')
            ->get()
            ->map(function ($token) {
                return $this->removeSensitiveData($token);
            });

        return response()->json($tokens);
    }

    #[OA\Get(
        summary: 'Get Cloud Provider Token',
        description: 'Get cloud provider token by UUID.',
        path: '/cloud-tokens/{uuid}',
        operationId: 'get-cloud-token-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cloud Tokens'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Token UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get cloud provider token by UUID',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'provider' => ['type' => 'string'],
                                'team_id' => ['type' => 'integer'],
                                'servers_count' => ['type' => 'integer'],
                                'created_at' => ['type' => 'string'],
                                'updated_at' => ['type' => 'string'],
                            ]
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
    public function show(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($request->uuid)
            ->withCount('servers')
            ->first();

        if (is_null($token)) {
            return response()->json(['message' => 'Cloud provider token not found.'], 404);
        }

        return response()->json($this->removeSensitiveData($token));
    }

    #[OA\Post(
        summary: 'Create Cloud Provider Token',
        description: 'Create a new cloud provider token. The token will be validated before being stored.',
        path: '/cloud-tokens',
        operationId: 'create-cloud-token',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cloud Tokens'],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Cloud provider token details',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['provider', 'token', 'name'],
                    properties: [
                        'provider' => ['type' => 'string', 'enum' => ['hetzner', 'digitalocean'], 'example' => 'hetzner', 'description' => 'The cloud provider.'],
                        'token' => ['type' => 'string', 'example' => 'your-api-token-here', 'description' => 'The API token for the cloud provider.'],
                        'name' => ['type' => 'string', 'example' => 'My Hetzner Token', 'description' => 'A friendly name for the token.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Cloud provider token created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'example' => 'og888os', 'description' => 'The UUID of the token.'],
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
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function store(Request $request)
    {
        $allowedFields = ['provider', 'token', 'name'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        // Use request body only (excludes any route parameters)
        $body = $request->json()->all();

        $validator = customApiValidator($body, [
            'provider' => 'required|string|in:hetzner,digitalocean',
            'token' => 'required|string',
            'name' => 'required|string|max:255',
        ]);

        $extraFields = array_diff(array_keys($body), $allowedFields);
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

        // Validate token with the provider's API
        $validation = $this->validateProviderToken($body['provider'], $body['token']);

        if (! $validation['valid']) {
            return response()->json(['message' => $validation['error']], 400);
        }

        $cloudProviderToken = CloudProviderToken::create([
            'team_id' => $teamId,
            'provider' => $body['provider'],
            'token' => $body['token'],
            'name' => $body['name'],
        ]);

        return response()->json([
            'uuid' => $cloudProviderToken->uuid,
        ])->setStatusCode(201);
    }

    #[OA\Patch(
        summary: 'Update Cloud Provider Token',
        description: 'Update cloud provider token name.',
        path: '/cloud-tokens/{uuid}',
        operationId: 'update-cloud-token-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cloud Tokens'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Token UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Cloud provider token updated.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'The friendly name for the token.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cloud provider token updated.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string'],
                            ]
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
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update(Request $request)
    {
        $allowedFields = ['name'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        // Use request body only (excludes route parameters like uuid)
        $body = $request->json()->all();

        $validator = customApiValidator($body, [
            'name' => 'required|string|max:255',
        ]);

        $extraFields = array_diff(array_keys($body), $allowedFields);
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

        // Use route parameter for UUID lookup
        $token = CloudProviderToken::whereTeamId($teamId)->whereUuid($request->route('uuid'))->first();
        if (! $token) {
            return response()->json(['message' => 'Cloud provider token not found.'], 404);
        }

        $token->update(array_intersect_key($body, array_flip($allowedFields)));

        return response()->json([
            'uuid' => $token->uuid,
        ]);
    }

    #[OA\Delete(
        summary: 'Delete Cloud Provider Token',
        description: 'Delete cloud provider token by UUID. Cannot delete if token is used by any servers.',
        path: '/cloud-tokens/{uuid}',
        operationId: 'delete-cloud-token-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cloud Tokens'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the cloud provider token.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cloud provider token deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Cloud provider token deleted.'],
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
        ]
    )]
    public function destroy(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 422);
        }

        $token = CloudProviderToken::whereTeamId($teamId)->whereUuid($request->uuid)->first();

        if (! $token) {
            return response()->json(['message' => 'Cloud provider token not found.'], 404);
        }

        if ($token->hasServers()) {
            return response()->json(['message' => 'Cannot delete token that is used by servers.'], 400);
        }

        $token->delete();

        return response()->json(['message' => 'Cloud provider token deleted.']);
    }

    #[OA\Post(
        summary: 'Validate Cloud Provider Token',
        description: 'Validate a cloud provider token against the provider API.',
        path: '/cloud-tokens/{uuid}/validate',
        operationId: 'validate-cloud-token-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cloud Tokens'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Token UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token validation result.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'valid' => ['type' => 'boolean', 'example' => true],
                                'message' => ['type' => 'string', 'example' => 'Token is valid.'],
                            ]
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
    public function validateToken(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $cloudToken = CloudProviderToken::whereTeamId($teamId)->whereUuid($request->uuid)->first();

        if (! $cloudToken) {
            return response()->json(['message' => 'Cloud provider token not found.'], 404);
        }

        $validation = $this->validateProviderToken($cloudToken->provider, $cloudToken->token);

        return response()->json([
            'valid' => $validation['valid'],
            'message' => $validation['valid'] ? 'Token is valid.' : $validation['error'],
        ]);
    }
}
