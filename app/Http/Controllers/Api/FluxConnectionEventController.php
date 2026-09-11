<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sentinel\ResolveFluxPublicUrl;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class FluxConnectionEventController extends Controller
{
    public function __invoke(Request $request, ResolveFluxPublicUrl $resolveFluxPublicUrl): Response
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);

        $expectedToken = config('constants.flux.internal_token');
        $providedToken = $request->bearerToken();
        if (! is_string($expectedToken) || $expectedToken === '' || ! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
            abort(401);
        }

        $validator = Validator::make($request->all(), [
            'event' => ['required', 'in:connected,heartbeat,disconnected'],
            'server_id' => ['required', 'string', 'max:255'],
            'connection_id' => ['required', 'uuid'],
            'sentinel_version' => ['required_if:event,connected', 'nullable', 'string', 'max:100'],
            'protocol_version' => ['required_if:event,connected', 'nullable', 'integer', 'min:1'],
            'observed_at_unix_ms' => ['nullable', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) {
            abort(422, $validator->errors()->first());
        }
        $data = $validator->validated();
        $server = Server::query()->where('uuid', $data['server_id'])->firstOrFail();
        $key = "flux:connection:{$server->uuid}";
        $current = Cache::get($key, []);

        if ($data['event'] === 'disconnected') {
            if (data_get($current, 'connection_id') === $data['connection_id']) {
                Cache::forget($key);
            }

            return response()->noContent();
        }

        if ($data['event'] === 'heartbeat' && data_get($current, 'connection_id') !== $data['connection_id']) {
            return response()->noContent();
        }

        $fluxUrl = $resolveFluxPublicUrl->resolve();
        Cache::put($key, array_filter([
            'status' => 'connected',
            'connection_id' => $data['connection_id'],
            'sentinel_version' => $data['sentinel_version'] ?? data_get($current, 'sentinel_version'),
            'protocol_version' => $data['protocol_version'] ?? data_get($current, 'protocol_version'),
            'connected_at' => $data['event'] === 'connected' ? now()->toIso8601String() : data_get($current, 'connected_at'),
            'last_heartbeat_at' => $data['event'] === 'heartbeat' ? now()->toIso8601String() : data_get($current, 'last_heartbeat_at'),
            'transport' => str_starts_with($fluxUrl, 'https://') ? 'tls' : 'plaintext',
            'endpoint' => $fluxUrl,
        ], fn ($value) => $value !== null), now()->addMinutes(5));

        return response()->noContent();
    }
}
