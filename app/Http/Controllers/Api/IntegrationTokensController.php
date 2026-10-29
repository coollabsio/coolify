<?php

namespace App\Http\Controllers\Api;

use App\Actions\IntegrationToken\CreateIntegrationToken;
use App\Actions\IntegrationToken\UpdateIntegrationToken;
use App\Http\Controllers\Controller;
use App\Models\IntegrationToken;
use App\Services\Dns\CloudflareDnsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use RuntimeException;

#[OA\Schema(
    schema: 'IntegrationToken',
    description: 'Integration token. The secret is never returned.',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'provider', type: 'string', enum: ['cloudflare', 'doppler', 'infisical', 'vault']),
        new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string', enum: ['dns', 'tunnel', 'secrets'])),
        new OA\Property(property: 'automatic_dns', type: 'boolean', description: 'Cloudflare only. Create DNS records when a domain is added.'),
        new OA\Property(property: 'proxied_dns', type: 'boolean', description: 'Cloudflare only. Create new records proxied (orange cloud).'),
        new OA\Property(property: 'dns_zones_count', type: 'integer', description: 'Cloudflare only.'),
        new OA\Property(property: 'zones_synced_at', type: 'string', nullable: true, description: 'Cloudflare only.'),
        new OA\Property(property: 'created_at', type: 'string'),
        new OA\Property(property: 'updated_at', type: 'string'),
    ],
)]
class IntegrationTokensController extends Controller
{
    #[OA\Get(
        summary: 'List Integration Tokens',
        description: 'List the integration tokens (Cloudflare, Doppler, Infisical, Vault) of the team. Requires admin or owner.',
        path: '/security/integration-tokens',
        operationId: 'list-integration-tokens',
        security: [['bearerAuth' => []]],
        tags: ['Integration Tokens'],
        responses: [
            new OA\Response(response: 200, description: 'Integration tokens.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/IntegrationToken'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
        ],
    )]
    public function index(): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('viewAny', IntegrationToken::class);

        $tokens = IntegrationToken::query()->where('team_id', $teamId)->withCount('dnsZones')->latest()->get();

        return response()->json($tokens->map(fn (IntegrationToken $token): array => $this->tokenData($token))->values());
    }

    #[OA\Get(
        summary: 'Get Integration Token',
        description: 'Get an integration token of the team by UUID. Requires admin or owner.',
        path: '/security/integration-tokens/{uuid}',
        operationId: 'get-integration-token-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Integration Tokens'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Integration token UUID.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Integration token.', content: new OA\JsonContent(ref: '#/components/schemas/IntegrationToken')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $token = $this->findToken($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        $this->authorize('view', $token);

        return response()->json($this->tokenData($token->loadCount('dnsZones')));
    }

    #[OA\Post(
        summary: 'Create Integration Token',
        description: 'Create and validate a Cloudflare, Doppler, Infisical, or Vault integration token. A Cloudflare token also gets its DNS zones; it is not stored when the zones cannot be read. Requires admin or owner.',
        path: '/security/integration-tokens',
        operationId: 'create-secret-manager-integration-token',
        security: [['bearerAuth' => []]],
        tags: ['Integration Tokens'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['provider', 'name', 'token'],
                properties: [
                    new OA\Property(property: 'provider', type: 'string', enum: ['cloudflare', 'doppler', 'infisical', 'vault']),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string', enum: ['dns', 'tunnel', 'secrets']), description: 'Cloudflare: dns, tunnel (tunnel needs dns). Default: dns. Secret managers: secrets.'),
                    new OA\Property(property: 'automatic_dns', type: 'boolean', description: 'Cloudflare only. Create DNS records when a domain is added. Default: true.'),
                    new OA\Property(property: 'proxied_dns', type: 'boolean', description: 'Cloudflare only. Create new records proxied (orange cloud). Default: false.'),
                    new OA\Property(property: 'metadata', type: 'object', description: 'Secret managers only: base_url, client_id (Infisical), namespace (Vault), timeout.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Integration token created.', content: new OA\JsonContent(properties: [new OA\Property(property: 'uuid', type: 'string')])),
            new OA\Response(response: 400, description: 'The provider rejected the token, or the Cloudflare zones could not be read.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', IntegrationToken::class);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $body = $request->json()->all();
        $provider = is_string($body['provider'] ?? null) ? $body['provider'] : '';
        $isCloudflare = $provider === 'cloudflare';
        $body['capabilities'] ??= $isCloudflare ? ['dns'] : ['secrets'];

        $rules = CreateIntegrationToken::rules($provider) + [
            'metadata' => $isCloudflare ? ['prohibited'] : ['sometimes', 'array'],
            'automatic_dns' => $isCloudflare ? ['sometimes', 'boolean'] : ['prohibited'],
            'proxied_dns' => $isCloudflare ? ['sometimes', 'boolean'] : ['prohibited'],
        ];

        $validator = customApiValidator($body, $rules);
        $extraFields = array_diff(array_keys($body), ['provider', 'name', 'token', 'capabilities', 'automatic_dns', 'proxied_dns', 'metadata']);

        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $validated = $validator->validated();

        try {
            $integrationToken = CreateIntegrationToken::run(
                (int) $teamId,
                $validated['provider'],
                $validated['name'],
                $validated['token'],
                array_values(array_unique($validated['capabilities'])),
                $validated['metadata'] ?? [],
                $validated['automatic_dns'] ?? true,
                $validated['proxied_dns'] ?? false,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        auditLog('api.integration_token.created', [
            'team_id' => $teamId,
            'integration_token_uuid' => $integrationToken->uuid,
            'provider' => $integrationToken->provider,
        ]);

        return response()->json(['uuid' => $integrationToken->uuid], 201);
    }

    #[OA\Patch(
        summary: 'Update Integration Token',
        description: 'Rename an integration token, rotate its secret, or change its capabilities and settings. Omitted fields keep their value. The provider checks the token again when the secret, the capabilities, or the settings change. Requires admin or owner.',
        path: '/security/integration-tokens/{uuid}',
        operationId: 'update-integration-token-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Integration Tokens'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Integration token UUID.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'token', type: 'string', description: 'New secret. Required when base_url, client_id (Infisical), or namespace (Vault) changes.'),
                    new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string', enum: ['dns', 'tunnel', 'secrets'])),
                    new OA\Property(property: 'automatic_dns', type: 'boolean', description: 'Cloudflare only.'),
                    new OA\Property(property: 'proxied_dns', type: 'boolean', description: 'Cloudflare only.'),
                    new OA\Property(property: 'metadata', type: 'object', description: 'Secret managers only. Fields to change: base_url, client_id, namespace, timeout. An empty value removes a field.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Integration token updated.', content: new OA\JsonContent(ref: '#/components/schemas/IntegrationToken')),
            new OA\Response(response: 400, description: 'The provider rejected the token, or the Cloudflare zones could not be read.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $token = $this->findToken($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        $this->authorize('update', $token);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $isCloudflare = $token->provider === 'cloudflare';
        $storedMetadata = $token->metadata ?? [];
        $body = $request->json()->all();
        $merged = [
            'name' => $body['name'] ?? $token->name,
            'token' => $body['token'] ?? null,
            'capabilities' => $body['capabilities'] ?? $token->capabilities,
            'metadata' => is_array($body['metadata'] ?? null) ? array_merge($storedMetadata, $body['metadata']) : $storedMetadata,
        ];

        $rules = UpdateIntegrationToken::rules($token->provider);
        $validator = customApiValidator($merged, $rules);
        $flags = customApiValidator($body, [
            'metadata' => $isCloudflare ? ['prohibited'] : ['sometimes', 'array'],
            'automatic_dns' => $isCloudflare ? ['sometimes', 'boolean'] : ['prohibited'],
            'proxied_dns' => $isCloudflare ? ['sometimes', 'boolean'] : ['prohibited'],
        ]);
        $extraFields = array_diff(array_keys($body), ['name', 'token', 'capabilities', 'automatic_dns', 'proxied_dns', 'metadata']);

        if ($validator->fails() || $flags->fails() || $extraFields !== []) {
            $errors = $validator->errors()->merge($flags->errors());
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $validated = $validator->validated();

        try {
            UpdateIntegrationToken::run(
                $token,
                $validated['name'],
                $validated['token'] ?? null,
                array_values(array_unique($validated['capabilities'])),
                $merged['metadata'],
                $body['automatic_dns'] ?? $token->automaticDnsEnabled(),
                $body['proxied_dns'] ?? $token->proxiedDnsEnabled(),
                'api',
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json($this->tokenData($token->refresh()->loadCount('dnsZones')));
    }

    #[OA\Delete(
        summary: 'Delete Integration Token',
        description: 'Delete an integration token. A token that a resource uses as secret manager source, or that manages DNS records, cannot be deleted. Requires admin or owner.',
        path: '/security/integration-tokens/{uuid}',
        operationId: 'delete-integration-token-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Integration Tokens'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Integration token UUID.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Integration token deleted.', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'The token is still in use.'),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $token = $this->findToken($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        $this->authorize('delete', $token);

        if ($blocker = $token->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $token->delete();
        auditLog('api.integration_token.deleted', [
            'team_id' => $token->team_id,
            'integration_token_uuid' => $token->uuid,
            'integration_token_name' => $token->name,
            'provider' => $token->provider,
        ]);

        return response()->json(['message' => 'Integration token deleted.']);
    }

    #[OA\Post(
        summary: 'Sync Cloudflare Zones',
        description: 'Read the DNS zones that a Cloudflare token can access again. Requires admin or owner.',
        path: '/security/integration-tokens/{uuid}/sync-zones',
        operationId: 'sync-integration-token-zones',
        security: [['bearerAuth' => []]],
        tags: ['Integration Tokens'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Integration token UUID.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Zones synchronized.', content: new OA\JsonContent(properties: [new OA\Property(property: 'zones_count', type: 'integer')])),
            new OA\Response(response: 400, description: 'Cloudflare did not return the zones.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, description: 'The token is not a Cloudflare token.'),
        ],
    )]
    public function syncZones(Request $request, CloudflareDnsProvider $cloudflare): JsonResponse
    {
        $token = $this->findToken($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        $this->authorize('update', $token);

        if ($token->provider !== 'cloudflare') {
            return response()->json(['message' => 'Only Cloudflare tokens have DNS zones.'], 422);
        }

        try {
            $count = $cloudflare->syncZones($token);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['zones_count' => $count]);
    }

    private function findToken(Request $request): IntegrationToken|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        return IntegrationToken::query()->where('team_id', $teamId)->where('uuid', $request->route('uuid'))->first()
            ?? response()->json(['message' => 'Integration token not found.'], 404);
    }

    /**
     * Fields that are safe to return. The secret and the provider settings (a base URL can carry credentials) are left out.
     *
     * @return array<string, mixed>
     */
    private function tokenData(IntegrationToken $token): array
    {
        $data = [
            'uuid' => $token->uuid,
            'name' => $token->name,
            'provider' => $token->provider,
            'capabilities' => $token->capabilities ?? [],
        ];

        if ($token->provider === 'cloudflare') {
            $data += [
                'automatic_dns' => $token->automaticDnsEnabled(),
                'proxied_dns' => $token->proxiedDnsEnabled(),
                'dns_zones_count' => (int) $token->dns_zones_count,
                'zones_synced_at' => data_get($token->metadata, 'zones_synced_at'),
            ];
        }

        return $data + [
            'created_at' => $token->created_at,
            'updated_at' => $token->updated_at,
        ];
    }
}
