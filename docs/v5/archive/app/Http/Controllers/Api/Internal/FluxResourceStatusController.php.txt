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
        if (! $this->authorizedBearer($request)) {
            abort(401);
        }

        $validated = Validator::make($request->all(), [
            'resource_type' => ['required', 'string', 'max:64'],
            'team_id' => ['prohibited'],
            'application_id' => ['prohibited'],
            'resource_id' => ['prohibited'],
            'server_id' => ['prohibited'],
            'host_server_id' => ['prohibited'],
            'application_uuid' => ['nullable', 'string', 'max:255'],
            'resource_uuid' => ['nullable', 'string', 'max:255'],
            'server_uuid' => ['nullable', 'string', 'max:255'],
            'host_server_uuid' => ['nullable', 'string', 'max:255'],
            'host_id' => ['nullable', 'string', 'max:255'],
            'node_id' => ['nullable', 'string', 'max:255'],
            'server_host' => ['nullable', 'string', 'max:255'],
            'container_id' => ['nullable', 'string', 'max:255'],
            'runtime_container_id' => ['nullable', 'string', 'max:255'],
            'container_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['required_without:state', 'string', 'max:64'],
            'state' => ['required_without:status', 'string', 'max:64'],
            'status_message' => ['nullable', 'string', 'max:1000'],
            'message' => ['nullable', 'string', 'max:1000'],
            'observed_at' => ['nullable', 'string', 'date'],
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

    /**
     * Constant-time match the presented bearer token against every accepted
     * inbound token. Accepting an array (config('flux.laravel_api_tokens'),
     * falling back to the single config('flux.laravel_api_token')) lets an
     * operator rotate by serving old+new tokens simultaneously.
     *
     * SECURITY: this is still a shared global secret — every flux instance
     * presents the same token, so it cannot be scoped or revoked per-flux, and
     * a leak forces a fleet-wide rotation. The target design is per-flux,
     * individually rotatable tokens; until then the array support above is the
     * mitigation that makes rotation possible without downtime.
     */
    private function authorizedBearer(Request $request): bool
    {
        $presented = (string) $request->bearerToken();

        if ($presented === '') {
            return false;
        }

        foreach ($this->acceptedTokens() as $token) {
            if (hash_equals($token, $presented)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function acceptedTokens(): array
    {
        $tokens = config('flux.laravel_api_tokens', []);
        $tokens = is_array($tokens) ? $tokens : [];

        $single = config('flux.laravel_api_token');

        if (is_string($single) && $single !== '') {
            $tokens[] = $single;
        }

        return array_values(array_filter(
            array_map(fn ($token): string => is_string($token) ? $token : '', $tokens),
            fn (string $token): bool => $token !== ''
        ));
    }
}
