<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server as ModelsServer;
use Illuminate\Http\Request;

class APIServer extends Controller
{
    public function servers(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api/authentication'], 400);
        }
        $servers = ModelsServer::whereTeamId($teamId)->select('id', 'name', 'uuid', 'ip', 'user', 'port')->get()->load(['settings'])->map(function ($server) {
            $server['is_reachable'] = $server->settings->is_reachable;
            $server['is_usable'] = $server->settings->is_usable;
            return $server;
        });
        ray($servers);
        return response()->json($servers);
    }
    public function server_by_uuid(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api/authentication'], 400);
        }
        $server = ModelsServer::whereTeamId($teamId)->whereUuid(request()->uuid)->first();
        if (is_null($server)) {
            return response()->json(['error' => 'Server not found.'], 404);
        }
        $server->load(['settings']);
        $server['resources'] = $server->definedResources()->map(function ($resource) {
            $payload = [
                'id' => $resource->id,
                'uuid' => $resource->uuid,
                'name' => $resource->name,
                'type' => $resource->type(),
                'created_at' => $resource->created_at,
                'updated_at' => $resource->updated_at,
            ];
            if ($resource->type() === 'service') {
                $payload['status'] = $resource->status();
            } else {
                $payload['status'] = $resource->status;
            }
            return $payload;
        });
        return response()->json($server);
    }
}
