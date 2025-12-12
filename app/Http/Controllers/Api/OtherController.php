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

    #[OA\Get(
        summary: 'Upgrade Status',
        description: 'Get the current upgrade status. Returns the step and message from the upgrade process.',
        path: '/upgrade-status',
        operationId: 'upgrade-status',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Returns upgrade status.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'in_progress'),
                        new OA\Property(property: 'step', type: 'integer', example: 3),
                        new OA\Property(property: 'message', type: 'string', example: 'Pulling Docker images'),
                        new OA\Property(property: 'timestamp', type: 'string', example: '2024-01-15T10:30:45+00:00'),
                    ]
                )),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function upgradeStatus(Request $request)
    {
        $statusFile = '/data/coolify/source/.upgrade-status';

        if (! file_exists($statusFile)) {
            return response()->json(['status' => 'none']);
        }

        $content = trim(file_get_contents($statusFile));
        if (empty($content)) {
            return response()->json(['status' => 'none']);
        }

        $parts = explode('|', $content);
        if (count($parts) < 3) {
            return response()->json(['status' => 'none']);
        }

        [$step, $message, $timestamp] = $parts;

        // Check if status is stale (older than 30 minutes)
        try {
            $statusTime = new \DateTime($timestamp);
            $now = new \DateTime;
            $diffMinutes = ($now->getTimestamp() - $statusTime->getTimestamp()) / 60;

            if ($diffMinutes > 30) {
                return response()->json(['status' => 'none']);
            }
        } catch (\Exception $e) {
            // If timestamp parsing fails, continue with the status
        }

        // Determine status based on step
        if ($step === 'error') {
            return response()->json([
                'status' => 'error',
                'step' => 0,
                'message' => $message,
                'timestamp' => $timestamp,
            ]);
        }

        $stepInt = (int) $step;
        $status = $stepInt >= 6 ? 'complete' : 'in_progress';

        return response()->json([
            'status' => $status,
            'step' => $stepInt,
            'message' => $message,
            'timestamp' => $timestamp,
        ]);
    }
}
