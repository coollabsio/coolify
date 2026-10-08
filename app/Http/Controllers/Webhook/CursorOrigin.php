<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhook\Concerns\MatchesManualWebhookApplications;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class CursorOrigin extends Controller
{
    use MatchesManualWebhookApplications;

    private const JWKS_URL = 'https://api.cursor.com/v1/origin/keys';

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            auditLogWebhookFailure('cursor-origin', 'invalid_signature');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $deliveryId = (string) $request->header('webhook-id');
        if (! Cache::add("cursor-origin-webhook:{$deliveryId}", true, now()->addDays(7))) {
            return response()->json(['message' => 'Webhook delivery already processed.']);
        }

        $eventType = data_get($request->json()->all(), 'event.type');
        if ($eventType !== 'repository.pushed') {
            return response()->json(['message' => "Nothing to do. Event '{$eventType}' is not supported."]);
        }

        $payload = data_get($request->json()->all(), 'event.payload');
        $repository = $this->manualWebhookRepositoryFullName(
            data_get($payload, 'repository.owner.slug').'/'.data_get($payload, 'repository.name')
        );

        if ($repository === null) {
            return response()->json(['message' => 'Nothing to do. Invalid repository.'], 422);
        }

        $results = collect(data_get($payload, 'refUpdates', []))
            ->filter(fn (mixed $update): bool => is_array($update)
                && Str::startsWith((string) data_get($update, 'ref'), 'refs/heads/')
                && ! data_get($update, 'deleted', false))
            ->flatMap(fn (array $update) => $this->deployRefUpdate($repository, $update))
            ->values();

        if ($results->contains(fn (array $result): bool => data_get($result, 'status') === 'queue_full')) {
            Cache::forget("cursor-origin-webhook:{$deliveryId}");

            return response()->json(['message' => 'Deployment queue is full.'], 429)->header('Retry-After', '60');
        }

        return response()->json($results->isEmpty()
            ? ['message' => 'Nothing to do. No matching branch updates or applications found.']
            : ['deployments' => $results]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deployRefUpdate(string $repository, array $update): array
    {
        $branch = Str::after((string) data_get($update, 'ref'), 'refs/heads/');
        $applications = $this->manualWebhookApplications(
            Application::query()->where('git_branch', $branch),
            $repository,
        );

        return $applications->map(function (Application $application) use ($repository, $update): array {
            if (! $application->isDeployable()) {
                return ['application' => $application->name, 'status' => 'skipped', 'message' => 'Deployments disabled.'];
            }

            if (! $application->destination->server->isFunctional()) {
                return ['application' => $application->name, 'status' => 'failed', 'message' => 'Server is not functional.'];
            }

            $result = queue_application_deployment(
                application: $application,
                deployment_uuid: new_public_id(),
                force_rebuild: false,
                commit: (string) data_get($update, 'after', 'HEAD'),
                is_webhook: true,
            );

            if (data_get($result, 'status') === 'queue_full') {
                return ['application' => $application->name, 'status' => 'queue_full', 'message' => data_get($result, 'message')];
            }

            auditLog('webhook.deployment.queued', [
                'provider' => 'cursor-origin',
                'application_uuid' => $application->uuid,
                'deployment_uuid' => data_get($result, 'deployment_uuid'),
                'commit' => data_get($update, 'after'),
                'repository' => $repository,
            ]);

            return [
                'application' => $application->name,
                'status' => data_get($result, 'status', 'success'),
                'message' => data_get($result, 'message', 'Deployment queued.'),
                'deployment_uuid' => data_get($result, 'deployment_uuid'),
            ];
        })->all();
    }

    private function hasValidSignature(Request $request): bool
    {
        $deliveryId = $request->header('webhook-id');
        $timestamp = $request->header('webhook-timestamp');
        $signatureHeader = $request->header('webhook-signature');

        if (! is_string($deliveryId) || ! ctype_digit((string) $timestamp) || ! is_string($signatureHeader)) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        $signature = collect(preg_split('/\s+/', $signatureHeader))
            ->first(fn (string $value): bool => Str::startsWith($value, 'v1ed,'));
        $signature = is_string($signature) ? base64_decode(Str::after($signature, 'v1ed,'), true) : false;

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        $digest = hash('sha256', "{$deliveryId}.{$timestamp}.{$request->getContent()}");

        try {
            $keys = Cache::remember('cursor-origin-jwks', now()->addHour(), fn (): array => Http::timeout(5)
                ->get(self::JWKS_URL)
                ->throw()
                ->json('keys', []));
        } catch (Throwable) {
            return false;
        }

        return collect($keys)->contains(function (mixed $key) use ($digest, $signature): bool {
            if (! is_array($key) || data_get($key, 'kty') !== 'OKP' || data_get($key, 'crv') !== 'Ed25519') {
                return false;
            }

            $publicKey = $this->decodeBase64Url(data_get($key, 'x'));

            return $publicKey !== null
                && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($signature, $digest, $publicKey);
        });
    }

    private function decodeBase64Url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
