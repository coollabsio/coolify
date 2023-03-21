<?php

namespace App\Enums;

enum ProcessStatus: string
{
    case HOLDING = 'holding';
    case IN_PROGRESS = 'in_progress';
    case FINISHED = 'finished';
    case ERROR = 'error';
}
