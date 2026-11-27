<?php

namespace App\Enums;

enum ApplicationDeploymentStatus: string
{
    case QUEUED = 'queued';
    case IN_PROGRESS = 'in_progress';
    case FINISHED = 'finished';
    case FAILED = 'failed';
    case CANCELLED_BY_USER = 'cancelled-by-user';
    case SKIPPED = 'skipped';

    /**
     * A deployment in a final status does not run anymore, so it has an end time.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::FINISHED, self::FAILED, self::CANCELLED_BY_USER, self::SKIPPED], true);
    }
}
