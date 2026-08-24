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
        $canReadSensitive = $request->attributes->get('can_read_sensitive', false) === true;
        $events = AuditEvent::query()
            ->select([
                'id',
                'team_id',
                'event',
                'source',
                'action',
                'actor_type',
                'actor_id',
                'actor_name',
                'resource_type',
                'resource_uuid',
                'resource_name',
                'description',
                'created_at',
            ])
            ->when($canReadSensitive, fn ($query) => $query->addSelect([
                'actor_email',
                'actor_token_id',
                'actor_token_name',
                'metadata',
                'ip_address',
                'user_agent',
            ]))
            ->visibleToTeam($teamId)
            ->filtered(
                search: $search,
                action: $request->string('action', 'all')->toString(),
                source: $request->string('source', 'all')->toString(),
                searchSensitiveFields: $canReadSensitive,
            )
            ->latestFirst()
            ->paginate($perPage);

        return response()->json($events);
    }
}
