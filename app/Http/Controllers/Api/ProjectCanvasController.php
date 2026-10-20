<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ProjectCanvas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Project canvas: the resources of every environment of one project (or of every project).
 * Moves and links are saved through EnvironmentCanvasController. Called by resources/js/environment-canvas.js through the /canvas/api session bridge.
 */
class ProjectCanvasController extends Controller
{
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
     * Same rule as the environment canvas: team admins with a write token move cards and draw links.
     */
    private function canEdit(Request $request, int $teamId): bool
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return $user->isAdminOfTeam($teamId) && ($token->can('root') || $token->can('write'));
    }
}
