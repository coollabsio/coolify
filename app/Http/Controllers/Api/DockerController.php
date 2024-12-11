<?php

namespace App\Http\Controllers\Api;

use App\Actions\Docker\DeleteServerDockerImages;
use App\Actions\Docker\GetServerDockerImageDetails;
use App\Actions\Docker\ListServerDockerImages;
use App\Actions\Docker\UpdateServerDockerImageTag;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;

class DockerController extends Controller
{
    public function list_server_docker_images($server_uuid, Request $request,)
    {

        $query = Server::query();

        $server  = $query->where('uuid', $server_uuid)->first();
        if (!$server) {
            return response()->json(['error' => 'server not found'], 404);
        }

        $isReachable = (bool) $server->settings->is_reachable;
        // If the server is reachable, send the reachable notification if it was sent before
        if ($isReachable !== true) {
            return response()->json(['error' => 'server is not reachable.'], 403);
        }

        $filter = $request->input('filter', 'all'); // Default to 'all' if no filter is provided

        // Validate filter
        if (!in_array($filter, ['all', 'dangling'])) {
            return response()->json(['error' => 'Invalid filter. Allowed values are: all, dangling.'], 400);
        }

        return ListServerDockerImages::run($server, $filter);
    }

    public function get_server_docker_image_details($server_uuid, $id)
    {
        $query = Server::query();

        $server  = $query->where('uuid', $server_uuid)->first();
        if (!$server) {
            return response()->json(['error' => 'server not found'], 404);
        }

        $isReachable = (bool) $server->settings->is_reachable;
        // If the server is reachable, send the reachable notification if it was sent before
        if ($isReachable !== true) {
            return response()->json(['error' => 'server is not reachable.'], 403);
        }

        return response()->json(GetServerDockerImageDetails::run($server, $id));
    }

    public function update_server_docker_image_tag($server_uuid, $id, Request $request)
    {
        $query = Server::query();

        $server  = $query->where('uuid', $server_uuid)->first();
        if (!$server) {
            return response()->json(['error' => 'server not found'], 404);
        }

        $isReachable = (bool) $server->settings->is_reachable;
        // If the server is reachable, send the reachable notification if it was sent before
        if ($isReachable !== true) {
            return response()->json(['error' => 'server is not reachable.'], 403);
        }

        $validatedData = $request->validate([
            'tag' => 'required|string|min:1'
        ]);

        return response()->json(UpdateServerDockerImageTag::run($server, $id, $validatedData['tag']));
    }

    public function delete_server_docker_images($server_uuid, Request $request)
    {
        $query = Server::query();

        $server  = $query->where('uuid', $server_uuid)->first();
        if (!$server) {
            return response()->json(['error' => 'server not found'], 404);
        }

        $isReachable = (bool) $server->settings->is_reachable;
        // If the server is reachable, send the reachable notification if it was sent before
        if ($isReachable !== true) {
            return response()->json(['error' => 'server is not reachable.'], 403);
        }

        $validatedData = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['string', 'distinct'],
        ]);

        $message = DeleteServerDockerImages::run($server, $validatedData['ids']);

        return response()->json(['message' => $message]);
    }
}
