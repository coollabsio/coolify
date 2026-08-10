<?php

namespace App\Http\Controllers\Api;

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ServerLogDrainsController extends Controller
{
    private const ALLOWED_FIELDS = [
        'is_logdrain_newrelic_enabled',
        'logdrain_newrelic_license_key',
        'logdrain_newrelic_base_uri',
        'is_logdrain_axiom_enabled',
        'logdrain_axiom_dataset_name',
        'logdrain_axiom_api_key',
        'is_logdrain_custom_enabled',
        'logdrain_custom_config',
        'logdrain_custom_config_parser',
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
            'is_logdrain_newrelic_enabled' => (bool) $settings->is_logdrain_newrelic_enabled,
            'logdrain_newrelic_base_uri' => $settings->logdrain_newrelic_base_uri,
            'is_logdrain_axiom_enabled' => (bool) $settings->is_logdrain_axiom_enabled,
            'logdrain_axiom_dataset_name' => $settings->logdrain_axiom_dataset_name,
            'is_logdrain_custom_enabled' => (bool) $settings->is_logdrain_custom_enabled,
        ];

        if ($this->canReadSensitive()) {
            $payload['logdrain_newrelic_license_key'] = $settings->logdrain_newrelic_license_key;
            $payload['logdrain_axiom_api_key'] = $settings->logdrain_axiom_api_key;
            $payload['logdrain_custom_config'] = $settings->logdrain_custom_config;
            $payload['logdrain_custom_config_parser'] = $settings->logdrain_custom_config_parser;
        }

        return $payload;
    }

    #[OA\Get(
        summary: 'Get log drain settings',
        description: 'Get log drain settings for a server owned by the authenticated team. Sensitive fields require the read:sensitive or root token ability.',
        path: '/servers/{uuid}/log-drains',
        operationId: 'get-server-log-drains',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Log drain settings.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'is_logdrain_newrelic_enabled', type: 'boolean'),
                        new OA\Property(property: 'logdrain_newrelic_license_key', type: 'string', description: 'Only present with read:sensitive.'),
                        new OA\Property(property: 'logdrain_newrelic_base_uri', type: 'string', nullable: true),
                        new OA\Property(property: 'is_logdrain_axiom_enabled', type: 'boolean'),
                        new OA\Property(property: 'logdrain_axiom_dataset_name', type: 'string', nullable: true),
                        new OA\Property(property: 'logdrain_axiom_api_key', type: 'string', description: 'Only present with read:sensitive.'),
                        new OA\Property(property: 'is_logdrain_custom_enabled', type: 'boolean'),
                        new OA\Property(property: 'logdrain_custom_config', type: 'string', description: 'Only present with read:sensitive.'),
                        new OA\Property(property: 'logdrain_custom_config_parser', type: 'string', description: 'Only present with read:sensitive.'),
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
        summary: 'Update log drain settings',
        description: 'Update New Relic, Axiom, or custom log drain settings for a server owned by the authenticated team.',
        path: '/servers/{uuid}/log-drains',
        operationId: 'update-server-log-drains',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'is_logdrain_newrelic_enabled', type: 'boolean'),
                    new OA\Property(property: 'logdrain_newrelic_license_key', type: 'string'),
                    new OA\Property(property: 'logdrain_newrelic_base_uri', type: 'string'),
                    new OA\Property(property: 'is_logdrain_axiom_enabled', type: 'boolean'),
                    new OA\Property(property: 'logdrain_axiom_dataset_name', type: 'string'),
                    new OA\Property(property: 'logdrain_axiom_api_key', type: 'string'),
                    new OA\Property(property: 'is_logdrain_custom_enabled', type: 'boolean'),
                    new OA\Property(property: 'logdrain_custom_config', type: 'string'),
                    new OA\Property(property: 'logdrain_custom_config_parser', type: 'string'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated log drain settings.'),
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
            'is_logdrain_newrelic_enabled' => 'boolean',
            'logdrain_newrelic_license_key' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'logdrain_newrelic_base_uri' => 'nullable|url',
            'is_logdrain_axiom_enabled' => 'boolean',
            'logdrain_axiom_dataset_name' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'logdrain_axiom_api_key' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'is_logdrain_custom_enabled' => 'boolean',
            'logdrain_custom_config' => 'nullable|string',
            'logdrain_custom_config_parser' => 'nullable|string',
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

        $settings = $server->settings;
        foreach (self::ALLOWED_FIELDS as $field) {
            if ($request->has($field)) {
                $settings->{$field} = $request->input($field);
            }
        }

        // Conditional required fields when enabling a drain type (matches Livewire).
        if ($settings->is_logdrain_newrelic_enabled) {
            $errors = [];
            if (blank($settings->logdrain_newrelic_license_key)) {
                $errors['logdrain_newrelic_license_key'] = ['The New Relic license key is required when New Relic log drain is enabled.'];
            }
            if (blank($settings->logdrain_newrelic_base_uri)) {
                $errors['logdrain_newrelic_base_uri'] = ['The New Relic base URI is required when New Relic log drain is enabled.'];
            }
            if ($errors !== []) {
                return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
            }
        }
        if ($settings->is_logdrain_axiom_enabled) {
            $errors = [];
            if (blank($settings->logdrain_axiom_dataset_name)) {
                $errors['logdrain_axiom_dataset_name'] = ['The Axiom dataset name is required when Axiom log drain is enabled.'];
            }
            if (blank($settings->logdrain_axiom_api_key)) {
                $errors['logdrain_axiom_api_key'] = ['The Axiom API key is required when Axiom log drain is enabled.'];
            }
            if ($errors !== []) {
                return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
            }
        }
        if ($settings->is_logdrain_custom_enabled && blank($settings->logdrain_custom_config)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'logdrain_custom_config' => ['The custom log drain config is required when custom log drain is enabled.'],
                ],
            ], 422);
        }

        $settings->save();
        $server->refresh();

        // Match Livewire instantSave: start or stop the drain service after settings change.
        if ($server->isLogDrainEnabled()) {
            StartLogDrain::dispatch($server);
        } else {
            StopLogDrain::dispatch($server);
        }

        auditLog('api.server.log_drains.updated', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'changed_fields' => array_values(array_intersect(self::ALLOWED_FIELDS, array_keys($request->all()))),
        ]);

        return response()->json($this->transform($server));
    }
}
