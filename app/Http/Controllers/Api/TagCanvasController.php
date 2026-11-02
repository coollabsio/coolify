<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Services\TagCanvas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Tags canvas: shows which resources have which tag, and tags or untags a resource.
 * Called by resources/js/environment-canvas.js through the /canvas/api session bridge.
 */
class TagCanvasController extends Controller
{
    public function __construct(private TagCanvas $canvas) {}

    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $this->authorize('viewAny', Tag::class);

        $user = $request->user();
        $token = $user->currentAccessToken();
        $canEdit = $user->isAdminOfTeam((int) $teamId) && ($token->can('root') || $token->can('write'));

        return response()->json($this->canvas->payload((int) $teamId, $canEdit));
    }

    public function storeLink(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $validator = Validator::make($request->all(), [
            'source' => ['required', 'string', 'max:255'],
            'target' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $tag = $this->findTag((int) $teamId, $request->input('source'));
        $resource = $this->canvas->resources((int) $teamId)->get($request->input('target'));
        $errors = [];
        if (! $tag) {
            $errors['source'] = ['The source must be a tag of this team.'];
        }
        if (! $resource) {
            $errors['target'] = ['The target must be a resource of this team.'];
        }
        if ($errors !== []) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }
        $this->authorize('update', $resource);

        if ($resource->tags->contains('id', $tag->id)) {
            $message = "{$resource->name} already has the {$tag->name} tag.";

            return response()->json(['message' => $message, 'errors' => ['target' => [$message]]], 422);
        }
        $resource->tags()->attach($tag->id);

        return response()->json($this->canvas->link($tag, $resource), 201);
    }

    public function destroyLink(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        [$source, $target] = array_pad(explode('|', (string) $request->route('link_id'), 2), 2, '');
        $tag = $this->findTag((int) $teamId, $source);
        $resource = $this->canvas->resources((int) $teamId)->get($target);
        if (! $tag || ! $resource || ! $resource->tags->contains('id', $tag->id)) {
            return response()->json(['message' => 'Link not found.'], 404);
        }
        $this->authorize('update', $resource);

        // The tag stays, also without resources. Delete it on the Tags page.
        $resource->tags()->detach($tag->id);

        return response()->json(['message' => 'Link removed.']);
    }

    private function findTag(int $teamId, ?string $nodeId): ?Tag
    {
        if (! str_starts_with((string) $nodeId, 'tag:')) {
            return null;
        }

        return Tag::query()->where('team_id', $teamId)->where('uuid', substr($nodeId, strlen('tag:')))->first();
    }
}
