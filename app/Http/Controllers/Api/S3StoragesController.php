<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\S3Storage;
use App\Rules\SafeWebhookUrl;
use App\Rules\ValidS3BucketName;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class S3StoragesController extends Controller
{
    private function removeSensitiveData(S3Storage $storage)
    {
        $storage->makeHidden([
            'id',
        ]);

        if (request()->attributes->get('can_read_sensitive', false) === true) {
            $storage->makeVisible([
                'key',
                'secret',
            ]);
        }

        return serializeApiResponse($storage);
    }

    /**
     * @return array{valid: bool, error: string|null}
     */
    private function validateStorageConnection(S3Storage $storage): array
    {
        try {
            $storage->testConnection(shouldSave: true);

            return ['valid' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, string>  $allowedFields
     * @param  array<string, mixed>  $rules
     */
    private function validateBody(array $body, array $allowedFields, array $rules): ?JsonResponse
    {
        $validator = customApiValidator($body, $rules);

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

        return null;
    }

    #[OA\Get(
        summary: 'List S3 Storages',
        description: 'List all S3 storages for the authenticated team.',
        path: '/s3-storages',
        operationId: 'list-s3-storages',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['S3 Storages'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all S3 storages.',
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
                                    'description' => ['type' => 'string', 'nullable' => true],
                                    'endpoint' => ['type' => 'string'],
                                    'bucket' => ['type' => 'string'],
                                    'region' => ['type' => 'string'],
                                    'is_usable' => ['type' => 'boolean'],
                                    'team_id' => ['type' => 'integer'],
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

        $storages = S3Storage::ownedByCurrentTeamAPI($teamId)
            ->get()
            ->map(function ($storage) {
                return $this->removeSensitiveData($storage);
            });

        return response()->json($storages);
    }

    #[OA\Get(
        summary: 'Get S3 Storage',
        description: 'Get S3 storage by UUID.',
        path: '/s3-storages/{uuid}',
        operationId: 'get-s3-storage-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['S3 Storages'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'S3 Storage UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get S3 storage by UUID',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'description' => ['type' => 'string', 'nullable' => true],
                                'endpoint' => ['type' => 'string'],
                                'bucket' => ['type' => 'string'],
                                'region' => ['type' => 'string'],
                                'is_usable' => ['type' => 'boolean'],
                                'team_id' => ['type' => 'integer'],
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

        $storage = S3Storage::ownedByCurrentTeamAPI($teamId)
            ->whereUuid($request->uuid)
            ->first();

        if (is_null($storage)) {
            return response()->json(['message' => 'S3 storage not found.'], 404);
        }
        $this->authorize('view', $storage);

        return response()->json($this->removeSensitiveData($storage));
    }

    #[OA\Post(
        summary: 'Create S3 Storage',
        description: 'Create a new S3 storage configuration for the authenticated team.',
        path: '/s3-storages',
        operationId: 'create-s3-storage',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['S3 Storages'],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'S3 storage details',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['name', 'endpoint', 'bucket', 'region', 'key', 'secret'],
                    properties: [
                        'name' => ['type' => 'string', 'example' => 'My S3 Storage', 'description' => 'A friendly name for the storage.'],
                        'description' => ['type' => 'string', 'nullable' => true, 'description' => 'Optional description.'],
                        'endpoint' => ['type' => 'string', 'example' => 'https://s3.us-east-1.amazonaws.com', 'description' => 'S3-compatible endpoint URL.'],
                        'bucket' => ['type' => 'string', 'example' => 'my-bucket', 'description' => 'S3 bucket name.'],
                        'region' => ['type' => 'string', 'example' => 'us-east-1', 'description' => 'S3 region.'],
                        'key' => ['type' => 'string', 'description' => 'Access key.'],
                        'secret' => ['type' => 'string', 'description' => 'Secret key.'],
                        'is_usable' => ['type' => 'boolean', 'description' => 'Whether the storage is marked usable.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'S3 storage created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'example' => 'og888os', 'description' => 'The UUID of the S3 storage.'],
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
        $allowedFields = ['name', 'description', 'endpoint', 'bucket', 'region', 'key', 'secret', 'is_usable'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $this->authorize('create', [S3Storage::class]);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $body = $request->json()->all();

        $validationError = $this->validateBody($body, $allowedFields, [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'endpoint' => ['required', 'string', 'max:255', new SafeWebhookUrl],
            'bucket' => ['required', new ValidS3BucketName],
            'region' => 'required|string|max:255',
            'key' => 'required|string|max:255',
            'secret' => 'required|string|max:255',
            'is_usable' => 'sometimes|boolean',
        ]);
        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }

        $storage = S3Storage::create([
            'team_id' => $teamId,
            'name' => $body['name'],
            'description' => $body['description'] ?? null,
            'endpoint' => $body['endpoint'],
            'bucket' => $body['bucket'],
            'region' => $body['region'],
            'key' => $body['key'],
            'secret' => $body['secret'],
            'is_usable' => $body['is_usable'] ?? false,
        ]);

        auditLog('api.s3_storage.created', [
            'team_id' => $teamId,
            's3_storage_uuid' => $storage->uuid,
            's3_storage_name' => $storage->name,
        ]);

        return response()->json([
            'uuid' => $storage->uuid,
        ])->setStatusCode(201);
    }

    #[OA\Patch(
        summary: 'Update S3 Storage',
        description: 'Update S3 storage by UUID.',
        path: '/s3-storages/{uuid}',
        operationId: 'update-s3-storage-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['S3 Storages'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'S3 Storage UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'S3 storage fields to update.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'A friendly name for the storage.'],
                        'description' => ['type' => 'string', 'nullable' => true, 'description' => 'Optional description.'],
                        'endpoint' => ['type' => 'string', 'description' => 'S3-compatible endpoint URL.'],
                        'bucket' => ['type' => 'string', 'description' => 'S3 bucket name.'],
                        'region' => ['type' => 'string', 'description' => 'S3 region.'],
                        'key' => ['type' => 'string', 'description' => 'Access key.'],
                        'secret' => ['type' => 'string', 'description' => 'Secret key.'],
                        'is_usable' => ['type' => 'boolean', 'description' => 'Whether the storage is marked usable.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'S3 storage updated.',
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
        $allowedFields = ['name', 'description', 'endpoint', 'bucket', 'region', 'key', 'secret', 'is_usable'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $body = $request->json()->all();

        $validationError = $this->validateBody($body, $allowedFields, [
            'name' => ValidationPatterns::nameRules(required: false),
            'description' => ValidationPatterns::descriptionRules(),
            'endpoint' => ['sometimes', 'string', 'max:255', new SafeWebhookUrl],
            'bucket' => ['sometimes', new ValidS3BucketName],
            'region' => 'sometimes|string|max:255',
            'key' => 'sometimes|string|max:255',
            'secret' => 'sometimes|string|max:255',
            'is_usable' => 'sometimes|boolean',
        ]);
        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }

        $storage = S3Storage::ownedByCurrentTeamAPI($teamId)->whereUuid($request->route('uuid'))->first();
        if (! $storage) {
            return response()->json(['message' => 'S3 storage not found.'], 404);
        }
        $this->authorize('update', $storage);

        $storage->update(array_intersect_key($body, array_flip($allowedFields)));

        auditLog('api.s3_storage.updated', [
            'team_id' => $teamId,
            's3_storage_uuid' => $storage->uuid,
            's3_storage_name' => $storage->name,
            'changed_fields' => array_values(array_intersect($allowedFields, array_keys($body))),
        ]);

        return response()->json([
            'uuid' => $storage->uuid,
        ]);
    }

    #[OA\Delete(
        summary: 'Delete S3 Storage',
        description: 'Delete S3 storage by UUID.',
        path: '/s3-storages/{uuid}',
        operationId: 'delete-s3-storage-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['S3 Storages'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the S3 storage.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'S3 storage deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'S3 storage deleted.'],
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
    public function destroy(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 422);
        }

        $storage = S3Storage::ownedByCurrentTeamAPI($teamId)->whereUuid($request->uuid)->first();

        if (! $storage) {
            return response()->json(['message' => 'S3 storage not found.'], 404);
        }
        $this->authorize('delete', $storage);

        $storageUuid = $storage->uuid;
        $storageName = $storage->name;
        $storage->delete();

        auditLog('api.s3_storage.deleted', [
            'team_id' => $teamId,
            's3_storage_uuid' => $storageUuid,
            's3_storage_name' => $storageName,
        ]);

        return response()->json(['message' => 'S3 storage deleted.']);
    }

    #[OA\Post(
        summary: 'Validate S3 Storage',
        description: 'Validate an S3 storage connection using ListObjectsV2.',
        path: '/s3-storages/{uuid}/validate',
        operationId: 'validate-s3-storage-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['S3 Storages'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'S3 Storage UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'S3 storage validation result.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'valid' => ['type' => 'boolean', 'example' => true],
                                'message' => ['type' => 'string', 'example' => 'S3 storage connection is valid.'],
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
    public function validateStorage(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $storage = S3Storage::ownedByCurrentTeamAPI($teamId)->whereUuid($request->uuid)->first();

        if (! $storage) {
            return response()->json(['message' => 'S3 storage not found.'], 404);
        }
        $this->authorize('validateConnection', $storage);

        $validation = $this->validateStorageConnection($storage);

        auditLog('api.s3_storage.validated', [
            'team_id' => $teamId,
            's3_storage_uuid' => $storage->uuid,
            's3_storage_name' => $storage->name,
            'valid' => $validation['valid'],
        ]);

        return response()->json([
            'valid' => $validation['valid'],
            'message' => $validation['valid'] ? 'S3 storage connection is valid.' : $validation['error'],
        ]);
    }
}
