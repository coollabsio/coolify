<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CloudProviderToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

        $validator = customApiValidator($request->all(), [
            'provider' => 'required|string|in:hetzner,digitalocean',
            'token' => 'required|string',
            'name' => 'required|string|max:255',
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

        // Validate token with the provider's API
        $isValid = false;
        $errorMessage = 'Invalid token.';

        try {
            if ($request->provider === 'hetzner') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$request->token,
                ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

                $isValid = $response->successful();
                if (! $isValid) {
                    $errorMessage = 'Invalid Hetzner token. Please check your API token.';
                }
            } elseif ($request->provider === 'digitalocean') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$request->token,
                ])->timeout(10)->get('https://api.digitalocean.com/v2/account');

                $isValid = $response->successful();
                if (! $isValid) {
                    $errorMessage = 'Invalid DigitalOcean token. Please check your API token.';
                }
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to validate token with provider API: '.$e->getMessage()], 400);
        }

        if (! $isValid) {
            return response()->json(['message' => $errorMessage], 400);
        }

        $cloudProviderToken = CloudProviderToken::create([
            'team_id' => $teamId,
            'provider' => $request->provider,
            'token' => $request->token,
            'name' => $request->name,
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

        $validator = customApiValidator($request->all(), [
            'name' => 'required|string|max:255',
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

        $token = CloudProviderToken::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $token) {
            return response()->json(['message' => 'Cloud provider token not found.'], 404);
        }

        $token->update($request->only(['name']));

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

        $isValid = false;
        $message = 'Token is invalid.';

        try {
            if ($cloudToken->provider === 'hetzner') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$cloudToken->token,
                ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

                $isValid = $response->successful();
                $message = $isValid ? 'Token is valid.' : 'Token is invalid.';
            } elseif ($cloudToken->provider === 'digitalocean') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$cloudToken->token,
                ])->timeout(10)->get('https://api.digitalocean.com/v2/account');

                $isValid = $response->successful();
                $message = $isValid ? 'Token is valid.' : 'Token is invalid.';
            }
        } catch (\Throwable $e) {
            return response()->json([
                'valid' => false,
                'message' => 'Failed to validate token: '.$e->getMessage(),
            ]);
        }

        return response()->json([
            'valid' => $isValid,
            'message' => $message,
        ]);
    }
}
