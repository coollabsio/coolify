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
            return invalid_token();
        }
        $projects = Project::where('team_id', $teamId)->get();
        $resources = collect();
        $resources->push($projects->pluck('applications')->flatten());
        $resources->push($projects->pluck('services')->flatten());
        foreach (collect(DATABASE_TYPES) as $db) {
            $resources->push($projects->pluck(str($db)->plural(2))->flatten());
        }
        $resources = $resources->flatten();
        $resources = $resources->map(function ($resource) {
            $payload = $resource->toArray();
            if ($resource->getMorphClass() === 'App\Models\Service') {
                $payload['status'] = $resource->status();
            } else {
                $payload['status'] = $resource->status;
            }
            $payload['type'] = $resource->type();

            return $payload;
        });

        return response()->json($resources);
    }
}
