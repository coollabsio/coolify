<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TeamController extends Controller
{
    private function removeSensitiveData($team)
    {
        $token = auth()->user()->currentAccessToken();
        $team->makeHidden([
            'custom_server_limit',
            'pivot',
        ]);
        if ($token->can('view:sensitive')) {
            return serializeApiResponse($team);
        }
        $team->makeHidden([
            'smtp_username',
            'smtp_password',
            'resend_api_key',
            'telegram_token',
        ]);

        return serializeApiResponse($team);
    }

    #[OA\Get(path: '/teams')]
    #[OA\Response(response: '200', description: 'List of teams')]
    #[OA\Response(response: '401', description: 'Unauthorized')]
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

    #[OA\Get(path: '/teams/{id}')]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
            ]
        )
    )]
    #[OA\Response(response: '404', description: 'Team not found')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Team ID', schema: new OA\Schema(type: 'integer'))]
    // response 200 with team model
    #[OA\Response(
        response: 200,
        description: 'Team model',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'name', type: 'string', example: 'Team 1'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2021-10-10T10:00:00Z'),
                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2021-10-10T10:00:00Z'),
            ]
        )
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
            return response()->json(['message' => 'Team not found.',  'docs' => 'https://coolify.io/docs/api-reference/get-team-by-teamid'], 404);
        }
        $team = $this->removeSensitiveData($team);

        return response()->json(
            serializeApiResponse($team),
        );
    }

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
            return response()->json(['message' => 'Team not found.', 'docs' => 'https://coolify.io/docs/api-reference/get-team-by-teamid-members'], 404);
        }
        $members = $team->members;
        $members->makeHidden([
            'pivot',
        ]);

        return response()->json(
            serializeApiResponse($members),
        );
    }

    public function current_team(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $team = auth()->user()->currentTeam();

        return response()->json(
            serializeApiResponse($team),
        );
    }

    public function current_team_members(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $team = auth()->user()->currentTeam();

        return response()->json(
            serializeApiResponse($team->members),
        );
    }
}
