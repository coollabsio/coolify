<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditEventsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $request->user()->isAdminOfTeam($teamId)) {
            return response()->json(['message' => 'Only team admins and owners can view audit logs.'], 403);
        }

        $perPage = max(1, min(100, $request->integer('per_page', 25)));
        $search = trim((string) $request->query('search', ''));
        $events = AuditEvent::query()
            ->where('team_id', $teamId)
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->string('source')->toString()))
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')->toString()))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('event', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('resource_name', 'like', "%{$search}%")
                        ->orWhere('actor_name', 'like', "%{$search}%")
                        ->orWhere('actor_email', 'like', "%{$search}%");
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json($events);
    }
}
