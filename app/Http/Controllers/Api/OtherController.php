<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use OpenApi\Attributes as OA;

class OtherController extends Controller
{
    #[OA\Get(
        summary: 'Version',
        description: 'Get Coolify version.',
        path: '/version',
        operationId: 'version',
        security: [
            ['bearerAuth' => []],
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Returns the version of the application',
                content: new OA\MediaType(
                    mediaType: 'text/html',
                    schema: new OA\Schema(type: 'string'),
                    example: 'v4.0.0',
                )),
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
    public function version(Request $request)
    {
        return response(config('constants.coolify.version'));
    }

    #[OA\Get(
        summary: 'Enable API',
        description: 'Enable API (only with root permissions).',
        path: '/enable',
        operationId: 'enable-api',
        security: [
            ['bearerAuth' => []],
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Enable API.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'API enabled.'),
                    ]
                )),
            new OA\Response(
                response: 403,
                description: 'You are not allowed to enable the API.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'You are not allowed to enable the API.'),
                    ]
                )),
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
    public function enable_api(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ($teamId !== '0') {
            auditLog('api.instance.enable_denied', ['team_id' => $teamId], 'warning');

            return response()->json(['message' => 'You are not allowed to enable the API.'], 403);
        }
        $settings = instanceSettings();
        $settings->update(['is_api_enabled' => true]);

        auditLog('api.instance.enabled', ['team_id' => $teamId]);

        return response()->json(['message' => 'API enabled.'], 200);
    }

    #[OA\Get(
        summary: 'Disable API',
        description: 'Disable API (only with root permissions).',
        path: '/disable',
        operationId: 'disable-api',
        security: [
            ['bearerAuth' => []],
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Disable API.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'API disabled.'),
                    ]
                )),
            new OA\Response(
                response: 403,
                description: 'You are not allowed to disable the API.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'You are not allowed to disable the API.'),
                    ]
                )),
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
    public function disable_api(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ($teamId !== '0') {
            auditLog('api.instance.disable_denied', ['team_id' => $teamId], 'warning');

            return response()->json(['message' => 'You are not allowed to disable the API.'], 403);
        }
        $settings = instanceSettings();
        $settings->update(['is_api_enabled' => false]);

        auditLog('api.instance.disabled', ['team_id' => $teamId]);

        return response()->json(['message' => 'API disabled.'], 200);
    }

    #[OA\Post(
        summary: 'Enable MCP Server',
        description: 'Enable the MCP server endpoint at /mcp (only with root permissions).',
        path: '/mcp/enable',
        operationId: 'enable-mcp',
        security: [
            ['bearerAuth' => []],
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'MCP server enabled.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'MCP server enabled.'),
                    ]
                )),
            new OA\Response(
                response: 403,
                description: 'You are not allowed to enable the MCP server.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'You are not allowed to enable the MCP server.'),
                    ]
                )),
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
    public function enable_mcp(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ($teamId !== '0') {
            auditLog('api.mcp.enable_denied', ['team_id' => $teamId], 'warning');

            return response()->json(['message' => 'You are not allowed to enable the MCP server.'], 403);
        }
        $settings = instanceSettings();
        $settings->update(['is_mcp_server_enabled' => true]);

        auditLog('api.mcp.enabled', ['team_id' => $teamId]);

        return response()->json(['message' => 'MCP server enabled.'], 200);
    }

    #[OA\Post(
        summary: 'Disable MCP Server',
        description: 'Disable the MCP server endpoint at /mcp (only with root permissions).',
        path: '/mcp/disable',
        operationId: 'disable-mcp',
        security: [
            ['bearerAuth' => []],
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'MCP server disabled.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'MCP server disabled.'),
                    ]
                )),
            new OA\Response(
                response: 403,
                description: 'You are not allowed to disable the MCP server.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'You are not allowed to disable the MCP server.'),
                    ]
                )),
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
    public function disable_mcp(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ($teamId !== '0') {
            auditLog('api.mcp.disable_denied', ['team_id' => $teamId], 'warning');

            return response()->json(['message' => 'You are not allowed to disable the MCP server.'], 403);
        }
        $settings = instanceSettings();
        $settings->update(['is_mcp_server_enabled' => false]);

        auditLog('api.mcp.disabled', ['team_id' => $teamId]);

        return response()->json(['message' => 'MCP server disabled.'], 200);
    }

    public function feedback(Request $request)
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $webhook_url = config('constants.webhooks.feedback_discord_webhook');
        if ($webhook_url) {
            Http::timeout(5)->post($webhook_url, [
                'content' => $data['content'],
                'allowed_mentions' => ['parse' => []],
            ]);
        }

        return response()->json(['message' => 'Feedback sent.'], 200);
    }

    #[OA\Get(
        summary: 'Healthcheck',
        description: 'Healthcheck endpoint.',
        path: '/health',
        operationId: 'healthcheck',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Healthcheck endpoint.',
                content: new OA\MediaType(
                    mediaType: 'text/html',
                    schema: new OA\Schema(type: 'string'),
                    example: 'OK',
                )),
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
    public function healthcheck(Request $request)
    {
        return 'OK';
    }
}
