<?php

namespace App\Enums;

enum Role: string
{
    case MEMBER = 'member';
    case ADMIN = 'admin';
    case OWNER = 'owner';

    public function rank(): int
    {
        return match ($this) {
            self::MEMBER => 1,
            self::ADMIN => 2,
            self::OWNER => 3,
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
