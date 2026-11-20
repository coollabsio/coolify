<?php

namespace App\Enums;

/** The fix states of the findings lists; the values are the keys of `Vulnerabilities::FIX_STATES`. */
enum ImageScanFixState: string
{
    case Fixed = 'fixed';
    case NoFix = 'no_fix';
}
