<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnvironmentVariable;
use Illuminate\Http\Request;

class EnvironmentVariables extends Controller
{
    public function delete_env_by_uuid(Request $request)
    {
        ray()->clearAll();
        $teamId = get_team_id_from_token();
        $both = $request->query->get('both') ?? false;
        if (is_null($teamId)) {
            return invalid_token();
        }
        $env = EnvironmentVariable::where('uuid', $request->env_uuid)->first();
        if (! $env) {
            return response()->json([
                'success' => false,
                'message' => 'Environment variable not found.',
            ], 404);
        }
        $found_app = $env->resource()->whereRelation('environment.project.team', 'id', $teamId)->first();
        if (! $found_app) {
            return response()->json([
                'success' => false,
                'message' => 'Environment variable not found.',
            ], 404);
        }
        $env->delete();
        if ($both) {
            $found_other_pair = EnvironmentVariable::where('application_id', $found_app->id)->where('key', $env->key)->first();
            if ($found_other_pair) {
                $found_other_pair->delete();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Environment variable deleted.',
        ]);
    }
}
