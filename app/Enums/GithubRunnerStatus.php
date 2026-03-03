<?php

namespace App\Enums;

enum GithubRunnerStatus: string
{
    case Queued = 'queued';
    case Provisioning = 'provisioning';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cleaning = 'cleaning';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Provisioning, self::Running, self::Cleaning]);
    }
}
