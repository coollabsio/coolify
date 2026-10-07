<?php

namespace App\Models;

use App\Casts\EncryptedArrayCast;
use App\Enums\ApplicationDeploymentStatus;
use App\Services\Security\SensitiveDataRedactor;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Project model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'application_id' => ['type' => 'string'],
        'deployment_uuid' => ['type' => 'string'],
        'pull_request_id' => ['type' => 'integer'],
        'docker_registry_image_tag' => ['type' => 'string', 'nullable' => true],
        'configuration_hash' => ['type' => 'string', 'nullable' => true],
        'configuration_snapshot' => ['type' => 'object', 'nullable' => true],
        'configuration_diff' => ['type' => 'object', 'nullable' => true],
        'force_rebuild' => ['type' => 'boolean'],
        'commit' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'is_webhook' => ['type' => 'boolean'],
        'is_api' => ['type' => 'boolean'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
        'logs' => ['type' => 'string'],
        'current_process_id' => ['type' => 'string'],
        'restart_only' => ['type' => 'boolean'],
        'git_type' => ['type' => 'string'],
        'server_id' => ['type' => 'integer'],
        'application_name' => ['type' => 'string'],
        'server_name' => ['type' => 'string'],
        'deployment_url' => ['type' => 'string'],
        'destination_id' => ['type' => 'string'],
        'only_this_server' => ['type' => 'boolean'],
        'parent_deployment_uuid' => ['type' => 'string', 'nullable' => true],
        'rollback' => ['type' => 'boolean'],
        'commit_message' => ['type' => 'string'],
    ],
)]
class ApplicationDeploymentQueue extends Model
{
    /**
     * Kept in memory only, never saved.
     *
     * @var array<array-key, mixed>
     */
    private array $remoteSecretsForRedaction = [];

    protected static function booted(): void
    {
        static::created(function (ApplicationDeploymentQueue $deployment): void {
            if (! auth()->check() || ! $deployment->rollback) {
                return;
            }

            $application = $deployment->application;
            $source = $deployment->is_api ? 'api' : 'ui';

            auditLog("{$source}.application.rollback", [
                'team_id' => $application?->team()?->id,
                'application_uuid' => $application?->uuid,
                'application_name' => $application?->name,
                'deployment_uuid' => $deployment->deployment_uuid,
                'commit' => $deployment->commit,
            ]);
        });

        static::updated(function (ApplicationDeploymentQueue $deployment): void {
            if (! auth()->check()
                || ! $deployment->wasChanged('status')
                || $deployment->status !== ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
                return;
            }

            $application = $deployment->application;
            $source = $deployment->is_api ? 'api' : 'ui';

            auditLog("{$source}.deployment.cancelled", [
                'team_id' => $application?->team()?->id,
                'application_uuid' => $application?->uuid,
                'application_name' => $application?->name,
                'deployment_uuid' => $deployment->deployment_uuid,
            ]);
        });
    }

    protected $fillable = [
        'application_id',
        'deployment_uuid',
        'pull_request_id',
        'docker_registry_image_tag',
        'configuration_hash',
        'configuration_snapshot',
        'configuration_diff',
        'force_rebuild',
        'commit',
        'status',
        'is_webhook',
        'logs',
        'current_process_id',
        'restart_only',
        'git_type',
        'server_id',
        'application_name',
        'server_name',
        'deployment_url',
        'destination_id',
        'only_this_server',
        'parent_deployment_uuid',
        'rollback',
        'commit_message',
        'is_api',
        'build_server_id',
        'horizon_job_id',
        'horizon_job_worker',
        'finished_at',
    ];

