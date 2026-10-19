<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class AuditEventsController extends Controller
{
    #[OA\Get(
        summary: 'List',
        description: 'List audit events of the team. Only team admins and owners can view audit events. Actor e-mail, token, metadata, changes, IP address, and user agent are returned only for tokens with the read:sensitive or root permission.',
        path: '/audit-events',
        operationId: 'list-audit-events',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Audit Events'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page.', required: false, schema: new OA\Schema(type: 'integer', default: 25, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number.', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(name: 'search', in: 'query', description: 'Search text.', required: false, schema: new OA\Schema(type: 'string', maxLength: 255)),
            new OA\Parameter(name: 'action', in: 'query', description: 'Filter by action. Use all for every action.', required: false, schema: new OA\Schema(type: 'string', maxLength: 255)),
            new OA\Parameter(name: 'source', in: 'query', description: 'Filter by source.', required: false, schema: new OA\Schema(type: 'string', enum: ['all', 'ui', 'api', 'mcp', 'webhook', 'system', 'scheduler'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of audit events, newest first.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'current_page' => ['type' => 'integer'],
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'integer'],
                                            'team_id' => ['type' => 'integer', 'nullable' => true],
                                            'event' => ['type' => 'string'],
                                            'source' => ['type' => 'string'],
                                            'action' => ['type' => 'string'],
                                            'level' => ['type' => 'string'],
                                            'actor_type' => ['type' => 'string', 'nullable' => true],
                                            'actor_id' => ['type' => 'integer', 'nullable' => true],
                                            'actor_name' => ['type' => 'string', 'nullable' => true],
                                            'resource_type' => ['type' => 'string', 'nullable' => true],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true],
                                            'resource_name' => ['type' => 'string', 'nullable' => true],
                                            'description' => ['type' => 'string', 'nullable' => true],
                                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                                        ],
                                    ],
                                ],
                                'per_page' => ['type' => 'integer'],
                                'last_page' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                            ]
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 403,
                description: 'Only team admins and owners can view audit logs.',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
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
                'level',
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
                'changes',
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
