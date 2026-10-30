<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\IntegrationToken;
use App\Models\SecretManagerLink;
use App\Models\Service;
use App\Services\SecretManagers\ResourceSecretManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Schema(
    schema: 'SecretManagerSource',
    description: 'The secret manager source of an application, service, or database. Routes start with the resource type (`applications`, `services`, `databases`), so tokens limited to some resources can use them.',
    type: 'object',
    properties: [
        new OA\Property(property: 'linked', type: 'boolean'),
        new OA\Property(property: 'integration_token_uuid', type: 'string', nullable: true),
        new OA\Property(property: 'provider', type: 'string', nullable: true, enum: ['doppler', 'infisical', 'vault', null]),
        new OA\Property(property: 'settings', type: 'object', nullable: true, description: 'Doppler: project, config. Infisical: project_id, environment, secret_path. Vault: mount, path.'),
        new OA\Property(property: 'available_tokens', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'uuid', type: 'string'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'provider', type: 'string'),
        ])),
    ],
)]
class SecretManagerController extends Controller
{
    public function __construct(private ResourceSecretManager $secretManager) {}

    #[OA\Get(
        summary: 'Get Secret Manager Source',
        description: 'The secret manager source of an application, service, or database, and the secret manager tokens of its team that can be linked.',
        path: '/{resource_type}/{uuid}/secret-manager',
        operationId: 'get-secret-manager-source',
        security: [['bearerAuth' => []]],
        tags: ['Secret Managers'],
        parameters: [new OA\Parameter(name: 'resource_type', in: 'path', required: true, description: 'Resource type.', schema: new OA\Schema(type: 'string', enum: ['applications', 'services', 'databases'])), new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Resource UUID.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Secret manager source.', content: new OA\JsonContent(ref: '#/components/schemas/SecretManagerSource')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No access to the resource.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $resource = $this->findResource($request, 'view');
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        return response()->json($this->sourceData($resource));
    }

