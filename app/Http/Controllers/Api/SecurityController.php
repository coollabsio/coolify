<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrivateKey;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SecurityController extends Controller
{
    private function removeSensitiveData($team)
    {
        $token = auth()->user()->currentAccessToken();
        if ($token->can('view:sensitive')) {
            return serializeApiResponse($team);
        }
        $team->makeHidden([
            'private_key',
        ]);

        return serializeApiResponse($team);
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all private keys.',
        path: '/security/keys',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Private Keys'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all private keys.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/PrivateKey')
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
    public function keys(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $keys = PrivateKey::where('team_id', $teamId)->get();

        return response()->json($this->removeSensitiveData($keys));
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get key by UUID.',
        path: '/security/keys/{uuid}',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Private Keys'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Private Key Uuid', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all private keys.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/PrivateKey')
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
                description: 'Private Key not found.',
            ),
        ]
    )]
    public function key_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $key = PrivateKey::where('team_id', $teamId)->where('uuid', $request->uuid)->first();

        if (is_null($key)) {
            return response()->json([
                'message' => 'Private Key not found.',
            ], 404);
        }

        return response()->json($this->removeSensitiveData($key));
    }

    #[OA\Post(
        summary: 'Create',
        description: 'Create a new private key.',
        path: '/security/keys',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Private Keys'],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                'application/json' => new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['private_key'],
                        properties: [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'private_key' => ['type' => 'string'],
                        ],
                        additionalProperties: false,
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'The created private key\'s UUID.',
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
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function create_key(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|max:255',
            'private_key' => 'required|string',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        if (! $request->name) {
            $request->offsetSet('name', generate_random_name());
        }
        if (! $request->description) {
            $request->offsetSet('description', 'Created by Coolify via API');
        }
        $key = PrivateKey::create([
            'team_id' => $teamId,
            'name' => $request->name,
            'description' => $request->description,
            'private_key' => $request->private_key,
        ]);

        return response()->json(serializeApiResponse([
            'uuid' => $key->uuid,
        ]))->setStatusCode(201);
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update a private key.',
        path: '/security/keys',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Private Keys'],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                'application/json' => new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['private_key'],
                        properties: [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'private_key' => ['type' => 'string'],
                        ],
                        additionalProperties: false,
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'The updated private key\'s UUID.',
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
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function update_key(Request $request)
    {
        $allowedFields = ['name', 'description', 'private_key'];
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|max:255',
            'private_key' => 'required|string',
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
        $foundKey = PrivateKey::where('team_id', $teamId)->where('uuid', $request->uuid)->first();
        if (is_null($foundKey)) {
            return response()->json([
                'message' => 'Private Key not found.',
            ], 404);
        }
        $foundKey->update($request->all());

        return response()->json(serializeApiResponse([
            'uuid' => $foundKey->uuid,
        ]))->setStatusCode(201);
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete a private key.',
        path: '/security/keys/{uuid}',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Private Keys'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Private Key Uuid', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Private Key deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Private Key deleted.'],
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
                description: 'Private Key not found.',
            ),
        ]
    )]
    public function delete_key(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 422);
        }

        $key = PrivateKey::where('team_id', $teamId)->where('uuid', $request->uuid)->first();
        if (is_null($key)) {
            return response()->json(['message' => 'Private Key not found.'], 404);
        }
        $key->forceDelete();

        return response()->json([
            'message' => 'Private Key deleted.',
        ]);
    }
}
