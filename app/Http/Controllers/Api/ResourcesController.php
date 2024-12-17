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
                description: 'Get all resources',
                content: new OA\JsonContent(
                    type: 'string',
                    example: 'Content is very complex. Will be implemented later.',
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
        $projects = Project::where('team_id', $teamId)->get();
        $resources = collect();
        $resources->push($projects->pluck('applications')->flatten());
        $resources->push($projects->pluck('services')->flatten());
        foreach (collect(DATABASE_TYPES) as $db) {
            $resources->push($projects->pluck(str($db)->plural(2))->flatten());
        }
        $resources = $resources->flatten();
        $resources = $resources->map(function ($resource) {
            $payload = $resource->toArray();
            $payload['status'] = $resource->status;
            $payload['type'] = $resource->type();

            return $payload;
        });

        return response()->json(serializeApiResponse($resources));
    }
}
