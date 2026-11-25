<?php

namespace App\Http\Controllers\V5\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

trait HandlesIngressSyncErrors
{
    protected function ingressSyncErrorResponse(\RuntimeException $exception): JsonResponse
    {
        return response()->json([
            'message' => $this->friendlyIngressSyncError($exception->getMessage()),
            'detail' => $exception->getMessage(),
        ], 502);
    }

    protected function friendlyIngressSyncError(string $message): string
    {
        $normalized = Str::lower($message);

        if (str_contains($normalized, 'invalid http response') || str_contains($normalized, 'could not talk to flux')) {
            return 'Could not reach Flux. Check that Flux is running in the Coolify container and try again.';
        }

        if (str_contains($normalized, 'dispatch timeout') || str_contains($normalized, 'timed out')) {
            return 'coold did not respond in time. Check that the server agent is running and connected to Flux.';
        }

        if (str_contains($normalized, 'validate caddyfile')) {
            return 'Caddy rejected the generated ingress configuration. Check the domains and internal port, then try again.';
        }

        if (str_contains($normalized, 'start caddy ingress') || str_contains($normalized, 'reload caddy ingress')) {
            return 'Could not start Caddy ingress on the server. Check that Podman is running and port 80 is available.';
        }

        return 'Could not update ingress. Check Flux and coold logs, then try again.';
    }
}
