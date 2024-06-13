<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class Team extends Controller
{
    public function teams(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $teams = auth()->user()->teams;

        return response()->json($teams);
    }

    public function team_by_id(Request $request)
    {
        $id = $request->id;
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $teams = auth()->user()->teams;
        $team = $teams->where('id', $id)->first();
        if (is_null($team)) {
            return response()->json(['error' => 'Team not found.',  'docs' => 'https://coolify.io/docs/api-reference/get-team-by-teamid'], 404);
        }

        return response()->json($team);
    }

    public function members_by_id(Request $request)
    {
        $id = $request->id;
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $teams = auth()->user()->teams;
        $team = $teams->where('id', $id)->first();
        if (is_null($team)) {
            return response()->json(['error' => 'Team not found.', 'docs' => 'https://coolify.io/docs/api-reference/get-team-by-teamid-members'], 404);
        }

        return response()->json($team->members);
    }

    public function current_team(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $team = auth()->user()->currentTeam();

        return response()->json($team);
    }

    public function current_team_members(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $team = auth()->user()->currentTeam();

        return response()->json($team->members);
    }
}
