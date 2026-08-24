<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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

        $validator = Validator::make($request->all(), [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'ui', 'api', 'mcp', 'webhook', 'system', 'scheduler'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);
        $search = trim((string) ($validated['search'] ?? ''));
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
                action: (string) ($validated['action'] ?? 'all'),
                source: (string) ($validated['source'] ?? 'all'),
                searchSensitiveFields: $canReadSensitive,
            )
            ->latestFirst()
            ->paginate($perPage);

        return response()->json(serializeApiResponse($events));
    }
}
