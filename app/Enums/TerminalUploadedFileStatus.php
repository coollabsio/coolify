<?php

namespace App\Enums;

enum TerminalUploadedFileStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
    case DeleteFailed = 'delete_failed';
}
