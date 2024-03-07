<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class Resources extends Controller
{
    public function resources(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api/authentication'], 400);
        }
        $projects = Project::where('team_id', $teamId)->get();
        $resources = collect();
        $resources->push($projects->pluck('applications')->flatten());
        $resources->push($projects->pluck('services')->flatten());
        $resources->push($projects->pluck('postgresqls')->flatten());
        $resources->push($projects->pluck('redis')->flatten());
        $resources->push($projects->pluck('mongodbs')->flatten());
        $resources->push($projects->pluck('mysqls')->flatten());
        $resources->push($projects->pluck('mariadbs')->flatten());
        $resources = $resources->flatten();
        return response()->json($resources);
    }

}
