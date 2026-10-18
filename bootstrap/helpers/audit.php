<?php

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        if (! shouldRecordAuditEvent($event, $context)) {
            return;
        }

        $level = AuditEvent::normalizeLevel($level);

        try {
            $request = app()->bound('request') ? request() : null;
            $user = auth()->user();
            $token = $user?->currentAccessToken();
            $payload = AuditEvent::redactContext(array_merge([
                'event' => $event,
                'ip' => $request?->ip(),
                'ua' => substr((string) $request?->userAgent(), 0, 200),
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'team_id' => $token ? data_get($token, 'team_id') : null,
                'token_id' => $token?->id,
                'token_name' => $token?->name,
                'method' => $request?->method(),
                'path' => $request?->path(),
            ], $context));

            Log::channel('audit')->{$level}($event, $payload);
        } catch (Throwable) {
            // The database sink remains available when the optional channel fails.
        }

        AuditEvent::record($event, $context, $level);
    }
}

if (! function_exists('auditChangedFields')) {
    /**
     * Names of the attributes that the next save() will really change, without timestamps.
     * Call it before save(). Loosely equal values, such as `false` and `0` in an uncast
     * boolean column, do not count as changes.
     *
     * @return array<int, string>
     */
    function auditChangedFields(Model $model): array
    {
        return collect(array_keys($model->getDirty()))
            ->reject(fn (string $field): bool => in_array($field, ['created_at', 'updated_at'], true)
                || $model->getOriginal($field) == $model->getAttribute($field))
            ->values()
            ->all();
    }
}

if (! function_exists('auditFailureDeduplicationWindow')) {
    /**
     * Seconds during which repeated unauthenticated failures from one source are recorded once.
     *
     * Failed logins use a short window so an ongoing brute force attempt stays visible
     * (one row per email and source IP each minute, on top of the login rate limit).
     */
    function auditFailureDeduplicationWindow(string $event): ?int
    {
        return match (true) {
            $event === 'auth.user.login_failed' => 60,
            $event === 'api.auth.unauthenticated',
            preg_match('/^webhook\.[a-z0-9_-]+\.signature_failed$/i', $event) === 1 => 300,
            default => null,
        };
    }
}

if (! function_exists('shouldRecordAuditEvent')) {
    /**
     * Keep only the first unauthenticated failure per event, source IP, reason, server and
     * attempted email in each deduplication window. All other events are always recorded.
     *
     * @param  array<string, mixed>  $context
     */
    function shouldRecordAuditEvent(string $event, array $context): bool
    {
        $window = auditFailureDeduplicationWindow($event);
        if ($window === null) {
            return true;
        }

        try {
            $request = app()->bound('request') ? request() : null;
            $fingerprint = hash('sha256', json_encode([
                $event,
                $request?->ip(),
                data_get($context, 'reason'),
                data_get($context, 'server_uuid'),
                Str::lower(trim((string) data_get($context, 'attempted_email'))),
            ]));

            return Cache::add("audit-failure-dedup:{$fingerprint}", true, $window);
        } catch (Throwable) {
            return true;
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
