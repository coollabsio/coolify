<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationToken;
use App\Services\IntegrationTokenValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class IntegrationTokensController extends Controller
{
    #[OA\Post(
        summary: 'Create Secret Manager Token',
        description: 'Create and validate a Doppler, Infisical, or Vault integration token.',
        path: '/security/integration-tokens',
        operationId: 'create-secret-manager-integration-token',
        security: [['bearerAuth' => []]],
        tags: ['Secret Managers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['provider', 'name', 'token'],
                properties: [
                    new OA\Property(property: 'provider', type: 'string', enum: ['doppler', 'infisical', 'vault']),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'metadata', type: 'object'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Integration token created.'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function store(Request $request, IntegrationTokenValidator $tokenValidator): JsonResponse
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
        $rules = [
            'provider' => ['required', 'string', 'in:'.implode(',', IntegrationToken::SECRET_MANAGER_PROVIDERS)],
            'name' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string'],
            'metadata' => ['sometimes', 'array'],
        ];

        if (($body['provider'] ?? null) === 'doppler') {
            $rules['token'][] = 'regex:/^dp\.(st|sa)\./';
        } elseif (($body['provider'] ?? null) === 'infisical') {
            $rules['metadata.base_url'] = ['required', 'url:http,https'];
            $rules['metadata.client_id'] = ['required', 'string'];
        } elseif (($body['provider'] ?? null) === 'vault') {
            $rules['metadata.base_url'] = ['required', 'url:http,https'];
            $rules['metadata.namespace'] = ['nullable', 'string'];
        }

        $validator = customApiValidator($body, $rules);
        $extraFields = array_diff(array_keys($body), ['provider', 'name', 'token', 'metadata']);

        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $validated = $validator->validated();
        $metadata = array_filter($validated['metadata'] ?? [], fn ($value) => filled($value));

        if (! $tokenValidator->validate($validated['provider'], $validated['token'], ['secrets'], $metadata)) {
            return response()->json(['message' => $tokenValidator->errorMessage($validated['provider'])], 400);
        }

        $integrationToken = IntegrationToken::query()->create([
            'team_id' => $teamId,
            'provider' => $validated['provider'],
            'name' => $validated['name'],
            'token' => $validated['token'],
            'capabilities' => ['secrets'],
            'metadata' => $metadata ?: null,
        ]);

        auditLog('api.integration_token.created', [
            'team_id' => $teamId,
            'integration_token_uuid' => $integrationToken->uuid,
            'provider' => $integrationToken->provider,
        ]);

        return response()->json(['uuid' => $integrationToken->uuid], 201);
    }
}
