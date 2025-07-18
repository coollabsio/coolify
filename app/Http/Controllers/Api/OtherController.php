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
                content: new OA\JsonContent(
                    type: 'string',
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
            return response()->json(['message' => 'You are not allowed to enable the API.'], 403);
        }
        $settings = instanceSettings();
        $settings->update(['is_api_enabled' => true]);

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
            return response()->json(['message' => 'You are not allowed to disable the API.'], 403);
        }
        $settings = instanceSettings();
        $settings->update(['is_api_enabled' => false]);

        return response()->json(['message' => 'API disabled.'], 200);
    }

    public function feedback(Request $request)
    {
        $content = $request->input('content');
        $webhook_url = config('constants.webhooks.feedback_discord_webhook');
        if ($webhook_url) {
            Http::post($webhook_url, [
                'content' => $content,
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
                content: new OA\JsonContent(
                    type: 'string',
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
