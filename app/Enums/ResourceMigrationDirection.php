<?php

namespace App\Enums;

enum ResourceMigrationDirection: string
{
    case Export = 'export';
    case Import = 'import';
}
