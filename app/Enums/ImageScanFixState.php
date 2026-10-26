<?php

namespace App\Enums;

enum ImageScanFixState: string
{
    case FixableNow = 'fixable_now';
    case NotReleased = 'not_released';
    case NoFix = 'no_fix';
}
