<?php

use Illuminate\Support\Facades\Log;

if (! function_exists('auditLog')) {
    /**
     * Write a security-relevant audit entry to the dedicated `audit` log channel.
     *
     * Never include secrets (private keys, passwords, tokens, webhook secrets,
     * signature header values, env-var values) in $context.
     *
     * @param  string  $event  Dot-namespaced event name, e.g. `api.private_key.created`.
     * @param  array<string, mixed>  $context  Identifiers + outcome details.
     * @param  string  $level  Log level: info | warning | error.
     */
    function auditLog(string $event, array $context = [], string $level = 'info'): void
    {
        try {
            $request = app()->bound('request') ? request() : null;
            $user = auth()->check() ? auth()->user() : null;
            $token = $user?->currentAccessToken();

            $base = [
                'event' => $event,
                'ip' => $request?->ip(),
                'ua' => substr((string) $request?->userAgent(), 0, 200),
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'team_id' => $token ? data_get($token, 'team_id') : null,
                'token_id' => $token?->id ?? null,
                'token_name' => $token?->name ?? null,
                'method' => $request?->method(),
                'path' => $request?->path(),
            ];

            $payload = array_merge($base, $context);

            Log::channel('audit')->{$level}($event, $payload);
        } catch (Throwable $e) {
            // Audit logging must never break the request path.
            try {
                Log::warning('auditLog failed: '.$e->getMessage(), ['event' => $event]);
            } catch (Throwable) {
            }
        }
    }
}

if (! function_exists('auditLogWebhookFailure')) {
    /**
     * Record a webhook signature/auth verification failure to the `audit` channel.
     */
    function auditLogWebhookFailure(string $provider, string $reason, array $context = []): void
    {
        try {
            $request = app()->bound('request') ? request() : null;

            $event = "webhook.{$provider}.signature_failed";

            $base = [
                'event' => $event,
                'reason' => $reason,
                'ip' => $request?->ip(),
                'ua' => substr((string) $request?->userAgent(), 0, 200),
                'method' => $request?->method(),
                'path' => $request?->path(),
                'event_header' => $request?->header('X-GitHub-Event')
                    ?? $request?->header('X-Gitlab-Event')
                    ?? $request?->header('X-Gitea-Event')
                    ?? $request?->header('X-Event-Key'),
            ];

            Log::channel('audit')->warning($event, array_merge($base, $context));
        } catch (Throwable $e) {
            try {
                Log::warning('auditLogWebhookFailure failed: '.$e->getMessage(), ['provider' => $provider]);
            } catch (Throwable) {
            }
        }
    }
}
