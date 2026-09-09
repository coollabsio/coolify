<?php

namespace App\Traits;

/**
 * Shared healthcheck behaviour for standalone database models.
 *
 * Standalone databases use a fixed, type-specific probe command (psql, redis-cli, ...),
 * so only the timing fields and the enable/disable flag are configurable.
 */
trait HasDatabaseHealthCheck
{
    public function isHealthcheckEnabled(): bool
    {
        return (bool) ($this->health_check_enabled ?? true);
    }

    /**
     * Build the Docker Compose healthcheck block for the given probe command.
     *
     * @param  array<int, string>  $test  The Docker `test` array (e.g. ['CMD', 'pg_isready']).
     * @return array<string, mixed>
     */
    public function healthCheckConfiguration(array $test): array
    {
        return [
            'test' => $test,
            'interval' => ($this->health_check_interval ?? 15).'s',
            'timeout' => ($this->health_check_timeout ?? 5).'s',
            'retries' => $this->health_check_retries ?? 5,
            'start_period' => ($this->health_check_start_period ?? 5).'s',
        ];
    }

    protected function healthCheckConfigurationHash(): string
    {
        return implode('|', [
            (int) ($this->health_check_enabled ?? true),
            $this->health_check_interval ?? 15,
            $this->health_check_timeout ?? 5,
            $this->health_check_retries ?? 5,
            $this->health_check_start_period ?? 5,
        ]);
    }
}
