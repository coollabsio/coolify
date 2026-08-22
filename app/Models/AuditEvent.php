<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AuditEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'team_id',
        'event',
        'source',
        'action',
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
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $event, array $context = []): void
    {
        try {
            $attributes = self::attributesFor($event, $context);

            if ($attributes === null) {
                return;
            }

            DB::afterCommit(function () use ($attributes): void {
                defer(function () use ($attributes): void {
                    try {
                        self::query()->create($attributes);
                    } catch (Throwable) {
                    }
                })->always();
            });
        } catch (Throwable) {
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function attributesFor(string $event, array $context): array
    {
        $teamId = data_get(auth()->user()?->currentAccessToken(), 'team_id')
            ?? data_get($context, 'team_id')
            ?? currentTeam()?->id
            ?? self::teamIdFromContext($context);

        $parts = explode('.', $event);
        $source = $parts[0] ?? 'system';
        $resourceType = $parts[1] ?? null;
        $action = end($parts) ?: 'event';
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
            'actor_type' => $actorType,
            'actor_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'actor_token_id' => $token?->id,
            'actor_token_name' => $token?->name,
            'resource_type' => $resourceType,
            'resource_uuid' => $resourceUuid,
            'resource_name' => $resourceName,
            'description' => data_get($context, 'audit_description')
                ?? trim(($resourceName ?? Str::headline((string) $resourceType)).' '.Str::headline($action)),
            'metadata' => self::redact($context),
            'ip_address' => app()->bound('request') ? request()->ip() : null,
            'user_agent' => app()->bound('request') ? Str::limit((string) request()->userAgent(), 200, '') : null,
        ];
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

    public static function pruneExpired(): int
    {
        return self::query()
            ->where('created_at', '<', now()->subDays(90))
            ->delete();
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

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/password|secret|token|private_key|signature|credential/i', $key)) {
            return '[REDACTED]';
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
}
