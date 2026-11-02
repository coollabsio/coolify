<?php

namespace App\Enums;

enum NodeBuildStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::FAILED, self::CANCELLED], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The status of a deploy that has not passed this build, in the words of a deployment status. */
    public function deploymentLabel(): string
    {
        return match ($this) {
            self::QUEUED => 'Queued',
            self::RUNNING => 'In progress',
            self::SUCCEEDED => 'Success',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
        };
    }

    /** The `type` of `x-status-badge`. */
    public function badgeType(): string
    {
        return match ($this) {
            self::SUCCEEDED => 'success',
            self::FAILED => 'error',
            self::QUEUED, self::RUNNING => 'warning',
            self::CANCELLED => 'neutral',
        };
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::QUEUED->value, self::RUNNING->value];
    }
}
