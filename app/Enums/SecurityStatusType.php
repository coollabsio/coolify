<?php

namespace App\Enums;

/**
 * The security status of a resource or an image (spec 15.6). The cases are in the order the rules are applied.
 */
enum SecurityStatusType: string
{
    case NotChecked = 'not_checked';
    case CheckNeeded = 'check_needed';
    case UpdateNow = 'update_now';
    case UpdateAvailable = 'update_available';
    case NoFix = 'no_fix';
    case Ok = 'ok';
}
