<?php

namespace App\Actions\Database;

use App\Models\PostgresqlWalBackupConfiguration;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Lorisleiva\Actions\Concerns\AsAction;

class BuildPostgresqlWalGPostgresConfig
{
    use AsAction;

    public function handle(
        PostgresqlWalBackupConfiguration $configuration,
        ?CarbonInterface $recoveryTargetTime = null,
    ): string {
        if ($recoveryTargetTime) {
            $utcTargetTime = CarbonImmutable::instance($recoveryTargetTime)->utc();

            return implode("\n", [
                "restore_command = '/usr/local/bin/coolify-walg-fetch %f %p'",
                "recovery_target_time = '{$utcTargetTime->format('Y-m-d H:i:s')}+00'",
                "recovery_target_action = 'promote'",
            ]);
        }

        return implode("\n", [
            "wal_level = {$configuration->wal_level}",
            'archive_mode = on',
            "archive_timeout = {$configuration->archive_timeout_seconds}",
            "archive_command = '/usr/local/bin/coolify-walg-archive %p'",
        ]);
    }
}
