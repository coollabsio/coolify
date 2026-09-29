<?php

namespace App\Traits;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Keeps next_run_at of a schedule model in sync with its frequency and enabled flag.
 * The model must have `frequency`, `enabled`, `next_run_at`, and a `server()` method.
 */
trait HasNextRunAt
{
    protected static function bootHasNextRunAt(): void
    {
        static::saving(function ($schedule) {
            if ($schedule->exists && ! $schedule->isDirty(['frequency', 'enabled'])) {
                return;
            }

            $schedule->next_run_at = $schedule->calculateNextRunAt(now());
        });
    }

    public function calculateNextRunAt(DateTimeInterface $after, bool $includeCurrentMinute = false): ?CarbonImmutable
    {
        if ($this->getAttribute('enabled') !== null && ! $this->enabled) {
            return null;
        }

        return next_cron_run_at((string) $this->frequency, $this->scheduleTimezone(), $after, $includeCurrentMinute);
    }

    public function scheduleTimezone(): ?string
    {
        return data_get($this->server(), 'settings.server_timezone');
    }
}
