<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SentinelController extends Controller
{
    /**
     * Handle a Sentinel agent metrics push.
     *
     * Sentinel pushes its full container list on a fixed interval (default 60s),
     * even when nothing changed. To avoid dispatching one PushServerUpdateJob per
     * server per minute, the job is only dispatched when the container state hash
     * changes, or when the force window has elapsed.
     */
    public function push(Request $request)
    {
        $token = $request->header('Authorization');
        if (! $token) {
            auditLogWebhookFailure('sentinel', 'token_missing');

            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $naked_token = str_replace('Bearer ', '', $token);
        try {
            $decrypted = decrypt($naked_token);
            $decrypted_token = json_decode($decrypted, true);
        } catch (Exception $e) {
            auditLogWebhookFailure('sentinel', 'decrypt_failed');

            return response()->json(['message' => 'Invalid token'], 401);
        }
        $server_uuid = data_get($decrypted_token, 'server_uuid');
        if (! $server_uuid) {
            auditLogWebhookFailure('sentinel', 'invalid_token_payload');

            return response()->json(['message' => 'Invalid token'], 401);
        }
        $server = Server::where('uuid', $server_uuid)->first();
        if (! $server) {
            auditLogWebhookFailure('sentinel', 'server_not_found', [
                'server_uuid' => $server_uuid,
            ]);

            return response()->json(['message' => 'Server not found'], 404);
        }

        if (isCloud() && data_get($server->team->subscription, 'stripe_invoice_paid', false) === false && $server->team->id !== 0) {
            auditLogWebhookFailure('sentinel', 'subscription_unpaid', [
                'server_uuid' => $server->uuid,
                'team_id' => $server->team_id,
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($server->isFunctional() === false) {
            auditLogWebhookFailure('sentinel', 'server_not_functional', [
                'server_uuid' => $server->uuid,
                'team_id' => $server->team_id,
            ]);

            return response()->json(['message' => 'Server is not functional'], 401);
        }

        if ($server->settings->sentinel_token !== $naked_token) {
            auditLogWebhookFailure('sentinel', 'token_mismatch', [
                'server_uuid' => $server->uuid,
                'team_id' => $server->team_id,
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $validator = Validator::make($request->all(), [
            'containers' => ['present', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(serializeApiResponse([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ]), 422);
        }

        $data = $request->all();

        // Heartbeat MUST update on every push — drives isSentinelLive() and SSH-check skipping.
        $server->sentinelHeartbeat();

        if ($this->shouldDispatchUpdate($server, $data)) {
            PushServerUpdateJob::dispatch($server, $data);
        }

        auditLog('sentinel.metrics_pushed', [
            'server_uuid' => $server->uuid,
            'team_id' => $server->team_id,
        ]);

        return response()->json(['message' => 'ok'], 200);
    }

    /**
     * Decide whether PushServerUpdateJob should be dispatched for this push.
     *
     * Dispatches when: first push (no cached hash), the container state changed,
     * or the force window elapsed.
     */
    private function shouldDispatchUpdate(Server $server, array $data): bool
    {
        $hash = $this->containerStateHash($data);
        $hashKey = "sentinel:push-hash:{$server->id}";
        $forceKey = "sentinel:push-force:{$server->id}";
        $lockKey = "sentinel:push-lock:{$server->id}";

        try {
            return Cache::lock($lockKey, 10)->block(5, function () use ($hashKey, $forceKey, $hash): bool {
                $cachedHash = Cache::get($hashKey);
                $forceActive = Cache::has($forceKey);

                $shouldDispatch = $cachedHash === null || $cachedHash !== $hash || ! $forceActive;

                if ($shouldDispatch) {
                    // Day-long TTL bounds memory if a server stops pushing entirely.
                    Cache::put($hashKey, $hash, now()->addDay());
                    Cache::put($forceKey, true, config('constants.sentinel.push_force_interval_seconds', 300));
                }

                return $shouldDispatch;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * Build a stable hash of container state.
     *
     * Covers [name, state] only — metrics, filesystem_usage_root, and
     * health_status are excluded on purpose. Disk % churns constantly, and
     * health checks can flap between starting/healthy/unhealthy while the
     * container lifecycle state remains unchanged. Both would otherwise defeat
     * the hash and dispatch DB-heavy PushServerUpdateJob instances too often.
     * The force window still refreshes full state periodically. Sorted by name
     * so container ordering from Sentinel does not affect the hash.
     */
    private function containerStateHash(array $data): string
    {
        $containers = collect(data_get($data, 'containers', []))
            ->map(fn ($c) => [
                'name' => data_get($c, 'name'),
                'state' => data_get($c, 'state'),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        return hash('xxh128', json_encode($containers));
    }
}
