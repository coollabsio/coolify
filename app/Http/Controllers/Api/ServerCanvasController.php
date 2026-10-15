<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ServerCanvas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server canvas: every server of the team (or one server) with the resources on it.
 * Moves and links are saved through EnvironmentCanvasController. Called by resources/js/environment-canvas.js through the /canvas/api session bridge.
 */
class ServerCanvasController extends Controller
{
    public function __construct(private ServerCanvas $canvas) {}

    public function index(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $this->authorize('viewAny', Server::class);

        $servers = Server::query()->whereTeamId($teamId)->with('settings')->orderBy('name')->get();

        return response()->json($this->canvas->payload($teamId, $servers, null, $this->canReadSensitive($request), $this->canEdit($request, $teamId)));
    }

    /**
     * Dashboard canvas: every server of the team with its resources and their running or queued deployments.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $this->authorize('viewAny', Server::class);

        $servers = Server::query()->whereTeamId($teamId)->with('settings')->orderBy('name')->get();

        return response()->json($this->canvas->dashboardPayload($teamId, $servers, $this->canReadSensitive($request), $this->canEdit($request, $teamId)));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $server = Server::query()->whereTeamId($teamId)->whereUuid($uuid)->with('settings')->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        $this->authorize('view', $server);

        return response()->json($this->canvas->payload($teamId, collect([$server]), $server, $this->canReadSensitive($request), $this->canEdit($request, $teamId)));
    }

    /**
     * Same rule as the environment canvas: team admins with a write token move cards and draw links.
     */
    private function canEdit(Request $request, int $teamId): bool
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return $user->isAdminOfTeam($teamId) && ($token->can('root') || $token->can('write'));
    }

    /**
     * Unmanaged Docker containers of one server, as read-only cards for its canvas.
     */
    public function containers(string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $server = Server::query()->whereTeamId($teamId)->whereUuid($uuid)->with('settings')->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        $this->authorize('view', $server);

        return response()->json(['nodes' => $this->canvas->unmanagedContainerNodes($server)->all()]);
    }

    /**
     * Latest CPU, memory and disk usage of one server for its zone header on the canvas.
     */
    public function usage(string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $server = Server::query()->whereTeamId($teamId)->whereUuid($uuid)->with('settings')->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        $this->authorize('view', $server);
        if (! $server->settings->is_metrics_enabled) {
            return response()->json(['cpu' => null, 'memory' => null, 'disk' => null]);
        }

        return response()->json($this->canvas->usage($server));
    }

    private function canReadSensitive(Request $request): bool
    {
        return (bool) $request->attributes->get('can_read_sensitive', false);
    }
}
