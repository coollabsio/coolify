<?php

declare(strict_types=1);

namespace App\Enums;

enum InvitationMethod: string
{
    case Email = 'email';
    case Link = 'link';
}
