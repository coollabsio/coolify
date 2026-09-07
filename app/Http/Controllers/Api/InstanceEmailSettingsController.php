<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstanceSettings;
use App\Rules\ValidHostname;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

class InstanceEmailSettingsController extends Controller
{
    private const FIELDS = [
        'smtp_enabled', 'smtp_from_address', 'smtp_from_name', 'smtp_host',
        'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'smtp_timeout', 'smtp_ehlo_domain', 'resend_enabled', 'resend_api_key',
    ];

    #[OA\Get(
        summary: 'Get instance email settings',
        description: 'Get instance-wide SMTP and Resend settings. Requires a root-team token belonging to a root-team admin or owner. Sensitive fields require the `read:sensitive` or `root` token ability.',
        path: '/settings/email', operationId: 'get-instance-email-settings',
        security: [['bearerAuth' => []]], tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'Instance email settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
        ]
    )]
    public function show(): JsonResponse
    {
        $settings = InstanceSettings::get();
        $this->authorizeRootTeam('view', $settings);

        return response()->json($this->serialize($settings));
    }

    #[OA\Patch(
        summary: 'Update instance email settings',
        description: 'Update instance-wide SMTP and Resend settings. Requires `write:sensitive` and a root-team token belonging to a root-team admin or owner.',
        path: '/settings/email', operationId: 'update-instance-email-settings',
        security: [['bearerAuth' => []]], tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'Updated instance email settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $settings = InstanceSettings::get();
        $this->authorizeRootTeam('update', $settings);

        $validator = customApiValidator($request->json()->all(), [
            'smtp_enabled' => 'sometimes|boolean',
            'smtp_from_address' => 'sometimes|nullable|email',
            'smtp_from_name' => 'sometimes|nullable|string|max:255',
            'smtp_host' => 'sometimes|nullable|string|max:255',
            'smtp_port' => 'sometimes|nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'sometimes|nullable|string|in:starttls,tls,none',
            'smtp_username' => 'sometimes|nullable|string|max:255',
            'smtp_password' => 'sometimes|nullable|string|max:255',
            'smtp_timeout' => 'sometimes|nullable|integer|min:0',
            'smtp_ehlo_domain' => ['sometimes', 'nullable', 'string', 'max:255', new ValidHostname],
            'resend_enabled' => 'sometimes|boolean',
            'resend_api_key' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $settings->fill($validator->validated());
        $settings->save();

        auditLog('api.settings.email.updated', ['changed_fields' => array_keys($validator->validated())]);

        return response()->json($this->serialize($settings->refresh()));
    }

    private function authorizeRootTeam(string $ability, InstanceSettings $settings): void
    {
        $teamId = getTeamIdFromToken();
        abort_unless(! is_null($teamId) && (int) $teamId === 0, 403, 'Instance email settings require a root-team API token.');
        $this->authorize($ability, $settings);
    }

    private function serialize(InstanceSettings $settings): array
    {
        exposeSensitiveFields($settings);

        return Arr::only($settings->toArray(), self::FIELDS);
    }
}
