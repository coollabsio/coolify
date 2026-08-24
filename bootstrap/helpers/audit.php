<?php

use App\Models\AuditEvent;

if (! function_exists('auditLog')) {
    /**
     * Queue an audit event for persistence after the response.
     *
     * @param  string  $event  Dot-namespaced event name, e.g. `api.private_key.created`.
     * @param  array<string, mixed>  $context  Identifiers + outcome details.
     * @param  string  $level  Log level: info | warning | error.
     */
    function auditLog(string $event, array $context = [], string $level = 'info'): void
    {
        try {
            AuditEvent::record($event, $context);
        } catch (Throwable) {
        }
    }
}

if (! function_exists('auditLogWebhookFailure')) {
    /**
     * Record a webhook signature/auth verification failure.
     */
    function auditLogWebhookFailure(string $provider, string $reason, array $context = []): void
    {
        try {
            $request = app()->bound('request') ? request() : null;

            $event = "webhook.{$provider}.signature_failed";

            $base = [
                'reason' => $reason,
                'method' => $request?->method(),
                'path' => $request?->path(),
                'event_header' => $request?->header('X-GitHub-Event')
                    ?? $request?->header('X-Gitlab-Event')
                    ?? $request?->header('X-Gitea-Event')
                    ?? $request?->header('X-Event-Key'),
            ];

            auditLog($event, array_merge($base, $context), 'warning');
        } catch (Throwable) {
        }
    }
}
