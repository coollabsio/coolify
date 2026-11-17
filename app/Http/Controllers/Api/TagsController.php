<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

    private function normalizeTagName(string $name): string
    {
        return strtolower(trim(strip_tags($name)));
    }

    private function validateTagWriteRequest(Request $request, array $allowedFields = ['name']): array|JsonResponse
    {
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:255',
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $name = $this->normalizeTagName((string) $request->input('name'));
        if (mb_strlen($name) < 2) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['name' => ['The tag name must be at least 2 characters after sanitization.']],
            ], 422);
        }

        return ['name' => $name];
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (string) ($exception->errorInfo[1] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062', '2067'], true);
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

    #[OA\Post(
        summary: 'Create',
        description: 'Create a tag for the current team.',
        path: '/tags',
        operationId: 'create-tag',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Tags'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 255),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Tag created.',
                content: new OA\JsonContent(ref: '#/components/schemas/Tag'),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 409, description: 'Tag with this name already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function create(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', Tag::class);

        $validated = $this->validateTagWriteRequest($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        if (Tag::where('team_id', $teamId)->where('name', $validated['name'])->exists()) {
            return response()->json(['message' => 'Tag with this name already exists.'], 409);
        }

        try {
            $tag = Tag::create([
                'name' => $validated['name'],
                'team_id' => $teamId,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return response()->json(['message' => 'Tag with this name already exists.'], 409);
            }

            throw $exception;
        }

        auditLog('api.tag.created', [
            'team_id' => $teamId,
            'tag_uuid' => $tag->uuid,
            'tag_name' => $tag->name,
        ]);

        return response()->json(self::serializeTag($tag), 201);
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update a tag name for the current team.',
        path: '/tags/{uuid}',
        operationId: 'update-tag-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Tags'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Tag UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 255),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tag updated.',
                content: new OA\JsonContent(ref: '#/components/schemas/Tag'),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Tag with this name already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validated = $this->validateTagWriteRequest($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $tag = Tag::where('team_id', $teamId)->where('uuid', $uuid)->first();
        if (! $tag) {
            return response()->json(['message' => 'Tag not found.'], 404);
        }

        $this->authorize('update', $tag);

        if ($validated['name'] !== $tag->name
            && Tag::where('team_id', $teamId)->where('name', $validated['name'])->where('id', '!=', $tag->id)->exists()) {
            return response()->json(['message' => 'Tag with this name already exists.'], 409);
        }

        try {
            $tag->update(['name' => $validated['name']]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return response()->json(['message' => 'Tag with this name already exists.'], 409);
            }

            throw $exception;
        }

        auditLog('api.tag.updated', [
            'team_id' => $teamId,
            'tag_uuid' => $tag->uuid,
            'tag_name' => $tag->name,
            'changed_fields' => ['name'],
        ]);

        return response()->json(self::serializeTag($tag->refresh()));
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete a tag for the current team. Detaches the tag from all resources via cascade.',
        path: '/tags/{uuid}',
        operationId: 'delete-tag-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Tags'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Tag UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tag deleted.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Tag deleted.'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function delete(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $tag = Tag::where('team_id', $teamId)->where('uuid', $uuid)->first();
        if (! $tag) {
            return response()->json(['message' => 'Tag not found.'], 404);
        }

        $this->authorize('delete', $tag);

        $tagUuid = $tag->uuid;
        $tagName = $tag->name;
        // taggables rows cascade-delete via FK on tag_id
        $tag->delete();

        auditLog('api.tag.deleted', [
            'team_id' => $teamId,
            'tag_uuid' => $tagUuid,
            'tag_name' => $tagName,
        ]);

        return response()->json(['message' => 'Tag deleted.']);
    }
}
