<?php

namespace App\Enums;

enum NodeWorkloadAction: string
{
    case START = 'start';
    case STOP = 'stop';
    case RESTART = 'restart';
    case REMOVE = 'remove';
}
