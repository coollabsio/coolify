<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ServerCloudflareTunnelController extends Controller
{
    private const ALLOWED_FIELDS = [
        'is_cloudflare_tunnel',
        'is_cloudflare_http_tunnel',
    ];

    private function findServerForTeam(int $teamId, string $uuid): ?Server
    {
        return Server::whereTeamId($teamId)->whereUuid($uuid)->first();
    }

    private function transform(Server $server): array
    {
        $settings = $server->settings;

        return [
            'is_cloudflare_tunnel' => (bool) $settings->is_cloudflare_tunnel,
            'is_cloudflare_http_tunnel' => (bool) $settings->is_cloudflare_http_tunnel,
            'cloudflare_http_tunnel_id' => $settings->cloudflare_http_tunnel_id,
            'cloudflare_http_tunnel_cname' => $settings->cloudflare_http_tunnel_cname,
            'cloudflare_http_tunnel_hostname' => $settings->cloudflare_http_tunnel_hostname,
            'cloudflare_dashboard_hostname' => $settings->cloudflare_dashboard_hostname,
            'ip' => $server->ip,
            'ip_previous' => $server->ip_previous,
        ];
    }

    #[OA\Get(
        summary: 'Get Cloudflare Tunnel settings',
        description: 'Get Cloudflare Tunnel settings for a server owned by the authenticated team.',
        path: '/servers/{uuid}/cloudflare-tunnel',
        operationId: 'get-server-cloudflare-tunnel',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cloudflare Tunnel settings.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'is_cloudflare_tunnel', type: 'boolean'),
                        new OA\Property(property: 'is_cloudflare_http_tunnel', type: 'boolean'),
                        new OA\Property(property: 'cloudflare_http_tunnel_id', type: 'string', nullable: true),
                        new OA\Property(property: 'cloudflare_http_tunnel_cname', type: 'string', nullable: true),
                        new OA\Property(property: 'cloudflare_http_tunnel_hostname', type: 'string', nullable: true),
                        new OA\Property(property: 'cloudflare_dashboard_hostname', type: 'string', nullable: true),
                        new OA\Property(property: 'ip', type: 'string'),
                        new OA\Property(property: 'ip_previous', type: 'string', nullable: true),
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
        summary: 'Update Cloudflare Tunnel settings',
        description: 'Update stored Cloudflare Tunnel settings for a server. Does not run remote cloudflared configuration; use enable/disable for the manual UI actions.',
        path: '/servers/{uuid}/cloudflare-tunnel',
        operationId: 'update-server-cloudflare-tunnel',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'is_cloudflare_tunnel', type: 'boolean'),
                    new OA\Property(property: 'is_cloudflare_http_tunnel', type: 'boolean'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated Cloudflare Tunnel settings.'),
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

        if ($server->isLocalhost() && $request->exists('is_cloudflare_tunnel')) {
            return response()->json(['message' => 'Cloudflare Tunnel cannot be configured on the localhost server.'], 422);
        }

        $validator = customApiValidator($request->all(), [
            'is_cloudflare_tunnel' => 'sometimes|boolean',
            'is_cloudflare_http_tunnel' => 'sometimes|boolean',
        ]);
        $extraFields = array_diff(array_keys($request->all()), self::ALLOWED_FIELDS);
        if ($validator->fails() || ! empty($extraFields) || (! $request->exists('is_cloudflare_tunnel') && ! $request->exists('is_cloudflare_http_tunnel'))) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }
            if (! $request->exists('is_cloudflare_tunnel') && ! $request->exists('is_cloudflare_http_tunnel')) {
                $errors->add('is_cloudflare_tunnel', 'Provide is_cloudflare_tunnel and/or is_cloudflare_http_tunnel.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        if ($request->exists('is_cloudflare_tunnel')) {
            $enabled = $request->boolean('is_cloudflare_tunnel');
            $server->settings->is_cloudflare_tunnel = $enabled;
            if (! $enabled && $server->ip_previous) {
                $server->update(['ip' => $server->ip_previous]);
            }
        }

        if ($request->exists('is_cloudflare_http_tunnel')) {
            $server->settings->is_cloudflare_http_tunnel = $request->boolean('is_cloudflare_http_tunnel');
        }

        $server->settings->save();

        auditLog('api.server.cloudflare_tunnel.updated', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'is_cloudflare_tunnel' => (bool) $server->settings->is_cloudflare_tunnel,
            'is_cloudflare_http_tunnel' => (bool) $server->settings->is_cloudflare_http_tunnel,
        ]);

        return response()->json($this->transform($server->refresh()));
    }

    #[OA\Post(
        summary: 'Enable Cloudflare Tunnel (manual)',
        description: 'Manually mark Cloudflare Tunnel as enabled for a server (matches UI manual enable). Does not deploy cloudflared remotely.',
        path: '/servers/{uuid}/cloudflare-tunnel/enable',
        operationId: 'enable-server-cloudflare-tunnel',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cloudflare Tunnel enabled.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function enable(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = $this->findServerForTeam($teamId, $request->uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        if ($server->isLocalhost()) {
            return response()->json(['message' => 'Cloudflare Tunnel cannot be configured on the localhost server.'], 422);
        }

        $server->settings->is_cloudflare_tunnel = true;
        $server->settings->save();

        auditLog('api.server.cloudflare_tunnel.enabled', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
        ]);

        return response()->json([
            'message' => 'Cloudflare Tunnel enabled.',
            ...$this->transform($server->refresh()),
        ]);
    }

    #[OA\Post(
        summary: 'Disable Cloudflare Tunnel',
        description: 'Mark Cloudflare Tunnel as disabled and restore ip_previous when available. Does not remove the remote cloudflared container.',
        path: '/servers/{uuid}/cloudflare-tunnel/disable',
        operationId: 'disable-server-cloudflare-tunnel',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cloudflare Tunnel disabled.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function disable(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = $this->findServerForTeam($teamId, $request->uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        if ($server->isLocalhost()) {
            return response()->json(['message' => 'Cloudflare Tunnel cannot be configured on the localhost server.'], 422);
        }

        $server->settings->is_cloudflare_tunnel = false;
        $server->settings->save();

        $message = 'Cloudflare Tunnel disabled.';
        if ($server->ip_previous) {
            $server->update(['ip' => $server->ip_previous]);
            $message .= ' Server IP restored to its previous IP address.';
        } else {
            $message .= ' Action required: Update the server IP address to its real IP address if needed.';
        }

        auditLog('api.server.cloudflare_tunnel.disabled', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
        ]);

        return response()->json([
            'message' => $message,
            ...$this->transform($server->refresh()),
        ]);
    }
}
