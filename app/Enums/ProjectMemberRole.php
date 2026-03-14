<?php

namespace App\Enums;

enum ProjectMemberRole: string
{
    case Viewer = 'viewer';
    case Deployer = 'deployer';
    case Manager = 'manager';

    public function rank(): int
    {
        return match ($this) {
            self::Viewer => 1,
            self::Deployer => 2,
            self::Manager => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Viewer (read-only)',
            self::Deployer => 'Deployer (view + deploy)',
            self::Manager => 'Manager (view + deploy + manage)',
        };
    }

    public function canDeploy(): bool
    {
        return $this->rank() >= self::Deployer->rank();
    }

    public function canManage(): bool
    {
        return $this === self::Manager;
    }

    public function lt(ProjectMemberRole|string $role): bool
    {
        if (is_string($role)) {
            $role = self::from($role);
        }

        return $this->rank() < $role->rank();
    }

    public function gte(ProjectMemberRole|string $role): bool
    {
        if (is_string($role)) {
            $role = self::from($role);
        }

        return $this->rank() >= $role->rank();
    }
}
