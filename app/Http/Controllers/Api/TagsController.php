<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TagsController extends Controller
{
    public static function serializeTag(Tag $tag): array
    {
        return [
            'uuid' => $tag->uuid,
            'name' => $tag->name,
            'created_at' => $tag->created_at,
            'updated_at' => $tag->updated_at,
        ];
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all tags for the current team.',
        path: '/tags',
        operationId: 'list-tags',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Tags'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'All tags for the current team.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Tag')
                        )
                    ),
                ]
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
        ]
    )]
    public function tags(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $tags = Tag::where('team_id', $teamId)->orderBy('name')->get();

        return response()->json($tags->map(self::serializeTag(...)));
    }
}
