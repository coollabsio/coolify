<?php

namespace App\Enums;

enum InstanceMigrationStatus: string
{
    case Pending = 'pending';
    case Packaging = 'packaging';
    case Installing = 'installing';
    case SyncingVolumes = 'syncing_localhost_volumes';
    case Restoring = 'restoring';
    case Consolidating = 'consolidating';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Packaging => 'Packaging backup',
            self::Installing => 'Installing Coolify',
            self::SyncingVolumes => 'Copying volumes',
            self::Restoring => 'Restoring Coolify database',
            self::Consolidating => 'Reassigning resources',
            self::Verifying => 'Verifying dashboard',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Ordered steps shown in the progress UI (excludes Pending/Failed).
     *
     * @return list<self>
     */
    public static function progressSteps(): array
    {
        return [
            self::Packaging,
            self::Installing,
            self::Restoring,
            self::SyncingVolumes,
            self::Consolidating,
            self::Verifying,
            self::Completed,
        ];
    }
}
