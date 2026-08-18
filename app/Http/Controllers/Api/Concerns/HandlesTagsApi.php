<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Controllers\Api\TagsController;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait HandlesTagsApi
{
    /**
     * Find the taggable resource by UUID within the team.
     */
    abstract protected function findTaggableResource(string $uuid, int|string $teamId): mixed;

    /**
     * Get the 404 message for the taggable resource.
     */
    abstract protected function tagResourceNotFoundMessage(): string;

    public function listTags(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $resource = $this->findTaggableResource($request->route('uuid'), $teamId);
        if (! $resource) {
            return response()->json(['message' => $this->tagResourceNotFoundMessage()], 404);
        }

        $this->authorize('view', $resource);

        return response()->json($resource->tags->map(TagsController::serializeTag(...)));
    }

    public function createTag(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $resource = $this->findTaggableResource($request->route('uuid'), $teamId);
        if (! $resource) {
            return response()->json(['message' => $this->tagResourceNotFoundMessage()], 404);
        }

        $this->authorize('update', $resource);

        if ($request->has('tag_name') && $request->has('tag_names')) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['tag_name' => ['Provide either tag_name or tag_names, not both.']],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'tag_name' => 'required_without:tag_names|string',
            'tag_names' => 'required_without:tag_name|array|min:1',
            'tag_names.*' => 'string',
        ]);

        $extraFields = array_diff(array_keys($request->all()), ['tag_name', 'tag_names']);
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

        $tagNames = $this->normalizeTagNames($request->has('tag_names') ? $request->tag_names : [$request->tag_name]);
        $invalidTags = array_filter($tagNames, fn (string $tagName): bool => mb_strlen($tagName) < 2);
        if (! empty($invalidTags)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['tag_name' => ['Each tag name must be at least 2 characters after sanitization.']],
            ], 422);
        }

        $this->attachTagsToResource($resource, $tagNames, $teamId);

        return response()->json($resource->refresh()->tags->map(TagsController::serializeTag(...)))->setStatusCode(201);
    }

    public function deleteTag(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $resource = $this->findTaggableResource($request->route('uuid'), $teamId);
        if (! $resource) {
            return response()->json(['message' => $this->tagResourceNotFoundMessage()], 404);
        }

        $this->authorize('update', $resource);

        $tag = Tag::where('team_id', $teamId)->where('uuid', $request->route('tag_uuid'))->first();
        if (! $tag) {
            return response()->json(['message' => 'Tag not found.'], 404);
        }

        if (! $resource->tags()->whereKey($tag->id)->exists()) {
            return response()->json(['message' => 'Tag not found on resource.'], 404);
        }

        $resource->tags()->detach($tag->id);
        $tag->deleteIfOrphaned();

        return response()->json(['message' => 'Tag removed.']);
    }

    protected function attachTagsToResource($resource, array $tagNames, int|string $teamId): void
    {
        foreach ($this->normalizeTagNames($tagNames) as $tagName) {
            if (mb_strlen($tagName) < 2) {
                continue;
            }

            $tag = Tag::query()->createOrFirst([
                'team_id' => $teamId,
                'name' => $tagName,
            ]);

            $resource->tags()->syncWithoutDetaching([$tag->id]);
        }
    }

    protected function validateTagsParameter(Request $request): ?JsonResponse
    {
        if (! $request->has('tags')) {
            return null;
        }

        $tagNames = $this->normalizeTagNames($request->input('tags', []));
        $invalidTags = array_filter($tagNames, fn (string $tagName): bool => mb_strlen($tagName) < 2);
        if (! empty($invalidTags)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['tags' => ['Each tag name must be at least 2 characters after sanitization.']],
            ], 422);
        }

        $request->merge(['tags' => $tagNames]);

        return null;
    }

    protected function normalizeTagNames(array $tagNames): array
    {
        return collect($tagNames)
            ->map(fn ($tagName): string => strtolower(trim(strip_tags((string) $tagName))))
            ->unique()
            ->values()
            ->all();
    }
}