    /**
     * The configuration snapshot/diff hold full (decrypted on read) configuration,
     * including unlocked environment variable values. They are only meant for the
     * in-app diff modal (which redacts per role) and must never be serialized by the
     * API, so hide them globally as defense in depth.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'logs',
        'configuration_snapshot',
        'configuration_diff',
    ];

    protected $casts = [
        'pull_request_id' => 'integer',
        'finished_at' => 'datetime',
        'configuration_snapshot' => EncryptedArrayCast::class,
        'configuration_diff' => EncryptedArrayCast::class,
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function server(): Attribute
    {
        return Attribute::make(
            get: fn () => Server::find($this->server_id),
        );
    }

    public function setStatus(string $status)
    {
        $this->update([
            'status' => $status,
        ]);
    }

    public function getOutput($name)
    {
        if (! $this->logs) {
            return null;
        }

        return collect(json_decode($this->logs))->where('name', $name)->first()?->output ?? null;
    }

    public function getHorizonJobStatus()
    {
        return getJobStatus($this->horizon_job_id);
    }

    /**
     * Horizon drops its job record 'trim.pending' minutes after the push, also while the job runs,
     * so a missing record counts as running only until the deployment timeout has passed.
     */
    public function isHorizonJobActive(): bool
    {
        return match ($this->getHorizonJobStatus()) {
            'reserved' => true,
            'unknown' => $this->updated_at?->gt(now()->subSeconds($this->server?->settings?->dynamic_timeout ?? 3600)) ?? false,
            default => false,
        };
    }

    public function commitMessage()
    {
        if (empty($this->commit_message) || is_null($this->commit_message)) {
            return null;
        }

        return str($this->commit_message)->value();
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     */
    public function redactRemoteSecrets(array $secrets): void
    {
        $this->remoteSecretsForRedaction = $secrets;
    }

    /**
     * @param  array<array-key, mixed>  $extraSecrets  Values that are not stored on the application, such as remote secrets.
     */
    private function redactSensitiveInfo(string $text, array $extraSecrets = []): string
    {
        try {
            $app = $this->application;
            $redactAllValues = $app?->redactsAllEnvValuesInLogs() ?? true;
            $knownSecrets = EnvironmentVariable::logRedactionValuesFor($app?->environment_variables, $redactAllValues);
            if ($this->pull_request_id !== 0) {
                $knownSecrets = array_merge($knownSecrets, EnvironmentVariable::logRedactionValuesFor($app?->environment_variables_preview, $redactAllValues));
            }
        } catch (\Throwable) {
            return REDACTED;
        }

        return app(SensitiveDataRedactor::class)->redactText($text, array_merge($knownSecrets, EnvironmentVariable::remoteSecretLogRedactionValues($this->remoteSecretsForRedaction), $extraSecrets));
    }

    /**
     * @param  array<array-key, mixed>  $knownSecrets  Extra values to hide, such as remote secrets fetched for this deployment.
     */
    public function addLogEntry(
        string $message,
        string $type = 'stdout',
        bool $hidden = false,
        ?string $command = null,
        int $batch = 1,
        array $knownSecrets = [],
    ): void {
        if ($type === 'error') {
            $type = 'stderr';
        }
        $message = str($message)->trim();
        if ($message->startsWith('╔')) {
            $message = "\n".$message;
        }
        $newLogEntry = [
            'command' => $command === null ? null : $this->redactSensitiveInfo($command, $knownSecrets),
            'output' => $this->redactSensitiveInfo($message, $knownSecrets),
            'type' => $type,
            'timestamp' => Carbon::now('UTC'),
            'hidden' => $hidden,
            'batch' => $batch,
        ];

        DB::transaction(function () use ($newLogEntry): void {
            $storedLogs = static::query()->whereKey($this->getKey())->lockForUpdate()->value('logs');
            $previousLogs = $this->decodeLogs($storedLogs);
            $newLogEntry['order'] = count($previousLogs) + 1;
            $previousLogs[] = $newLogEntry;
            $logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

            // Update only the logs column without model events to prevent race conditions.
            static::query()->whereKey($this->getKey())->update(['logs' => $logs]);
            $this->logs = $logs;
            $this->syncOriginalAttribute('logs');
        });
    }

    /** @return array<int, mixed> */
    private function decodeLogs(?string $logs): array
    {
        if (blank($logs)) {
            return [];
        }

        try {
            $decoded = json_decode($logs, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
