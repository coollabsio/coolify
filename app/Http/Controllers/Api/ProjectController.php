<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function projects(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $projects = Project::whereTeamId($teamId)->select('id', 'name', 'uuid')->get();

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($projects),
        ]);
    }

    public function project_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $project = Project::whereTeamId($teamId)->whereUuid(request()->uuid)->first()->load(['environments']);
        if (! $project) {
            return response()->json(['success' => false, 'message' => 'Project not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($project),
        ]);
    }

    public function environment_details(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $project = Project::whereTeamId($teamId)->whereUuid(request()->uuid)->first();
        $environment = $project->environments()->whereName(request()->environment_name)->first();
        if (! $environment) {
            return response()->json(['success' => false, 'message' => 'Environment not found.'], 404);
        }
        $environment = $environment->load(['applications', 'postgresqls', 'redis', 'mongodbs', 'mysqls', 'mariadbs', 'services']);

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($environment),
        ]);
    }
}
