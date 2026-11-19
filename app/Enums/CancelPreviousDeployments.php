<?php

namespace App\Enums;

/**
 * Which older deployments a new deployment cancels. A building deployment is cancelled only before it
 * starts to replace containers, and cancelling builds also cancels queued deployments.
 */
enum CancelPreviousDeployments: string
{
    case NONE = 'none';
    case QUEUED = 'queued';
    case BUILDING = 'building';
}
