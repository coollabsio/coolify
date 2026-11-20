<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\EnvironmentCanvas;
use App\Services\ProjectCanvas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Project canvas: the resources of every environment of one project (or of every project).
 * Moves of cards and links are saved through EnvironmentCanvasController; the projects canvas saves the place of a project here. Called by resources/js/environment-canvas.js through the /canvas/api session bridge.
 */
class ProjectCanvasController extends Controller
{
    private const MAX_POSITIONS = 1000;

    public function __construct(private ProjectCanvas $canvas) {}

    public function index(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        return response()->json($this->canvas->teamPayload((int) $teamId, (bool) $request->attributes->get('can_read_sensitive', false), $this->canEdit($request, (int) $teamId)));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $project = Project::query()->whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $this->authorize('view', $project);

        return response()->json($this->canvas->payload($project, (bool) $request->attributes->get('can_read_sensitive', false), $this->canEdit($request, (int) $project->team_id)));
    }

    /**
     * Saves the place of project zones on the projects canvas (top-left of each zone), keyed by project UUID.
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

        // Projects of other teams are left out, like unknown UUIDs.
        $projects = Project::query()->whereTeamId($teamId)->whereIn('uuid', array_keys($request->input('positions')))->get();
        foreach ($projects as $project) {
            $this->authorize('update', $project);
        }

        $saved = [];
        foreach ($projects as $project) {
            $position = $request->input("positions.{$project->uuid}");
            $project->canvas_position = $saved[$project->uuid] = ['x' => (int) $position['x'], 'y' => (int) $position['y']];
            $project->save();
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
}
