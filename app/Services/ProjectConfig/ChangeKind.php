<?php

namespace App\Services\ProjectConfig;

enum ChangeKind: string
{
    case Create = 'create';
    case Update = 'update';
    case Remove = 'remove';
    case Rename = 'rename';
    case Unmanage = 'unmanage';
    case Import = 'import';
}
