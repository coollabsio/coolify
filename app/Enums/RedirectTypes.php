<?php

namespace App\Enums;

enum RedirectTypes: string
{
    case BOTH = 'both';
    case WWW = 'www';
    case NON_WWW = 'non-www';
}