    #[OA\Patch(
        summary: 'Set Secret Manager Source',
        description: 'Link a secret manager token of the team to an application, service, or database, or change the token or settings of the link. References resolve at the next deployment.',
        path: '/{resource_type}/{uuid}/secret-manager',
        operationId: 'set-secret-manager-source',
        security: [['bearerAuth' => []]],
        tags: ['Secret Managers'],
        parameters: [new OA\Parameter(name: 'resource_type', in: 'path', required: true, description: 'Resource type.', schema: new OA\Schema(type: 'string', enum: ['applications', 'services', 'databases'])), new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Resource UUID.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['integration_token_uuid'],
                properties: [
                    new OA\Property(property: 'integration_token_uuid', type: 'string'),
                    new OA\Property(property: 'settings', type: 'object', description: 'Doppler service account: project, config. Infisical: project_id, environment, secret_path. Vault: mount, path.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Secret manager configured.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the resource.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    #[OA\Patch(
        summary: 'Configure Application Secret Manager',
        description: 'Configure the secret manager source used by an application. Same as Set Secret Manager Source with resource_type applications.',
        path: '/applications/{uuid}/secret-manager',
        operationId: 'configure-application-secret-manager',
        security: [['bearerAuth' => []]],
        tags: ['Secret Managers'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['integration_token_uuid'],
                properties: [
                    new OA\Property(property: 'integration_token_uuid', type: 'string'),
                    new OA\Property(property: 'settings', type: 'object'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Secret manager configured.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the resource.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $resource = $this->findResource($request, 'update');
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $body = $request->json()->all();
        $token = is_string($body['integration_token_uuid'] ?? null)
            ? $this->secretManager->findToken($resource, $body['integration_token_uuid'])
            : null;
        if (! $token) {
            return response()->json(['message' => 'Secret manager integration token not found.'], 404);
        }

        $body = $this->validateBody($body, [
            'integration_token_uuid' => ['required', 'string'],
            'settings' => ['sometimes', 'array'],
        ] + $this->secretManager->settingsRules($token));
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $link = $this->secretManager->link($resource, $token, $body['settings'] ?? [], 'api');

        return response()->json([
            'integration_token_uuid' => $token->uuid,
            'provider' => $token->provider,
            'settings' => $link->settings,
        ]);
    }

    #[OA\Delete(
        summary: 'Remove Secret Manager Source',
        description: 'Remove the secret manager source. Existing {{vault.*}} variables stay and fail the next deployment until they are removed.',
        path: '/{resource_type}/{uuid}/secret-manager',
        operationId: 'remove-secret-manager-source',
        security: [['bearerAuth' => []]],
        tags: ['Secret Managers'],
        parameters: [new OA\Parameter(name: 'resource_type', in: 'path', required: true, description: 'Resource type.', schema: new OA\Schema(type: 'string', enum: ['applications', 'services', 'databases'])), new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Resource UUID.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Source removed.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the resource.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $resource = $this->findResource($request, 'update');
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        if ($this->sourceLink($resource) === null) {
            return $this->noSourceResponse();
        }

        $this->secretManager->unlink($resource, 'api');

        return response()->json(['message' => 'Secret manager source removed. Existing {{vault.*}} variables fail the next deployment until they are removed.']);
    }

    #[OA\Get(
        summary: 'List Secret Manager Keys',
        description: 'Key names of the secrets in the linked source. Values are never returned. Requires write access, like the resource page, because the stored secret is sent to the secret manager.',
        path: '/{resource_type}/{uuid}/secret-manager/keys',
        operationId: 'list-secret-manager-keys',
        security: [['bearerAuth' => []]],
        tags: ['Secret Managers'],
        parameters: [new OA\Parameter(name: 'resource_type', in: 'path', required: true, description: 'Resource type.', schema: new OA\Schema(type: 'string', enum: ['applications', 'services', 'databases'])), new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Resource UUID.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Key names.', content: new OA\JsonContent(properties: [new OA\Property(property: 'keys', type: 'array', items: new OA\Items(type: 'string'))])),
            new OA\Response(response: 400, description: 'The secret manager did not return the keys.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the resource.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function keys(Request $request): JsonResponse
    {
        $resource = $this->findResource($request, 'update');
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        $link = $this->sourceLink($resource);
        if ($link === null) {
            return $this->noSourceResponse();
        }

        try {
            return response()->json(['keys' => $this->secretManager->keyNames($resource, $link, 'api')]);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not fetch keys: '.$e->getMessage()], 400);
        }
    }

    #[OA\Post(
        summary: 'Add Secret Manager Reference',
        description: 'Add a {{vault.KEY}} environment variable for one key of the linked source.',
        path: '/{resource_type}/{uuid}/secret-manager/references',
        operationId: 'add-secret-manager-reference',
        security: [['bearerAuth' => []]],
        tags: ['Secret Managers'],
        parameters: [new OA\Parameter(name: 'resource_type', in: 'path', required: true, description: 'Resource type.', schema: new OA\Schema(type: 'string', enum: ['applications', 'services', 'databases'])), new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Resource UUID.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['key'], properties: [new OA\Property(property: 'key', type: 'string')])),
        responses: [
            new OA\Response(response: 201, description: 'Variable added.'),
            new OA\Response(response: 400, description: 'The secret manager did not return the keys.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the resource.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, description: 'Not a key of the source, not a valid variable name, or the variable exists.'),
        ],
    )]
    public function addReference(Request $request): JsonResponse
    {
        $resource = $this->findResource($request, 'update');
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        $link = $this->sourceLink($resource);
        if ($link === null) {
            return $this->noSourceResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $body = $this->validateBody($request->json()->all(), ['key' => ['required', 'string', 'max:255']]);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        try {
            $keys = array_map('strval', array_keys($link->fetchSecrets()));
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not fetch keys: '.$e->getMessage()], 400);
        }

        try {
            if (! in_array($body['key'], $keys, true)) {
                throw ValidationException::withMessages(['key' => "{$body['key']} is not a key of the secret manager source."]);
            }
            $this->secretManager->addReference($resource, $body['key'], 'api');
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }

        return response()->json(['key' => $body['key'], 'value' => '{{vault.'.$body['key'].'}}'], 201);
    }

    #[OA\Post(
        summary: 'Import Secret Manager References',
        description: 'Add a {{vault.KEY}} environment variable for every key of the linked source that has no variable yet. Keys that are not valid variable names are skipped.',
        path: '/{resource_type}/{uuid}/secret-manager/import',
        operationId: 'import-secret-manager-references',
        security: [['bearerAuth' => []]],
        tags: ['Secret Managers'],
        parameters: [new OA\Parameter(name: 'resource_type', in: 'path', required: true, description: 'Resource type.', schema: new OA\Schema(type: 'string', enum: ['applications', 'services', 'databases'])), new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Resource UUID.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Import result.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'imported', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'skipped', type: 'array', items: new OA\Items(type: 'string')),
            ])),
            new OA\Response(response: 400, description: 'The secret manager did not return the keys.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the resource.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function import(Request $request): JsonResponse
    {
        $resource = $this->findResource($request, 'update');
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        $link = $this->sourceLink($resource);
        if ($link === null) {
            return $this->noSourceResponse();
        }

        try {
            return response()->json($this->secretManager->importAll($resource, $link, 'api'));
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not fetch keys: '.$e->getMessage()], 400);
        }
    }

    /**
     * The resource of the token team, authorized for the ability. Errors are returned as a response.
     */
    private function findResource(Request $request, string $ability): Model|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $uuid = (string) $request->route('uuid');
        [$resource, $name] = match ($request->route('resource_type')) {
            'applications' => [Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first(), 'Application'],
            'services' => [Service::whereRelation('environment.project.team', 'id', $teamId)->where('uuid', $uuid)->first(), 'Service'],
            default => [queryDatabaseByUuidWithinTeam($uuid, (string) $teamId), 'Database'],
        };
        if ($resource === null) {
            return response()->json(['message' => "{$name} not found."], 404);
        }

        $this->authorize($ability, $resource);

        return $resource;
    }

    private function sourceLink(Model $resource): ?SecretManagerLink
    {
        return $resource->secretManagerLink()->with('integrationToken')->first();
    }

    private function noSourceResponse(): JsonResponse
    {
        return response()->json(['message' => 'The resource has no secret manager source.'], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceData(Model $resource): array
    {
        $link = $this->sourceLink($resource);

        return [
            'linked' => $link !== null,
            'integration_token_uuid' => $link?->integrationToken?->uuid,
            'provider' => $link?->integrationToken?->provider,
            'settings' => $link?->settings,
            'available_tokens' => $this->secretManager->availableTokens($resource)
                ->map(fn (IntegrationToken $token): array => ['uuid' => $token->uuid, 'name' => $token->name, 'provider' => $token->provider])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    private function validateBody(array $body, array $rules): array|JsonResponse
    {
        $validator = customApiValidator($body, $rules);
        $allowed = array_unique(array_map(fn (string $field): string => explode('.', $field)[0], array_keys($rules)));
        $extraFields = array_diff(array_keys($body), $allowed);
        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        return $validator->validated();
    }
}
