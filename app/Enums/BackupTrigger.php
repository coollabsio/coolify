<?php

namespace App\Enums;

/**
 * What started a run of a database backup. Notifications show it.
 */
enum BackupTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case BeforeUpdate = 'before_update';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Manual => 'Manual',
            self::BeforeUpdate => 'Before Coolify update',
        };
    }
}
