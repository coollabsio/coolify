<?php

namespace App\Enums;

enum ActivityTypes: string
{
    case INSTANT = 'instant';
    case DEPLOYMENT = 'deployment';
}
