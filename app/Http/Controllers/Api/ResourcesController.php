<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ResourcesController extends Controller
{
    #[OA\Get(
        summary: 'List',
        description: 'Get all resources.',
        path: '/resources',
        operationId: 'list-resources',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Resources'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all applications, services and standalone databases. The other fields of each resource are the fields of the Application, Service or Database schema.',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'uuid', type: 'string'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'description', type: 'string', nullable: true),
                            new OA\Property(property: 'type', type: 'string', description: 'application, service, or standalone-<engine> for databases (for example standalone-postgresql).'),
                            new OA\Property(property: 'status', type: 'string', description: 'For example running:healthy or exited:unhealthy.'),
                            new OA\Property(property: 'environment_id', type: 'integer'),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                        ],
                        additionalProperties: true,
                    ),
                ),
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function resources(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        // General authorization check for viewing resources - using Project as base resource type
        $this->authorize('viewAny', Project::class);

        $projects = Project::where('team_id', $teamId)->get();
        $resources = collect();
        $resources->push($projects->pluck('applications')->flatten());
        $resources->push($projects->pluck('services')->flatten());
        foreach (collect(DATABASE_TYPES) as $db) {
            $resources->push($projects->pluck(str($db)->plural(2))->flatten());
        }
        $resources = $resources->flatten();
        $resources = $resources->map(function ($resource) {
            exposeSensitiveFields($resource);
            $payload = $resource->toArray();
            $payload['status'] = $resource->status;
            $payload['type'] = $resource->type();

            return $payload;
        });

        return response()->json(serializeApiResponse($resources));
    }
}
