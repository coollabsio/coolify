<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Root = 'root';
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';
    case Billing = 'billing';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Root => 'Root',
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
            self::Billing => 'Billing',
            self::Custom => 'Custom',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Root => 'Unrestricted access to everything.',
            self::Owner => 'Full ownership and control over the workspace and all its resources.',
            self::Admin => 'Can manage and configure workspace resources.',
            self::Member => 'Can contribute and interact with workspace resources.',
            self::Viewer => 'Read-only access to workspace resources.',
            self::Billing => 'Manages billing and subscription settings.',
            self::Custom => 'Custom role with individually assigned permissions.',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Root => Permission::cases(),
            self::Owner => [
                // Workspaces
                Permission::WorkspaceCreate,
                Permission::WorkspaceRead,
                Permission::WorkspaceUpdate,
                Permission::WorkspaceDelete,
            ],
            self::Admin => [
                // Workspaces
                Permission::WorkspaceCreate,
                Permission::WorkspaceRead,
                Permission::WorkspaceUpdate,
            ],
            self::Member => [
                // Workspaces
                Permission::WorkspaceRead,
            ],
            self::Viewer => [
                // Workspaces
                Permission::WorkspaceRead,
            ],
            self::Billing => [],
            self::Custom => [],
        };
    }
}
