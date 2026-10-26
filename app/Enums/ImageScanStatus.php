<?php

namespace App\Enums;

enum ImageScanStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
