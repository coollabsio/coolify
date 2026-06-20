<?php

namespace App\Http\Controllers\Api\Internal;

use App\Actions\V5\Flux\ApplyFluxResourceStatusUpdate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FluxResourceStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = config('flux.laravel_api_token');

        if (! is_string($token) || $token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            abort(401);
        }

        $validated = Validator::make($request->all(), [
            'resource_type' => ['required', 'string', 'max:64'],
            'team_id' => ['nullable', 'integer'],
            'application_id' => ['nullable', 'integer'],
            'resource_id' => ['nullable', 'integer'],
            'host_id' => ['nullable', 'string', 'max:255'],
            'node_id' => ['nullable', 'string', 'max:255'],
            'server_host' => ['nullable', 'string', 'max:255'],
            'server_id' => ['nullable', 'integer'],
            'host_server_id' => ['nullable', 'integer'],
            'container_id' => ['nullable', 'string', 'max:255'],
            'runtime_container_id' => ['nullable', 'string', 'max:255'],
            'container_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['required_without:state', 'string', 'max:64'],
            'state' => ['required_without:status', 'string', 'max:64'],
            'status_message' => ['nullable', 'string', 'max:1000'],
            'message' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $resource = ApplyFluxResourceStatusUpdate::run($validated);

        if ($resource === null) {
            if (($validated['resource_type'] ?? null) === 'container') {
                return response()->json([
                    'message' => 'Container status accepted.',
                ], 202);
            }

            return response()->json([
                'message' => 'No matching v5 resource was found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Resource status updated.',
        ]);
    }
}
