<?php

namespace App\Http\Controllers\Api;

use App\Actions\Docker\DeleteAllDanglingServerDockerImages;
use App\Actions\Docker\GetServerDockerImageDetails;
use App\Actions\Docker\ListServerDockerImages;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Support\Facades\Log;


class DockerController extends Controller
{
    public function list_server_docker_images($server_uuid)
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

        return ListServerDockerImages::run($server);
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

    public function delete_all_dangling_server_docker_images($server_uuid)
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

        $message = DeleteAllDanglingServerDockerImages::run($server);

        return response()->json(['message' => $message]);
    }
}
