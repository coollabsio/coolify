<?php

namespace App\Enums;

enum ActivityTypes: string
{
    case INLINE = 'inline';
    case COMMAND = 'command';
}
