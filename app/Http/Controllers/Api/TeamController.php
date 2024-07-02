<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function teams(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $teams = auth()->user()->teams;

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($teams),
        ]);
    }

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
            return response()->json(['success' => false, 'message' => 'Team not found.',  'docs' => 'https://coolify.io/docs/api-reference/get-team-by-teamid'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($team),
        ]);
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
            return response()->json(['success' => false, 'message' => 'Team not found.', 'docs' => 'https://coolify.io/docs/api-reference/get-team-by-teamid-members'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($team->members),
        ]);
    }

    public function current_team(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $team = auth()->user()->currentTeam();

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($team),
        ]);
    }

    public function current_team_members(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $team = auth()->user()->currentTeam();

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($team->members),
        ]);
    }
}
