<?php

namespace App\Http\Controllers\Api;

use App\Actions\Server\ProcessSentinelReport;
use App\Actions\Server\StopSentinel;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SentinelController extends Controller
{
    /**
     * Handle a Sentinel agent metrics push.
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
            // Tokens are encrypt(json) values, so the payload is a serialized string.
            // Unserialize it without classes: the endpoint has no login yet.
            $decrypted = unserialize(decrypt($naked_token, false), ['allowed_classes' => false]);
            if (! is_string($decrypted)) {
                throw new Exception('Sentinel token payload is not a string.');
            }
            $decrypted_token = json_decode($decrypted, true);
        } catch (Exception $e) {
            auditLogWebhookFailure('sentinel', 'decrypt_failed');

            // Usually a Sentinel of another instance or of an old APP_KEY: it cannot recover, so it waits one hour.
            return response()->json(['message' => 'Invalid token'], 401, ['Retry-After' => 3600]);
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

            // 410 and Retry-After make Sentinel wait one day before it pushes again.
            return response()->json(['message' => 'Server not found'], 410, ['Retry-After' => 86400]);
        }

        if (isCloud() && data_get($server->team->subscription, 'stripe_invoice_paid', false) === false && $server->team->id !== 0) {
            auditLogWebhookFailure('sentinel', 'subscription_unpaid', [
                'server_uuid' => $server->uuid,
                'team_id' => $server->team_id,
            ]);
            $server->rememberSentinelPushProblem('Coolify rejected the push: the subscription of this team is not active.');
            // Remove the Sentinel container of the unpaid server once a day. Server validation starts it again after payment.
            if (Cache::add("sentinel:stop-unpaid:{$server->id}", true, now()->addDay())) {
                StopSentinel::dispatch($server);
            }

            return response()->json(['message' => 'Subscription is not active'], 402, ['Retry-After' => 3600]);
        }

        if ($server->isFunctional() === false) {
            auditLogWebhookFailure('sentinel', 'server_not_functional', [
                'server_uuid' => $server->uuid,
                'team_id' => $server->team_id,
            ]);
            $server->rememberSentinelPushProblem('Coolify rejected the push: the server is marked unreachable, not usable, or disabled. Validate the server connection.');

            // SSH checks mark the server reachable again, so pushes can wait 5 minutes.
            return response()->json(['message' => 'Server is not functional'], 401, ['Retry-After' => 300]);
        }

        if (! hash_equals((string) $server->settings->sentinel_token, (string) $naked_token)) {
            auditLogWebhookFailure('sentinel', 'token_mismatch', [
                'server_uuid' => $server->uuid,
                'team_id' => $server->team_id,
            ]);
            $server->rememberSentinelPushProblem('Coolify rejected the push: the Sentinel token does not match. Sync Sentinel to apply the current token.');

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            ProcessSentinelReport::run($server, $request->all(), $request->header('X-Sentinel-Version'));
        } catch (ValidationException $e) {
            return response()->json(serializeApiResponse([
                'message' => 'Validation failed.',
                'errors' => $e->validator->errors(),
            ]), 422);
        }

        return response()->json(['message' => 'ok'], 200);
    }
}
