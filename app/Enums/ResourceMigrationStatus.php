<?php

namespace App\Enums;

enum ResourceMigrationStatus: string
{
    case Pending = 'pending';
    case Exporting = 'exporting';
    case Uploaded = 'uploaded';
    case Importing = 'importing';
    case Restoring = 'restoring';
    case Deploying = 'deploying';
    case Healthy = 'healthy';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Completed = 'completed';
    case Partial = 'partial';
    case Running = 'running';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Uploaded,
            self::Healthy,
            self::Failed,
            self::Skipped,
            self::Completed,
            self::Partial,
        ], true);
    }
}
