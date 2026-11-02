<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\EnvironmentCanvas;
use App\Services\ServerCanvas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Server canvas: every server of the team (or one server) with the resources on it.
 * Moves of cards and links are saved through EnvironmentCanvasController; the servers and dashboard canvases save the place of a server here. Called by resources/js/environment-canvas.js through the /canvas/api session bridge.
 */
class ServerCanvasController extends Controller
{
    private const MAX_POSITIONS = 1000;

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
     * Saves the place of server zones on the servers and dashboard canvases (top-left of each zone), keyed by server UUID.
     */
    public function updatePositions(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $coordinateRule = ['required', 'integer', 'between:-'.EnvironmentCanvas::MAX_COORDINATE.','.EnvironmentCanvas::MAX_COORDINATE];
        $validator = Validator::make($request->all(), [
            'positions' => ['required', 'array', 'max:'.self::MAX_POSITIONS],
            'positions.*' => ['required', 'array'],
            'positions.*.x' => $coordinateRule,
            'positions.*.y' => $coordinateRule,
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Servers of other teams are left out, like unknown UUIDs (for example the zone of resources without a server).
        $servers = Server::query()->whereTeamId($teamId)->whereIn('uuid', array_keys($request->input('positions')))->get();
        foreach ($servers as $server) {
            $this->authorize('update', $server);
        }

        $saved = [];
        foreach ($servers as $server) {
            $position = $request->input("positions.{$server->uuid}");
            $server->canvas_position = $saved[$server->uuid] = ['x' => (int) $position['x'], 'y' => (int) $position['y']];
            $server->save();
        }

        return response()->json(['positions' => (object) $saved]);
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
