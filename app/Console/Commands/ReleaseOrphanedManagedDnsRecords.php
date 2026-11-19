<?php

namespace App\Console\Commands;

use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReleaseOrphanedManagedDnsRecords extends Command
{
    protected $signature = 'dns:release-orphaned-records';

    protected $description = 'Release managed DNS records whose resources no longer exist (owned records are deleted in Cloudflare)';

    public function handle(ManagedDnsRecordCleanup $cleanup): int
    {
        $counts = $cleanup->releaseOrphanedRecords();
        if ($counts === []) {
            $this->info('No orphaned managed DNS records found.');

            return self::SUCCESS;
        }

        ksort($counts);
        $summary = collect($counts)->map(fn (int $count, string $outcome): string => "{$outcome}: {$count}")->implode(', ');
        $this->info("Orphaned managed DNS records processed ({$summary}).");
        Log::info('Orphaned managed DNS records processed.', $counts);

        return self::SUCCESS;
    }
}
