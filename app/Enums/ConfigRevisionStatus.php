<?php

namespace App\Enums;

enum ConfigRevisionStatus: string
{
    case Planned = 'planned';
    case WaitingApproval = 'waiting_approval';
    case Applying = 'applying';
    case Applied = 'applied';
    case Failed = 'failed';
    case Superseded = 'superseded';
    case Skipped = 'skipped';
}
