<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ServerSentinelController extends Controller
{
    private const ALLOWED_FIELDS = [
        'is_sentinel_enabled',
        'is_metrics_enabled',
        'is_sentinel_debug_enabled',
        'sentinel_token',
        'sentinel_metrics_refresh_rate_seconds',
        'sentinel_metrics_history_days',
        'sentinel_push_interval_seconds',
        'sentinel_custom_url',
    ];

    private function findServerForTeam(int $teamId, string $uuid): ?Server
    {
        return Server::whereTeamId($teamId)->whereUuid($uuid)->first();
    }

    private function canReadSensitive(): bool
    {
        return request()->attributes->get('can_read_sensitive', false) === true;
    }

    private function transform(Server $server): array
    {
        $settings = $server->settings;
        $payload = [
            'is_sentinel_enabled' => (bool) $settings->is_sentinel_enabled,
            'is_metrics_enabled' => (bool) $settings->is_metrics_enabled,
            'is_sentinel_debug_enabled' => (bool) $settings->is_sentinel_debug_enabled,
            'sentinel_metrics_refresh_rate_seconds' => (int) $settings->sentinel_metrics_refresh_rate_seconds,
            'sentinel_metrics_history_days' => (int) $settings->sentinel_metrics_history_days,
            'sentinel_push_interval_seconds' => (int) $settings->sentinel_push_interval_seconds,
            'sentinel_updated_at' => $server->sentinel_updated_at,
        ];

        if ($this->canReadSensitive()) {
            $payload['sentinel_token'] = $settings->sentinel_token;
            $payload['sentinel_custom_url'] = $settings->sentinel_custom_url;
        }

        return $payload;
    }

    #[OA\Get(
        summary: 'Get Sentinel settings',
        description: 'Get Sentinel settings for a server owned by the authenticated team. sentinel_token and sentinel_custom_url require the read:sensitive or root token ability.',
        path: '/servers/{uuid}/sentinel',
        operationId: 'get-server-sentinel',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sentinel settings.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'is_sentinel_enabled', type: 'boolean'),
                        new OA\Property(property: 'is_metrics_enabled', type: 'boolean'),
                        new OA\Property(property: 'is_sentinel_debug_enabled', type: 'boolean'),
                        new OA\Property(property: 'sentinel_token', type: 'string', description: 'Only present with read:sensitive.'),
                        new OA\Property(property: 'sentinel_metrics_refresh_rate_seconds', type: 'integer'),
                        new OA\Property(property: 'sentinel_metrics_history_days', type: 'integer'),
                        new OA\Property(property: 'sentinel_push_interval_seconds', type: 'integer'),
                        new OA\Property(property: 'sentinel_custom_url', type: 'string', description: 'Only present with read:sensitive.'),
                        new OA\Property(property: 'sentinel_updated_at', type: 'string', nullable: true),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = $this->findServerForTeam($teamId, $request->uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('view', $server);

        return response()->json($this->transform($server));
    }

    #[OA\Patch(
        summary: 'Update Sentinel settings',
        description: 'Update Sentinel settings for a server owned by the authenticated team. Changing token/metrics timing fields may restart Sentinel.',
        path: '/servers/{uuid}/sentinel',
        operationId: 'update-server-sentinel',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'is_sentinel_enabled', type: 'boolean'),
                    new OA\Property(property: 'is_metrics_enabled', type: 'boolean'),
                    new OA\Property(property: 'is_sentinel_debug_enabled', type: 'boolean'),
                    new OA\Property(property: 'sentinel_token', type: 'string'),
                    new OA\Property(property: 'sentinel_metrics_refresh_rate_seconds', type: 'integer', minimum: 1),
                    new OA\Property(property: 'sentinel_metrics_history_days', type: 'integer', minimum: 1),
                    new OA\Property(property: 'sentinel_push_interval_seconds', type: 'integer', minimum: 10),
                    new OA\Property(property: 'sentinel_custom_url', type: 'string', nullable: true),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated Sentinel settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
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

        $server = $this->findServerForTeam($teamId, $request->uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $validator = customApiValidator($request->all(), [
            'is_sentinel_enabled' => 'boolean',
            'is_metrics_enabled' => 'boolean',
            'is_sentinel_debug_enabled' => 'boolean',
            'sentinel_token' => ['string', 'max:500', 'regex:/\A[a-zA-Z0-9._\-+=\/]+\z/'],
            'sentinel_metrics_refresh_rate_seconds' => 'integer|min:1',
            'sentinel_metrics_history_days' => 'integer|min:1',
            'sentinel_push_interval_seconds' => 'integer|min:10',
            'sentinel_custom_url' => 'nullable|url',
        ]);

        $extraFields = array_diff(array_keys($request->all()), self::ALLOWED_FIELDS);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        if ($request->has('sentinel_token') && ! ServerSetting::isValidSentinelToken($request->input('sentinel_token'))) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['sentinel_token' => ['Invalid sentinel token characters.']],
            ], 422);
        }

        $settings = $server->settings;
        $enablingSentinel = $request->has('is_sentinel_enabled')
            && $request->boolean('is_sentinel_enabled')
            && ! $settings->is_sentinel_enabled;

        if ($enablingSentinel && $server->isBuildServer()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['is_sentinel_enabled' => ['Sentinel cannot be enabled on build servers.']],
            ], 422);
        }

        foreach (self::ALLOWED_FIELDS as $field) {
            if ($request->has($field)) {
                $settings->{$field} = $request->input($field);
            }
        }

        // Disabling Sentinel also clears related toggles (matches Livewire toggleSentinel).
        if ($request->has('is_sentinel_enabled') && ! $request->boolean('is_sentinel_enabled')) {
            $settings->is_metrics_enabled = false;
            $settings->is_sentinel_debug_enabled = false;
        }

        $settings->save();

        auditLog('api.server.sentinel.updated', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'changed_fields' => array_values(array_intersect(self::ALLOWED_FIELDS, array_keys($request->all()))),
        ]);

        return response()->json($this->transform($server->refresh()));
    }
}
