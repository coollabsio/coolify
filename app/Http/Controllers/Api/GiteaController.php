<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GiteaApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

class GiteaController extends Controller
{
    private function removeSensitiveData(GiteaApp $giteaApp, int $teamId): Collection
    {
        $sensitiveFields = ['client_secret', 'access_token', 'refresh_token', 'webhook_secret'];

        if (request()->attributes->get('can_read_sensitive', false) === true && $giteaApp->team_id === $teamId) {
            $giteaApp->makeVisible($sensitiveFields);
        } else {
            $giteaApp->makeHidden($sensitiveFields);
        }

        return serializeApiResponse($giteaApp);
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all Gitea apps for the current team (and system-wide sources).',
        path: '/gitea-apps',
        operationId: 'list-gitea-apps',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Gitea Apps'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of Gitea apps.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    'id' => ['type' => 'integer'],
                                    'uuid' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                    'organization' => ['type' => 'string', 'nullable' => true],
                                    'api_url' => ['type' => 'string'],
                                    'html_url' => ['type' => 'string'],
                                    'custom_user' => ['type' => 'string'],
                                    'custom_port' => ['type' => 'integer'],
                                    'client_id' => ['type' => 'string', 'nullable' => true],
                                    'redirect_uri' => ['type' => 'string', 'nullable' => true],
                                    'is_system_wide' => ['type' => 'boolean'],
                                    'is_public' => ['type' => 'boolean'],
                                    'is_connected' => ['type' => 'boolean', 'description' => 'True when the OAuth connection is complete and the source can deploy private repositories.'],
                                    'team_id' => ['type' => 'integer'],
                                ]
                            )
                        )
                    ),
                ]
            ),
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
    public function list_gitea_apps(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $giteaApps = GiteaApp::where(function ($query) use ($teamId) {
            $query->where('team_id', $teamId)
                ->orWhere('is_system_wide', true);
        })->get();

        return response()->json($giteaApps->map(
            fn (GiteaApp $giteaApp) => $this->removeSensitiveData($giteaApp, $teamId)->put('is_connected', $giteaApp->isConnected())
        ));
    }
}
