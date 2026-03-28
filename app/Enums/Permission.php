<?php

declare(strict_types=1);

namespace App\Enums;

enum Permission: string
{
    case WorkspaceRead = 'workspaceRead';
    case WorkspaceCreate = 'workspaceCreate';
    case WorkspaceUpdate = 'workspaceUpdate';
    case WorkspaceDestroy = 'workspaceDestroy';
}
