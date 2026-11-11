<?php

namespace App\Http\Controllers\Api;

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\LogDrain\LegacyLogDrainFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ServerLogDrainsController extends Controller
{
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
        $payload = [];
        foreach (LegacyLogDrainFields::FLAGS as $flag => $type) {
            $payload[$flag] = $settings->logdrain_type === $type;
        }
        foreach (LegacyLogDrainFields::FIELDS as $name => [$type, $key]) {
            if ((LegacyLogDrainFields::field($name)['secret'] ?? false) && ! $this->canReadSensitive()) {
                continue;
            }
            $payload[$name] = $settings->logDrainConfig($type)[$key] ?? null;
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
                        new OA\Property(property: 'logdrain_axiom_domain', type: 'string', nullable: true),
                        new OA\Property(property: 'logdrain_axiom_api_key', type: 'string', description: 'Only present with read:sensitive.'),
                        new OA\Property(property: 'is_logdrain_custom_enabled', type: 'boolean'),
                        new OA\Property(property: 'logdrain_custom_config', type: 'string', description: 'Only present with read:sensitive.'),
                        new OA\Property(property: 'logdrain_custom_config_parser', type: 'string', description: 'Only present with read:sensitive.'),
                        new OA\Property(property: 'is_logdrain_cloudwatch_enabled', type: 'boolean'),
                        new OA\Property(property: 'logdrain_cloudwatch_region', type: 'string', nullable: true),
                        new OA\Property(property: 'logdrain_cloudwatch_group', type: 'string', nullable: true),
                        new OA\Property(property: 'logdrain_cloudwatch_stream_prefix', type: 'string', nullable: true, description: 'Prepended to the container name to form the log stream name.'),
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
        description: 'Update New Relic, Axiom, Amazon CloudWatch Logs, or custom log drain settings for a server owned by the authenticated team. Needs a token with the write and deploy permissions. Custom configs cannot use the lua, wasm, exec, exec_wasi, or file plugins, @INCLUDE, or plugins_file, and the parser file can only contain [PARSER] and [MULTILINE_PARSER] sections.',
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
                    new OA\Property(property: 'logdrain_axiom_domain', type: 'string', description: 'Base domain of the Axiom edge deployment. Default: us-east-1.aws.edge.axiom.co. Use eu-central-1.aws.edge.axiom.co for EU datasets.'),
                    new OA\Property(property: 'logdrain_axiom_api_key', type: 'string'),
                    new OA\Property(property: 'is_logdrain_custom_enabled', type: 'boolean'),
                    new OA\Property(property: 'logdrain_custom_config', type: 'string'),
                    new OA\Property(property: 'logdrain_custom_config_parser', type: 'string'),
                    new OA\Property(property: 'is_logdrain_cloudwatch_enabled', type: 'boolean'),
                    new OA\Property(property: 'logdrain_cloudwatch_region', type: 'string', nullable: true),
                    new OA\Property(property: 'logdrain_cloudwatch_group', type: 'string', nullable: true),
                    new OA\Property(property: 'logdrain_cloudwatch_stream_prefix', type: 'string', nullable: true, description: 'Prepended to the container name to form the log stream name.'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated log drain settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 403, description: 'The token needs the write and deploy permissions.'),
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

        $allowedFields = [...array_keys(LegacyLogDrainFields::FLAGS), ...array_keys(LegacyLogDrainFields::FIELDS)];
        $rules = array_map(fn () => 'boolean', LegacyLogDrainFields::FLAGS);
        foreach (array_keys(LegacyLogDrainFields::FIELDS) as $name) {
            $rules[$name] = ['nullable', ...LegacyLogDrainFields::field($name)['rules']];
        }
        $validator = customApiValidator($request->all(), $rules);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
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

        $config = $settings->logdrain_config ?? [];
        foreach (LegacyLogDrainFields::FIELDS as $name => [$type, $key]) {
            if (! $request->has($name)) {
                continue;
            }
            $value = $request->input($name);
            if (filled($value)) {
                $config[$type->value][$key] = $value;
            } else {
                unset($config[$type->value][$key]);
            }
        }
        $config = array_filter($config);

        $type = $settings->logdrain_type;
        $enabledTypes = [];
        foreach (LegacyLogDrainFields::FLAGS as $flag => $flagType) {
            if (! $request->has($flag)) {
                continue;
            }
            if ($request->boolean($flag)) {
                $enabledTypes[$flag] = $flagType;
            } elseif ($type === $flagType) {
                $type = null;
            }
        }
        if (count($enabledTypes) > 1) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => array_map(fn () => ['Only one log drain can be enabled at a time.'], $enabledTypes),
            ], 422);
        }
        if ($enabledTypes !== []) {
            $flag = array_key_first($enabledTypes);
            $enabledType = $enabledTypes[$flag];
            if ($type !== null && $type !== $enabledType) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        $flag => ["{$enabledType->label()} cannot be enabled together with another log drain."],
                    ],
                ], 422);
            }
            $type = $enabledType;
        }

        // The fields of the enabled drain must be set (matches Livewire).
        if ($type !== null) {
            $errors = [];
            foreach (LegacyLogDrainFields::FIELDS as $name => [$fieldType, $key]) {
                $field = LegacyLogDrainFields::field($name);
                if ($fieldType === $type && ($field['required'] ?? false) && blank($config[$type->value][$key] ?? null)) {
                    $errors[$name] = ["{$field['label']} is required when the {$type->label()} log drain is enabled."];
                }
            }
            if ($errors !== []) {
                return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
            }
        }

        $settings->logdrain_type = $type;
        $settings->logdrain_config = $config === [] ? null : $config;
        $settings->save();
        $server->refresh();

        // Match Livewire instantSave: start or stop the drain service after settings change.
        if ($server->isFunctional()) {
            if ($server->isLogDrainEnabled()) {
                StartLogDrain::dispatch($server);
            } else {
                StopLogDrain::dispatch($server);
            }
        }

        auditLog('api.server.log_drains.updated', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'changed_fields' => array_values(array_intersect($allowedFields, array_keys($request->all()))),
        ]);

        return response()->json($this->transform($server));
    }
}
