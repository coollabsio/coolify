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
                headers: [new OA\Header(header: 'X-Total-Count', ref: '#/components/headers/X-Total-Count')],
                description: 'A page of audit events, newest first. The X-Total-Count header gives the total.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'team_id', type: 'integer', nullable: true),
                                    new OA\Property(property: 'event', type: 'string'),
                                    new OA\Property(property: 'source', type: 'string'),
                                    new OA\Property(property: 'action', type: 'string'),
                                    new OA\Property(property: 'level', type: 'string'),
                                    new OA\Property(property: 'actor_type', type: 'string', nullable: true),
                                    new OA\Property(property: 'actor_id', type: 'integer', nullable: true),
                                    new OA\Property(property: 'actor_name', type: 'string', nullable: true),
                                    new OA\Property(property: 'resource_type', type: 'string', nullable: true),
                                    new OA\Property(property: 'resource_uuid', type: 'string', nullable: true),
                                    new OA\Property(property: 'resource_name', type: 'string', nullable: true),
                                    new OA\Property(property: 'description', type: 'string', nullable: true),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                ],
                            )
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
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'ui', 'api', 'mcp', 'webhook', 'system', 'scheduler'])],
        ] + apiPaginationRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $search = trim((string) ($validated['search'] ?? ''));
        $canReadSensitive = $request->attributes->get('can_read_sensitive', false) === true;
        $query = AuditEvent::query()
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
            ->latestFirst();
        [$events, $headers] = apiPaginate($query, $request, 25, alwaysPaginate: true);

        return response()->json(serializeApiResponse($events), 200, $headers);
    }
}
