<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CloudInitScript;
use App\Rules\ValidCloudInitYaml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CloudInitScriptsController extends Controller
{
    private function removeSensitiveData(CloudInitScript $script): array
    {
        $script->makeHidden(['id', 'team_id']);

        if (request()->attributes->get('can_read_sensitive', false) === true) {
            $script->makeVisible(['script']);
        }

        return serializeApiResponse($script)->all();
    }

    #[OA\Get(
        summary: 'List Cloud-init Scripts',
        description: 'List all cloud-init scripts for the authenticated team.',
        path: '/cloud-init-scripts',
        operationId: 'list-cloud-init-scripts',
        security: [['bearerAuth' => []]],
        tags: ['Cloud-init Scripts'],
        responses: [
            new OA\Response(response: 200, description: 'Cloud-init scripts for the team.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('viewAny', CloudInitScript::class);

        $scripts = CloudInitScript::where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CloudInitScript $script) => $this->removeSensitiveData($script));

        return response()->json($scripts);
    }

    #[OA\Post(
        summary: 'Create Cloud-init Script',
        description: 'Create a new cloud-init script for the authenticated team.',
        path: '/cloud-init-scripts',
        operationId: 'create-cloud-init-script',
        security: [['bearerAuth' => []]],
        tags: ['Cloud-init Scripts'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'script'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'script', type: 'string', description: 'Bash script (#!) or cloud-config YAML.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Cloud-init script created.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', CloudInitScript::class);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'name' => 'required|string|max:255',
            'script' => ['required', 'string', new ValidCloudInitYaml],
        ]);
        $extraFields = array_diff(array_keys($request->all()), ['name', 'script']);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $script = CloudInitScript::create([
            'team_id' => $teamId,
            'name' => $request->string('name')->toString(),
            'script' => $request->string('script')->toString(),
        ]);

        auditLog('api.cloud_init_script.created', [
            'team_id' => $teamId,
            'cloud_init_script_uuid' => $script->uuid,
            'cloud_init_script_name' => $script->name,
        ]);

        return response()->json($this->removeSensitiveData($script), 201);
    }

    #[OA\Get(
        summary: 'Get Cloud-init Script',
        description: 'Get a cloud-init script by UUID.',
        path: '/cloud-init-scripts/{uuid}',
        operationId: 'get-cloud-init-script-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cloud-init Scripts'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cloud-init script.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $script = CloudInitScript::where('team_id', $teamId)->where('uuid', $request->route('uuid'))->first();
        if (! $script) {
            return response()->json(['message' => 'Cloud-init script not found.'], 404);
        }

        $this->authorize('view', $script);

        return response()->json($this->removeSensitiveData($script));
    }

    #[OA\Patch(
        summary: 'Update Cloud-init Script',
        description: 'Update a cloud-init script by UUID.',
        path: '/cloud-init-scripts/{uuid}',
        operationId: 'update-cloud-init-script-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cloud-init Scripts'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'script', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cloud-init script updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        if ($request->all() === []) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        $script = CloudInitScript::where('team_id', $teamId)->where('uuid', $request->route('uuid'))->first();
        if (! $script) {
            return response()->json(['message' => 'Cloud-init script not found.'], 404);
        }

        $this->authorize('update', $script);

        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'script' => ['string', new ValidCloudInitYaml],
        ]);
        $extraFields = array_diff(array_keys($request->all()), ['name', 'script']);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $script->update($request->only(['name', 'script']));

        auditLog('api.cloud_init_script.updated', [
            'team_id' => $teamId,
            'cloud_init_script_uuid' => $script->uuid,
            'cloud_init_script_name' => $script->name,
            'changed_fields' => array_values(array_intersect(['name', 'script'], array_keys($request->all()))),
        ]);

        return response()->json($this->removeSensitiveData($script->fresh()));
    }

    #[OA\Delete(
        summary: 'Delete Cloud-init Script',
        description: 'Delete a cloud-init script by UUID.',
        path: '/cloud-init-scripts/{uuid}',
        operationId: 'delete-cloud-init-script-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cloud-init Scripts'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cloud-init script deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $script = CloudInitScript::where('team_id', $teamId)->where('uuid', $request->route('uuid'))->first();
        if (! $script) {
            return response()->json(['message' => 'Cloud-init script not found.'], 404);
        }

        $this->authorize('delete', $script);

        $uuid = $script->uuid;
        $name = $script->name;
        $script->delete();

        auditLog('api.cloud_init_script.deleted', [
            'team_id' => $teamId,
            'cloud_init_script_uuid' => $uuid,
            'cloud_init_script_name' => $name,
        ]);

        return response()->json(['message' => 'Cloud-init script deleted.']);
    }
}
