<?php

namespace App\Actions\Database;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class SelectPostgresqlWalBaseBackupForTargetTime
{
    use AsAction;

    /**
     * @param  array<int|string, mixed>  $backups
     * @return array<string, mixed>
     */
    public function handle(array $backups, CarbonInterface $targetTime): array
    {
        $target = CarbonImmutable::instance($targetTime)->utc();
        $backupList = data_get($backups, 'backups', $backups);
        $eligibleBackups = [];

        foreach ($backupList as $backup) {
            if (! is_array($backup) || blank(data_get($backup, 'backup_name'))) {
                continue;
            }

            $finishTimeValue = data_get($backup, 'finish_time')
                ?? data_get($backup, 'stop_time')
                ?? data_get($backup, 'end_time');
            if (blank($finishTimeValue)) {
                continue;
            }

            try {
                $finishTime = CarbonImmutable::parse((string) $finishTimeValue)->utc();
            } catch (Throwable) {
                continue;
            }

            if ($finishTime->lessThanOrEqualTo($target)) {
                $eligibleBackups[] = [
                    'backup' => $backup,
                    'finish_time' => $finishTime,
                ];
            }
        }

        usort(
            $eligibleBackups,
            fn (array $left, array $right): int => $right['finish_time']->getTimestamp() <=> $left['finish_time']->getTimestamp(),
        );

        if ($eligibleBackups === []) {
            throw new RuntimeException('No WAL-G base backup finished at or before the requested restore time.');
        }

        return $eligibleBackups[0]['backup'];
    }
}
