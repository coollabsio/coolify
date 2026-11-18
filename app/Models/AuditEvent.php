<?php

namespace App\Models;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonSerializable;
use stdClass;
use Throwable;

class AuditEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Raw commands and configurations can contain credentials without sensitive field names.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_VALUE_FIELDS = [
        'git_full_url',
        'install_command', 'build_command', 'start_command',
        'health_check_command', 'health_check_response_text',
        'custom_docker_run_options', 'pre_deployment_command', 'post_deployment_command',
        'docker_compose_custom_start_command', 'docker_compose_custom_build_command',
        'custom_nginx_configuration',
        'postgres_conf', 'mysql_conf', 'mariadb_conf', 'mongo_conf', 'redis_conf', 'keydb_conf',
        'internal_db_url', 'external_db_url', 'init_scripts',
        'dockerfile', 'docker_compose', 'docker_compose_raw', 'custom_labels',
        'last_saved_proxy_configuration',
        'environment_variables', 'environment_variables_preview',
        'validation_logs', 'server_metadata', 'logs',
        'configuration_snapshot', 'configuration_diff', 'content', 'file_storage_content',
    ];

    private const PRUNE_BATCH_SIZE = 1000;

    protected $fillable = [
        'team_id',
        'event',
        'source',
        'action',
        'level',
        'actor_type',
        'actor_id',
        'actor_name',
        'actor_email',
        'actor_token_id',
        'actor_token_name',
        'resource_type',
        'resource_uuid',
        'resource_name',
        'description',
        'metadata',
        'changes',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'changes' => 'encrypted:array',
            'created_at' => 'datetime',
        ];
    }

    public function scopeVisibleToTeam(Builder $query, int $teamId, bool $includeInstanceEvents = false): Builder
    {
        return $query->where(function (Builder $query) use ($includeInstanceEvents, $teamId): void {
            $query->where('team_id', $teamId)
                ->when($includeInstanceEvents, fn (Builder $query) => $query->orWhereNull('team_id'));
        });
    }

    public function scopeFiltered(
        Builder $query,
        string $search = '',
        string $action = 'all',
        string $source = 'all',
        bool $searchSensitiveFields = true,
    ): Builder {
        return $query
            ->when($action !== 'all', fn (Builder $query) => $query->where('action', $action))
            ->when($source !== 'all', fn (Builder $query) => $query->where('source', $source))
            ->when($search !== '', function (Builder $query) use ($search, $searchSensitiveFields): void {
                $query->where(function (Builder $query) use ($search, $searchSensitiveFields): void {
                    $query->where('event', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('resource_name', 'like', "%{$search}%")
                        ->orWhere('actor_name', 'like', "%{$search}%")
                        ->when($searchSensitiveFields, fn (Builder $query) => $query->orWhere('actor_email', 'like', "%{$search}%"));
                });
            });
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->latest('created_at')->latest('id');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $event, array $context = [], string $level = 'info'): void
    {
        self::recordWithChanges($event, $context, level: $level);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public static function recordModelMutation(string $event, array $context, array $changes): void
    {
        self::recordWithChanges($event, $context, $changes ?: null);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, array{old: mixed, new: mixed}>|null  $changes
     */
    private static function recordWithChanges(string $event, array $context, ?array $changes = null, string $level = 'info'): void
    {
        try {
            $attributes = self::attributesFor($event, $context, $changes, $level);

            // Write at commit, not with defer(): PHP skips the terminate phase when it stops a
            // request early (client abort, fatal error), and the committed change would have no row.
            DB::afterCommit(function () use ($attributes): void {
                try {
                    self::query()->create($attributes);
                } catch (Throwable $exception) {
                    try {
                        Log::warning('Audit event persistence failed', [
                            'event' => $attributes['event'],
                            'exception' => $exception::class,
                        ]);
                    } catch (Throwable) {
                        // Audit failures must not interrupt the action.
                    }
                }
            });
        } catch (Throwable $exception) {
            Log::warning('Audit event preparation failed', [
                'event' => $event,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function attributesFor(string $event, array $context, ?array $changes, string $level): array
    {
        // API tokens store team_id as a string. Keep it an integer, so JSON filters on metadata work.
        if (is_string($context['team_id'] ?? null) && ctype_digit($context['team_id'])) {
            $context['team_id'] = (int) $context['team_id'];
        }

        $teamId = data_get(auth()->user()?->currentAccessToken(), 'team_id')
            ?? (array_key_exists('team_id', $context)
                ? $context['team_id']
                : currentTeam()?->id ?? self::teamIdFromContext($context));

        $parts = explode('.', $event);
        $source = $parts[0] ?? 'system';
        $resourceType = data_get($context, 'resource') ?? ($parts[1] ?? null);
        $action = data_get($context, 'action') ?? (end($parts) ?: 'event');
        $resourceUuid = self::firstContextValue($context, $resourceType ? "{$resourceType}_uuid" : null, '_uuid');
        $resourceName = self::firstContextValue($context, $resourceType ? "{$resourceType}_name" : null, '_name');
        $user = auth()->user();
        $token = $user?->currentAccessToken();
        $actorType = match (true) {
            in_array($source, ['mcp', 'webhook', 'system', 'scheduler'], true) => $source,
            $token !== null => 'api_token',
            $user !== null => 'user',
            default => 'system',
        };

        return [
            'team_id' => $teamId,
            'event' => $event,
            'source' => $source,
            'action' => $action,
            'level' => self::normalizeLevel($level),
            'actor_type' => $actorType,
            'actor_id' => data_get($context, 'actor_id', $user?->id),
            'actor_name' => data_get($context, 'actor_name', $user?->name),
            'actor_email' => data_get($context, 'actor_email', $user?->email),
            'actor_token_id' => $token?->id,
            'actor_token_name' => $token?->name,
            'resource_type' => $resourceType,
            'resource_uuid' => $resourceUuid,
            'resource_name' => $resourceName,
            'description' => data_get($context, 'audit_description')
                ?? trim(($resourceName ?? Str::headline((string) $resourceType)).' '.Str::headline($action)),
            'metadata' => self::redact(Arr::except($context, ['audit_changes', 'actor_email', 'actor_ip', 'actor_user_agent'])),
            'changes' => $changes,
            'ip_address' => data_get($context, 'actor_ip')
                ?? (app()->bound('request') ? (client_ip(request()) ?: null) : null),
            'user_agent' => Str::limit((string) (data_get($context, 'actor_user_agent')
                ?? (app()->bound('request') ? request()->userAgent() : null)), 200, '') ?: null,
        ];
    }

    public static function normalizeLevel(string $level): string
    {
        return in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info';
    }

    public static function redactContext(array $context): array
    {
        return self::redact($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function teamIdFromContext(array $context): ?int
    {
        $applicationUuid = data_get($context, 'application_uuid');
        if (! is_string($applicationUuid) || $applicationUuid === '') {
            return null;
        }

        return Application::query()
            ->where('uuid', $applicationUuid)
            ->first()?->team()?->id;
    }

    /**
     * Removes the personal data of a deleted user: from the events that the user did,
     * and from the events about the user (team member, impersonation, deletion).
     */
    public static function anonymizeUser(User $user): void
    {
        $replacement = '[deleted user]';
        $personalValues = array_filter([Str::lower((string) $user->email), Str::lower((string) $user->name)]);

        self::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('actor_type', 'user')->where('actor_id', $user->id))
                ->orWhereJsonContains('metadata->member_id', $user->id)
                ->orWhereJsonContains('metadata->target_user_id', $user->id)
                ->orWhereJsonContains('metadata->deleted_user_id', $user->id))
            ->chunkById(self::PRUNE_BATCH_SIZE, function ($events) use ($personalValues, $replacement, $user): void {
                foreach ($events as $event) {
                    if ($event->actor_type === 'user' && (int) $event->actor_id === (int) $user->id) {
                        $event->actor_name = $replacement;
                        $event->actor_email = null;
                        $event->ip_address = null;
                        $event->user_agent = null;
                    }

                    if ($event->resource_name !== null && in_array(Str::lower($event->resource_name), $personalValues, true)) {
                        $event->description = $replacement.substr($event->description, strlen($event->resource_name));
                        $event->resource_name = $replacement;
                    }

                    $event->metadata = self::replacePersonalValues($event->metadata, $personalValues, $replacement);
                    $event->save();
                }
            });
    }

    /**
     * @param  array<int, string>  $personalValues
     */
    private static function replacePersonalValues(mixed $value, array $personalValues, string $replacement): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => self::replacePersonalValues($item, $personalValues, $replacement), $value);
        }

        return is_string($value) && in_array(Str::lower($value), $personalValues, true) ? $replacement : $value;
    }

    /**
     * Deletes in batches, so a large table is not locked by one long DELETE.
     */
    public static function pruneExpired(): int
    {
        $olderThan = now()->subDays(90);
        $total = 0;

        do {
            $deleted = self::query()
                ->where('created_at', '<', $olderThan)
                ->limit(self::PRUNE_BATCH_SIZE)
                ->delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function firstContextValue(array $context, ?string $preferredKey, string $suffix): mixed
    {
        if ($preferredKey !== null && filled(data_get($context, $preferredKey))) {
            return data_get($context, $preferredKey);
        }

        $key = Arr::first(array_keys($context), fn (string $key): bool => str_ends_with($key, $suffix));

        return $key ? data_get($context, $key) : null;
    }

    public static function isSensitiveField(string $field): bool
    {
        return in_array($field, self::SENSITIVE_VALUE_FIELDS, true)
            || preg_match('/password|secret|token|private_key|signature|credential|invitation_email|api_key|access_key|authorization|cookie|license_key/i', $field) === 1;
    }

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && (in_array($key, self::SENSITIVE_VALUE_FIELDS, true) || self::isSensitiveKey($key))) {
            return '[REDACTED]';
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (is_string($value)) {
            return self::stripUrlCredentials($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        return collect($value)
            ->mapWithKeys(fn (mixed $item, string|int $itemKey): array => [
                $itemKey => self::redact($item, (string) $itemKey),
            ])
            ->all();
    }

    /**
     * Removes the user info of URLs (https://user:token@host), so the audit keeps the URL without credentials.
     */
    private static function stripUrlCredentials(string $value): string
    {
        return preg_replace('#\b([a-z][a-z0-9+.-]*://)[^/?\#\s]+@#i', '$1', $value) ?? $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        if (preg_match('/_(id|uuid|name)$/i', $key)) {
            return false;
        }

        return (bool) preg_match('/password|secret|token|private_key|signature|credential|invitation_email|api_key|access_key|authorization|cookie|license_key/i', $key);
    }
}
