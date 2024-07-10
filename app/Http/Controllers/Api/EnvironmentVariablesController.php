<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnvironmentVariable;
use Illuminate\Http\Request;

class EnvironmentVariablesController extends Controller
{
    public function delete_env_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $env = EnvironmentVariable::where('uuid', $request->env_uuid)->first();
        if (! $env) {
            return response()->json([
                'message' => 'Environment variable not found.',
            ], 404);
        }
        $found_app = $env->resource()->whereRelation('environment.project.team', 'id', $teamId)->first();
        if (! $found_app) {
            return response()->json([
                'message' => 'Environment variable not found.',
            ], 404);
        }
        $env->delete();

        return response()->json([
            'message' => 'Environment variable deleted.',
        ]);
    }
}
