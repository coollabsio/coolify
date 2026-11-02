<?php

namespace App\Enums;

enum AccessScope: string
{
    case Team = 'team';
    case Project = 'project';
    case Environment = 'environment';
    case Resource = 'resource';
}
