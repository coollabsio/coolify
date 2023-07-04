<?php

namespace App\Enums;

enum ApplicationDeploymentStatus: string
{
    case QUEUED = 'queued';
    case IN_PROGRESS = 'in_progress';
    case FINISHED = 'finished';
    case FAILED = 'failed';
    case CANCELLED_BY_USER = 'cancelled-by-user';
}
