<?php

namespace App\Enums;

enum GithubRunnerStatus: string
{
    case Queued = 'queued';
    case Provisioning = 'provisioning';
    case Idle = 'idle';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';

    /**
     * States that still need work from Coolify.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Queued, self::Provisioning, self::Idle, self::Running];
    }

    /**
     * States in which a runner container exists on a server and uses its capacity.
     *
     * @return array<int, self>
     */
    public static function occupying(): array
    {
        return [self::Provisioning, self::Idle, self::Running];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Waiting for job',
            self::TimedOut => 'Timed out',
            default => ucfirst($this->value),
        };
    }
}
