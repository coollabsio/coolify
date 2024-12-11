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
        if (request()->attributes->get('can_read_sensitive', false) === false) {
            $team->makeHidden([
                'smtp_username',
                'smtp_password',
                'resend_api_key',
                'telegram_token',
            ]);
        }

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
        $teams = auth()->user()->teams->sortBy('id');
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
        $id = $request->id;
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $teams = auth()->user()->teams;
        $team = $teams->where('id', $id)->first();
        if (is_null($team)) {
            return response()->json(['message' => 'Team not found.'], 404);
        }
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
        $id = $request->id;
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $teams = auth()->user()->teams;
        $team = $teams->where('id', $id)->first();
        if (is_null($team)) {
            return response()->json(['message' => 'Team not found.'], 404);
        }
        $members = $team->members;
        $members->makeHidden([
            'pivot',
        ]);

        return response()->json(
            serializeApiResponse($members),
        );
    }

    #[OA\Get(
        summary: 'Authenticated Team',
        description: 'Get currently authenticated team.',
        path: '/teams/current',
        operationId: 'get-current-team',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Teams'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Current Team.',
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
        $team = auth()->user()->currentTeam();

        return response()->json(
            $this->removeSensitiveData($team),
        );
    }

    #[OA\Get(
        summary: 'Authenticated Team Members',
        description: 'Get currently authenticated team members.',
        path: '/teams/current/members',
        operationId: 'get-current-team-members',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Teams'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Currently authenticated team members.',
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
        $team = auth()->user()->currentTeam();
        $team->members->makeHidden([
            'pivot',
        ]);

        return response()->json(
            serializeApiResponse($team->members),
        );
    }
}
