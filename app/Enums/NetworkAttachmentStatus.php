<?php

namespace App\Enums;

enum NetworkAttachmentStatus: string
{
    case Desired = 'desired';
    case Attached = 'attached';
    case Detached = 'detached';
    case MissingNetwork = 'missing_network';
    case MissingContainer = 'missing_container';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
