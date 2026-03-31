<?php

declare(strict_types=1);

namespace App\Enums;

enum Permission: string
{
    case WorkspaceCreate = 'workspaceCreate';
    case WorkspaceRead = 'workspaceRead';
    case WorkspaceUpdate = 'workspaceUpdate';
    case WorkspaceDelete = 'workspaceDelete';
}
