<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ResourceLink;
use App\Services\EnvironmentCanvas;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Environment canvas: a view of one environment's resources with saved card positions and visual-only links.
 */
class EnvironmentCanvasController extends Controller
{
    private const MAX_POSITIONS = 1000;

    public function __construct(private EnvironmentCanvas $canvas) {}

    public function show(Request $request): JsonResponse
    {
        $environment = $this->findEnvironment($request);
        if ($environment instanceof JsonResponse) {
            return $environment;
        }
        $this->authorize('view', $environment);

        $user = $request->user();
        $token = $user->currentAccessToken();
        $canEdit = $user->isAdminOfTeam((int) $environment->project->team_id)
            && ($token->can('root') || $token->can('write'));

        return response()->json($this->canvas->payload(
            $environment,
            canReadSensitive: (bool) $request->attributes->get('can_read_sensitive', false),
            canEdit: $canEdit,
        ));
    }

    public function updatePositions(Request $request): JsonResponse
    {
        $environment = $this->findEnvironment($request);
        if ($environment instanceof JsonResponse) {
            return $environment;
        }
        $this->authorize('update', $environment);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $validator = Validator::make($request->all(), [
            'positions' => ['present', 'array', 'max:'.self::MAX_POSITIONS],
        ]);
        if ($response = $this->validationFailedResponse($request, $validator, ['positions'])) {
            return $response;
        }

        $nodeUuids = $this->canvas->resourceIndex($environment)->keys()->all();
        $positions = collect($request->input('positions'))->only($nodeUuids)->all();

        $coordinateRule = ['required', 'integer', 'between:-'.EnvironmentCanvas::MAX_COORDINATE.','.EnvironmentCanvas::MAX_COORDINATE];
        $validator = Validator::make(['positions' => $positions], [
            'positions.*' => ['required', 'array'],
            'positions.*.x' => $coordinateRule,
            'positions.*.y' => $coordinateRule,
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $saved = DB::transaction(function () use ($environment, $positions, $nodeUuids) {
            $locked = Environment::query()->lockForUpdate()->findOrFail($environment->id);
            $merged = $this->canvas->filterPositions(
                array_merge($locked->canvas_positions ?? [], $positions),
                $nodeUuids,
            );
            $locked->canvas_positions = $merged;
            $locked->save();

            return $merged;
        });

        return response()->json(['positions' => (object) $saved]);
    }

    public function storeLink(Request $request): JsonResponse
    {
        $environment = $this->findEnvironment($request);
        if ($environment instanceof JsonResponse) {
            return $environment;
        }
        $this->authorize('update', $environment);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $validator = Validator::make($request->all(), [
            'source' => ['required', 'string', 'max:255'],
            'target' => ['required', 'string', 'max:255', 'different:source'],
            'label' => ['nullable', 'string', 'max:64'],
        ]);
        if ($response = $this->validationFailedResponse($request, $validator, ['source', 'target', 'label'])) {
            return $response;
        }

        $resources = $this->canvas->resourceIndex($environment);
        $source = $resources->get($request->input('source'));
        $target = $resources->get($request->input('target'));
        $errors = [];
        if (! $source) {
            $errors['source'] = ['The source must be a resource of this environment.'];
        }
        if (! $target) {
            $errors['target'] = ['The target must be a resource of this environment.'];
        }
        if ($errors !== []) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $attributes = [
            'environment_id' => $environment->id,
            'source_type' => $source['type'],
            'source_id' => $source['id'],
            'target_type' => $target['type'],
            'target_id' => $target['id'],
        ];
        $duplicate = ['message' => 'Validation failed.', 'errors' => ['target' => ['These resources are already linked.']]];
        if (ResourceLink::query()->where($attributes)->exists()) {
            return response()->json($duplicate, 422);
        }

        try {
            $link = ResourceLink::create($attributes + [
                'label' => $this->normalizeLabel($request->input('label')),
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json($duplicate, 422);
        }

        return response()->json(
            $this->canvas->linkPayload($link, $request->input('source'), $request->input('target')),
            201,
        );
    }

    public function updateLink(Request $request): JsonResponse
    {
        $environment = $this->findEnvironment($request);
        if ($environment instanceof JsonResponse) {
            return $environment;
        }
        $this->authorize('update', $environment);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $validator = Validator::make($request->all(), [
            'label' => ['present', 'nullable', 'string', 'max:64'],
        ]);
        if ($response = $this->validationFailedResponse($request, $validator, ['label'])) {
            return $response;
        }

        $link = $environment->resourceLinks()->whereUuid($request->route('link_uuid'))->first();
        if (! $link) {
            return response()->json(['message' => 'Link not found.'], 404);
        }
        $resources = $this->canvas->resourceIndex($environment);
        $uuidOf = fn (string $type, int $id) => $resources->search(fn (array $resource) => $resource['type'] === $type && $resource['id'] === $id);
        $sourceUuid = $uuidOf($link->source_type, (int) $link->source_id);
        $targetUuid = $uuidOf($link->target_type, (int) $link->target_id);
        if ($sourceUuid === false || $targetUuid === false) {
            return response()->json(['message' => 'Link not found.'], 404);
        }

        $link->update(['label' => $this->normalizeLabel($request->input('label'))]);

        return response()->json($this->canvas->linkPayload($link, $sourceUuid, $targetUuid));
    }

    public function destroyLink(Request $request): JsonResponse
    {
        $environment = $this->findEnvironment($request);
        if ($environment instanceof JsonResponse) {
            return $environment;
        }
        $this->authorize('update', $environment);

        $link = $environment->resourceLinks()->whereUuid($request->route('link_uuid'))->first();
        if (! $link) {
            return response()->json(['message' => 'Link not found.'], 404);
        }
        $link->delete();

        return response()->json(['message' => 'Link deleted.']);
    }

    /**
     * The environment of the request, within a project of the token's team.
     */
    private function findEnvironment(Request $request): Environment|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->route('uuid'))->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $environmentNameOrUuid = $request->route('environment_name_or_uuid');
        $environment = $project->environments()->whereName($environmentNameOrUuid)->first()
            ?? $project->environments()->whereUuid($environmentNameOrUuid)->first();
        if (! $environment) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }
        $environment->setRelation('project', $project);

        return $environment;
    }

    /**
     * @param  array<int, string>  $allowedFields
     */
    private function validationFailedResponse(Request $request, ValidatorContract $validator, array $allowedFields): ?JsonResponse
    {
        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if (! $validator->fails() && empty($extraFields)) {
            return null;
        }

        $errors = $validator->errors();
        foreach ($extraFields as $field) {
            $errors->add($field, 'This field is not allowed.');
        }

        return response()->json([
            'message' => 'Validation failed.',
            'errors' => $errors,
        ], 422);
    }

    private function normalizeLabel(mixed $label): ?string
    {
        $label = trim((string) $label);

        return $label === '' ? null : $label;
    }
}
