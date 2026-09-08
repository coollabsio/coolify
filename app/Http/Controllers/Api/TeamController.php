<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TeamController extends Controller
{
    private function removeSensitiveData($team)
    {
        $team->makeHidden([
            'custom_server_limit',
            'pivot',
        ]);

        return serializeApiResponse($team);
    }

    #[OA\Get(
        summary: 'List',
        description: 'Get all teams.',
        path: '/teams',
        operationId: 'list-teams',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Teams'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of teams.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Team')
                        )
                    ),
                ]),
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
    public function teams(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $teams = auth()->user()->teams->where('id', $teamId)->values();
        $teams = $teams->map(function ($team) {
            return $this->removeSensitiveData($team);
        });

        return response()->json(
            $teams,
        );
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get team by TeamId.',
        path: '/teams/{id}',
        operationId: 'get-team-by-id',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Teams'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Team ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of teams.',
                content: new OA\JsonContent(ref: '#/components/schemas/Team')
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function team_by_id(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ((int) $request->id !== (int) $teamId) {
            return response()->json(['message' => 'Team not found.'], 404);
        }
        $team = auth()->user()->teams->where('id', $teamId)->first();
        if (is_null($team)) {
            return response()->json(['message' => 'Team not found.'], 404);
        }
        $this->authorize('view', $team);
        $team = $this->removeSensitiveData($team);

        return response()->json(
            serializeApiResponse($team),
        );
    }

    #[OA\Get(
        summary: 'Members',
        description: 'Get members by TeamId.',
        path: '/teams/{id}/members',
        operationId: 'get-members-by-team-id',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Teams'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Team ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of members.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/User')
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function members_by_id(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ((int) $request->id !== (int) $teamId) {
            return response()->json(['message' => 'Team not found.'], 404);
        }
        $team = auth()->user()->teams->where('id', $teamId)->first();
        if (is_null($team)) {
            return response()->json(['message' => 'Team not found.'], 404);
        }
        $this->authorize('view', $team);
        $members = $team->members;
        $members->makeHidden([
            'pivot',
            'email_change_code',
            'email_change_code_expires_at',
        ]);

        return response()->json(
            serializeApiResponse($members),
        );
    }

    #[OA\Get(
        summary: 'Authenticated Team',
        description: 'Get the team bound to the API token.',
        path: '/team',
        operationId: 'get-token-team',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Teams'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Team bound to the API token.',
                content: new OA\JsonContent(ref: '#/components/schemas/Team')),
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
    public function current_team(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $team = auth()->user()->teams->where('id', $teamId)->first();
        if (is_null($team)) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        return response()->json(
            $this->removeSensitiveData($team),
        );
    }

    #[OA\Get(
        summary: 'Authenticated Team Members',
        description: 'Get members of the team bound to the API token.',
        path: '/team/members',
        operationId: 'get-token-team-members',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Teams'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Members of the team bound to the API token.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/User')
                        )
                    ),
                ]),
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
    public function current_team_members(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $team = auth()->user()->teams->where('id', $teamId)->first();
        if (is_null($team)) {
            return response()->json(['message' => 'Team not found.'], 404);
        }
        $team->members->makeHidden([
            'pivot',
            'email_change_code',
            'email_change_code_expires_at',
        ]);

        return response()->json(
            serializeApiResponse($team->members),
        );
    }
}
