<?php

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

if (! function_exists('auditLog')) {
    /**
     * Record an audit event. The database row is written at once, or when the open transaction commits.
     *
     * @param  string  $event  Dot-namespaced event name, e.g. `api.private_key.created`.
     * @param  array<string, mixed>  $context  Identifiers + outcome details.
     * @param  string  $level  Log level: info | warning | error.
     */
    function auditLog(string $event, array $context = [], string $level = 'info'): void
    {
        // MCP tools also call the REST API controllers through an internal request.
        if (str_starts_with($event, 'api.') && app()->bound('request') && request()->attributes->get('mcp_rest_bridge') === true) {
            $event = 'mcp.'.substr($event, 4);
        }

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
                'ip' => $request ? (client_ip($request) ?: null) : null,
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

if (! function_exists('auditUrl')) {
    /**
     * Scheme, host, port and path of a URL, without user info, query string or fragment.
     */
    function auditUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid-url]';
        }

        return $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');
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

if (! function_exists('auditSavedFields')) {
    /**
     * Names of the attributes that the last save() really changed, without timestamps.
     * Call it after save(), so changes made by saving hooks are included.
     *
     * @return array<int, string>
     */
    function auditSavedFields(Model $model): array
    {
        return array_values(array_diff(array_keys($model->getChanges()), ['created_at', 'updated_at']));
    }
}

if (! function_exists('auditFailureDeduplicationWindow')) {
    /**
     * Seconds during which repeated unauthenticated or permission failures from one source are recorded once.
     *
     * Failed logins use a short window so an ongoing brute force attempt stays visible
     * (one row per email and source IP each minute, on top of the login rate limit).
     * Sentinel pushes for a deleted server or an unpaid team fail until someone acts, so one row each hour is enough.
     *
     * @param  array<string, mixed>  $context
     */
    function auditFailureDeduplicationWindow(string $event, array $context = []): ?int
    {
        return match (true) {
            $event === 'auth.user.login_failed' => 60,
            $event === 'webhook.sentinel.signature_failed'
                && in_array(data_get($context, 'reason'), ['server_not_found', 'subscription_unpaid'], true) => 3600,
            $event === 'api.auth.unauthenticated',
            preg_match('/^(api|mcp)\.auth\.(ability|scope)_denied$/', $event) === 1,
            preg_match('/^webhook\.[a-z0-9_-]+\.signature_failed$/i', $event) === 1 => 300,
            default => null,
        };
    }
}

if (! function_exists('shouldRecordAuditEvent')) {
    /**
     * Keep only the first failure per event, source IP, reason, server, attempted email, token and
     * required abilities in each deduplication window. All other events are always recorded.
     *
     * @param  array<string, mixed>  $context
     */
    function shouldRecordAuditEvent(string $event, array $context): bool
    {
        $window = auditFailureDeduplicationWindow($event, $context);
        if ($window === null) {
            return true;
        }

        try {
            $request = app()->bound('request') ? request() : null;
            $tokenId = data_get($context, 'token_id');
            $fingerprint = hash('sha256', json_encode([
                $event,
                // The token identifies the client, so a token used from many IPs gets one row.
                $tokenId === null && $request ? client_ip($request) : null,
                data_get($context, 'reason'),
                data_get($context, 'server_uuid'),
                Str::lower(trim((string) data_get($context, 'attempted_email'))),
                $tokenId,
                data_get($context, 'required_abilities'),
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
                    ?? $request?->header('X-Forgejo-Event')
                    ?? $request?->header('X-Event-Key'),
            ];

            auditLog($event, array_merge($base, $context), 'warning');
        } catch (Throwable) {
        }
    }
}
