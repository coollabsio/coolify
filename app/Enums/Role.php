<?php

namespace App\Enums;

enum Role: string
{
    case VIEWER = 'viewer';
    case MEMBER = 'member';
    case ADMIN = 'admin';
    case OWNER = 'owner';

    public function rank(): int
    {
        return match ($this) {
            self::VIEWER => 1,
            self::MEMBER => 2,
            self::ADMIN => 3,
            self::OWNER => 4,
        };
    }

    /**
     * Check if this role can manage team members.
     */
    public function canManageMembers(): bool
    {
        return $this->rank() >= self::ADMIN->rank();
    }

    /**
     * Check if this role has full resource access.
     */
    public function hasFullResourceAccess(): bool
    {
        return $this->rank() >= self::ADMIN->rank();
    }

    /**
     * Check if this role is read-only.
     */
    public function isReadOnly(): bool
    {
        return $this === self::VIEWER;
    }

    /**
     * Check if this role requires explicit project assignment.
     */
    public function requiresProjectAssignment(): bool
    {
        return in_array($this, [self::VIEWER, self::MEMBER]);
    }

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::VIEWER => 'Viewer',
            self::MEMBER => 'Member',
            self::ADMIN => 'Admin',
            self::OWNER => 'Owner',
        };
    }

    /**
     * Get all roles that can be assigned by this role.
     */
    public function canAssignRoles(): array
    {
        return match ($this) {
            self::OWNER => [self::VIEWER, self::MEMBER, self::ADMIN, self::OWNER],
            self::ADMIN => [self::VIEWER, self::MEMBER, self::ADMIN],
            default => [],
        };
    }

    public function lt(Role|string $role): bool
    {
        if (is_string($role)) {
            $role = Role::from($role);
        }

        return $this->rank() < $role->rank();
    }

    public function gt(Role|string $role): bool
    {
        if (is_string($role)) {
            $role = Role::from($role);
        }

        return $this->rank() > $role->rank();
    }
}
