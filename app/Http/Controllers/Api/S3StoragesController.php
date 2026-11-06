<?php

namespace App\Http\Controllers\Api;

use App\Enums\BackupStorageType;
use App\Http\Controllers\Controller;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class S3StoragesController extends Controller
{
    private function removeSensitiveData(S3Storage $storage)
    {
        $storage->makeHidden([
            'id',
            'private_key_id',
            'privateKey',
        ]);
        if ($storage->type === BackupStorageType::Sftp) {
            $storage->setAttribute('private_key_uuid', $storage->privateKey?->uuid);
        }

        if (request()->attributes->get('can_read_sensitive', false) === true) {
            $storage->makeVisible([
                'key',
                'secret',
            ]);
        }

        return serializeApiResponse($storage);
    }

    /**
     * The request fields of a storage type. S3 has a bucket, a region, and a URL style; the other types have a URL,
     * a username (key), and a password (secret).
     *
     * @return array<int, string>
     */
    private function connectionFields(BackupStorageType $type): array
    {
        return match ($type) {
            BackupStorageType::S3 => ['endpoint', 'bucket', 'region', 'key', 'secret', 'use_path_style_endpoint', 'skip_tls_verification'],
            BackupStorageType::Sftp => ['endpoint', 'key', 'secret', 'private_key_uuid'],
            default => ['endpoint', 'key', 'secret'],
        };
    }

    /**
     * connectionRules() with the private key as a UUID of the team, as the API sends it.
     *
     * @return array<string, mixed>
     */
    private function connectionRules(BackupStorageType $type, int $teamId, bool $partial = false, array $trustedInternalHosts = [], ?string $savedEndpoint = null): array
    {
        $rules = S3Storage::connectionRules($type, $partial, $trustedInternalHosts, $teamId, $savedEndpoint);
        if (array_key_exists('private_key_id', $rules)) {
            unset($rules['private_key_id']);
            $rules['private_key_uuid'] = ['nullable', 'string', Rule::exists('private_keys', 'uuid')->where('team_id', $teamId)->where(fn ($query) => $query->where('is_git_related', false))];
            if (! $partial) {
                $rules['secret'] = ['required_without:private_key_uuid', 'nullable', 'string', 'max:255'];
            }
        }

        return $rules;
    }

    /**
     * The login columns of an SFTP storage from the request: a private key UUID logs in with that key, a password
     * logs in with the password. Empty when the request changes neither.
     *
     * @param  array<string, mixed>  $body
     * @return array{private_key_id?: int|null, secret?: string}|JsonResponse
     */
    private function sftpLoginAttributes(array $body, int $teamId, ?string $currentSecret = null): array|JsonResponse
    {
        if (filled($body['private_key_uuid'] ?? null)) {
            return [
                'private_key_id' => PrivateKey::query()->where('team_id', $teamId)->where('is_git_related', false)->where('uuid', $body['private_key_uuid'])->value('id'),
                'secret' => '',
            ];
        }
        if (filled($body['secret'] ?? null)) {
            return ['private_key_id' => null, 'secret' => $body['secret']];
        }
        if (array_key_exists('private_key_uuid', $body)) {
            if (blank($currentSecret)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['secret' => ['Send a password (secret) to log in without a private key.']],
                ], 422);
            }

            return ['private_key_id' => null];
        }

        return [];
    }

    /**
     * @return array{valid: bool, error: string|null}
     */
    private function validateStorageConnection(S3Storage $storage): array
    {
        try {
            $storage->testConnection(shouldSave: true, acceptNewHostKey: true);

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
        description: 'List all backup storages (S3, SFTP, FTPS, and WebDAV) for the authenticated team.',
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
                                    'type' => ['type' => 'string', 'enum' => ['s3', 'sftp', 'ftps', 'webdav']],
                                    'name' => ['type' => 'string'],
                                    'description' => ['type' => 'string', 'nullable' => true],
                                    'endpoint' => ['type' => 'string'],
                                    'bucket' => ['type' => 'string', 'nullable' => true, 'description' => 'S3 only. Null for the other types.'],
                                    'region' => ['type' => 'string', 'description' => 'S3 only. The other types have a placeholder value.'],
                                    'use_path_style_endpoint' => ['type' => 'boolean'],
                                    'skip_tls_verification' => ['type' => 'boolean'],
                                    'private_key_uuid' => ['type' => 'string', 'nullable' => true, 'description' => 'SFTP only: the UUID of the private key used to log in. Null with a password login.'],
                                    'sftp_host_key' => ['type' => 'string', 'nullable' => true, 'description' => 'SFTP only: the pinned host key of the server, as "<key type> <base64>". Saved by the first successful connection test.'],
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
            ->with('privateKey:id,uuid')
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
                                'type' => ['type' => 'string', 'enum' => ['s3', 'sftp', 'ftps', 'webdav']],
                                'name' => ['type' => 'string'],
                                'description' => ['type' => 'string', 'nullable' => true],
                                'endpoint' => ['type' => 'string'],
                                'bucket' => ['type' => 'string', 'nullable' => true, 'description' => 'S3 only. Null for the other types.'],
                                'region' => ['type' => 'string', 'description' => 'S3 only. The other types have a placeholder value.'],
                                'use_path_style_endpoint' => ['type' => 'boolean'],
                                'skip_tls_verification' => ['type' => 'boolean'],
                                'private_key_uuid' => ['type' => 'string', 'nullable' => true, 'description' => 'SFTP only: the UUID of the private key used to log in. Null with a password login.'],
                                'sftp_host_key' => ['type' => 'string', 'nullable' => true, 'description' => 'SFTP only: the pinned host key of the server, as "<key type> <base64>". Saved by the first successful connection test.'],
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
                    required: ['name', 'endpoint', 'key'],
                    properties: [
                        'type' => ['type' => 'string', 'enum' => ['s3', 'sftp', 'ftps', 'webdav'], 'default' => 's3', 'description' => 'The storage protocol. It cannot change later.'],
                        'name' => ['type' => 'string', 'example' => 'My S3 Storage', 'description' => 'A friendly name for the storage.'],
                        'description' => ['type' => 'string', 'nullable' => true, 'description' => 'Optional description.'],
                        'endpoint' => ['type' => 'string', 'example' => 'https://s3.us-east-1.amazonaws.com', 'description' => 'S3: the S3-compatible endpoint URL. SFTP and FTPS: sftp://host:port/directory or ftps://host:port/directory (explicit TLS); the directory is an absolute path, and without one the login directory is used. WebDAV: the https URL of the backup directory.'],
                        'bucket' => ['type' => 'string', 'example' => 'my-bucket', 'description' => 'S3 bucket name. Required for S3, not allowed for the other types.'],
                        'region' => ['type' => 'string', 'example' => 'us-east-1', 'description' => 'S3 region. Required for S3, not allowed for the other types.'],
                        'use_path_style_endpoint' => ['type' => 'boolean', 'description' => 'Use path-style URLs (endpoint/bucket). Set to false for providers that only accept virtual-hosted-style URLs (bucket.endpoint). Default: false for Alibaba Cloud OSS, Tencent Cloud COS, and Volcengine TOS endpoints, true for all other endpoints.'],
                        'skip_tls_verification' => ['type' => 'boolean', 'description' => 'S3 only. Do not check the TLS certificate of the endpoint. Use only for endpoints with a self-signed or private CA certificate. Default: false.'],
                        'key' => ['type' => 'string', 'description' => 'S3: the access key. Other types: the username.'],
                        'secret' => ['type' => 'string', 'description' => 'S3: the secret key. Other types: the password. SFTP: not needed with private_key_uuid.'],
                        'private_key_uuid' => ['type' => 'string', 'nullable' => true, 'description' => 'SFTP only: the UUID of a private key of your team to log in with instead of a password.'],
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
        $rawType = $body['type'] ?? 's3';
        $type = is_string($rawType) ? BackupStorageType::tryFrom($rawType) : null;
        $isS3 = $type === BackupStorageType::S3;
        $connectionFields = $this->connectionFields($type ?? BackupStorageType::S3);

        $validationError = $this->validateBody($body, ['type', 'name', 'description', 'is_usable', ...$connectionFields], [
            'type' => ['sometimes', Rule::enum(BackupStorageType::class)],
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            ...$this->connectionRules($type ?? BackupStorageType::S3, $teamId),
            'use_path_style_endpoint' => 'sometimes|boolean',
            'skip_tls_verification' => 'sometimes|boolean',
            'is_usable' => 'sometimes|boolean',
        ]);
        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }
        $login = $type === BackupStorageType::Sftp ? $this->sftpLoginAttributes($body, $teamId) : [];
        if ($login instanceof JsonResponse) {
            return $login;
        }

        $storage = S3Storage::create([
            'team_id' => $teamId,
            'type' => $type,
            'name' => $body['name'],
            'description' => $body['description'] ?? null,
            'endpoint' => $body['endpoint'],
            'bucket' => $isS3 ? $body['bucket'] : null,
            'region' => $isS3 ? $body['region'] : 'us-east-1',
            'key' => $body['key'],
            'secret' => $body['secret'] ?? '',
            ...$login,
            'use_path_style_endpoint' => $isS3 ? ($body['use_path_style_endpoint'] ?? S3Storage::detectPathStyleEndpoint($body['endpoint'])) : true,
            'skip_tls_verification' => $isS3 && ($body['skip_tls_verification'] ?? false),
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
        description: 'Update S3 storage by UUID. The type cannot change, and only the fields of the storage type are accepted.',
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
                        'endpoint' => ['type' => 'string', 'description' => 'The endpoint URL, in the format of the storage type (see Create S3 Storage).'],
                        'bucket' => ['type' => 'string', 'description' => 'S3 bucket name. Only for S3 storages.'],
                        'region' => ['type' => 'string', 'description' => 'S3 region. Only for S3 storages.'],
                        'use_path_style_endpoint' => ['type' => 'boolean', 'description' => 'Use path-style URLs (endpoint/bucket). Set to false for providers that only accept virtual-hosted-style URLs (bucket.endpoint), such as Alibaba Cloud OSS.'],
                        'skip_tls_verification' => ['type' => 'boolean', 'description' => 'S3 only. Do not check the TLS certificate of the endpoint. Use only for endpoints with a self-signed or private CA certificate. Default: false.'],
                        'key' => ['type' => 'string', 'description' => 'S3: the access key. Other types: the username.'],
                        'secret' => ['type' => 'string', 'description' => 'S3: the secret key. Other types: the password. SFTP: sending a password switches the login to the password.'],
                        'private_key_uuid' => ['type' => 'string', 'nullable' => true, 'description' => 'SFTP only: the UUID of a private key of your team to log in with. Null switches the login to the saved password.'],
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
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $body = $request->json()->all();

        $storage = S3Storage::ownedByCurrentTeamAPI($teamId)->whereUuid($request->route('uuid'))->first();
        if (! $storage) {
            return response()->json(['message' => 'S3 storage not found.'], 404);
        }
        $this->authorize('update', $storage);

        $allowedFields = ['name', 'description', 'is_usable', ...$this->connectionFields($storage->type)];
        $validationError = $this->validateBody($body, $allowedFields, [
            'name' => ValidationPatterns::nameRules(required: false),
            'description' => ValidationPatterns::descriptionRules(),
            ...$this->connectionRules($storage->type, $teamId, partial: true, trustedInternalHosts: $storage->trustedInternalHosts(), savedEndpoint: $storage->endpoint),
            'use_path_style_endpoint' => 'sometimes|boolean',
            'skip_tls_verification' => 'sometimes|boolean',
            'is_usable' => 'sometimes|boolean',
        ]);
        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }
        $login = $storage->type === BackupStorageType::Sftp ? $this->sftpLoginAttributes($body, $teamId, $storage->secret) : [];
        if ($login instanceof JsonResponse) {
            return $login;
        }

        $attributes = array_intersect_key($body, array_flip($allowedFields));
        unset($attributes['private_key_uuid']);
        if ($storage->type === BackupStorageType::Sftp && blank($attributes['secret'] ?? null)) {
            unset($attributes['secret']);
        }
        $storage->update([...$attributes, ...$login]);

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
        description: 'Validate a storage connection. S3: lists the bucket (ListObjectsV2). SFTP, FTPS, and WebDAV: lists the backup directory; for SFTP, the first successful validation pins the host key of the server.',
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
            'message' => $validation['valid'] ? $storage->storageType()->label().' storage connection is valid.' : $validation['error'],
        ]);
    }
}
