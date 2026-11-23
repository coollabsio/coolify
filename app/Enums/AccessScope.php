<?php

namespace App\Enums;

enum AccessScope: string
{
    case Team = 'team';
    case Project = 'project';
    case Environment = 'environment';
    case Resource = 'resource';

    /** Only for API tokens: the server endpoints of one server. Member grants never use it. */
    case Server = 'server';
}
