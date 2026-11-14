<?php

namespace App\Enums;

enum ProcessStatus: string
{
    case QUEUED = 'queued';
    case IN_PROGRESS = 'in_progress';
    case FINISHED = 'finished';
    case ERROR = 'error';
    case KILLED = 'killed';
    case CANCELLED = 'cancelled';
    case CLOSED = 'closed';
    case WAITING_FOR_APPROVAL = 'waiting_for_approval';
}
