<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\IntegrationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ApplicationSecretManagerController extends Controller
{
    #[OA\Patch(
        summary: 'Configure Application Secret Manager',
        description: 'Configure the secret manager source used by an application.',
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
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)
            ->where('uuid', $request->route('uuid'))
            ->first();

        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('update', $application);

        $body = $request->json()->all();
        $token = IntegrationToken::query()
            ->where('team_id', $teamId)
            ->where('uuid', $body['integration_token_uuid'] ?? '')
            ->whereIn('provider', IntegrationToken::SECRET_MANAGER_PROVIDERS)
            ->first();

        if (! $token || ! in_array('secrets', $token->capabilities ?? [], true)) {
            return response()->json(['message' => 'Secret manager integration token not found.'], 404);
        }

        $rules = [
            'integration_token_uuid' => ['required', 'string'],
            'settings' => ['sometimes', 'array'],
        ];
        $rules += match ($token->provider) {
            'doppler' => $token->dopplerTokenType() === 'service_account' ? [
                'settings.project' => ['required', 'string'],
                'settings.config' => ['required', 'string'],
            ] : [],
            'infisical' => [
                'settings.project_id' => ['required', 'string'],
                'settings.environment' => ['required', 'string'],
                'settings.secret_path' => ['nullable', 'string'],
            ],
            'vault' => [
                'settings.mount' => ['required', 'string'],
                'settings.path' => ['required', 'string'],
            ],
            default => [],
        };

        $validator = customApiValidator($body, $rules);
        $extraFields = array_diff(array_keys($body), ['integration_token_uuid', 'settings']);

        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $settings = array_filter($validator->validated()['settings'] ?? [], fn ($value) => filled($value));
        $application->secretManagerLink()->updateOrCreate([], [
            'integration_token_id' => $token->id,
            'settings' => $settings ?: null,
        ]);

        auditLog('api.application.secret_manager.updated', [
            'team_id' => $teamId,
            'application_uuid' => $application->uuid,
            'integration_token_uuid' => $token->uuid,
        ]);

        return response()->json([
            'integration_token_uuid' => $token->uuid,
            'provider' => $token->provider,
            'settings' => $settings ?: null,
        ]);
    }
}
