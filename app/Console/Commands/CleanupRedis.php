<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CleanupRedis extends Command
{
    protected $signature = 'cleanup:redis {--dry-run : Show what would be deleted without actually deleting} {--skip-overlapping : Skip overlapping queue cleanup} {--clear-locks : Clear stale WithoutOverlapping locks} {--restart : Aggressive cleanup mode for system restart (marks all processing jobs as failed)}';

    protected $description = 'Cleanup Redis (Horizon jobs, metrics, overlapping queues, cache locks, and related data)';

    public function handle()
    {
        $redis = Redis::connection('horizon');
        $prefix = config('horizon.prefix');
        $dryRun = $this->option('dry-run');
        $skipOverlapping = $this->option('skip-overlapping');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No data will be deleted');
        }

        $deletedCount = 0;
        $totalKeys = 0;

        // Get all keys with the horizon prefix
        $keys = $redis->keys('*');
        $totalKeys = count($keys);

        $this->info("Scanning {$totalKeys} keys for cleanup...");

        foreach ($keys as $key) {
            $keyWithoutPrefix = str_replace($prefix, '', $key);
            $type = $redis->command('type', [$keyWithoutPrefix]);

            // Handle hash-type keys (individual jobs)
            if ($type === 5) {
                if ($this->shouldDeleteHashKey($redis, $keyWithoutPrefix, $dryRun)) {
                    $deletedCount++;
                }
            }
            // Handle other key types (metrics, lists, etc.)
            else {
                if ($this->shouldDeleteOtherKey($redis, $keyWithoutPrefix, $key, $dryRun)) {
                    $deletedCount++;
                }
            }
        }

        // Clean up overlapping queues if not skipped
        if (! $skipOverlapping) {
            $this->info('Cleaning up overlapping queues...');
            $overlappingCleaned = $this->cleanupOverlappingQueues($redis, $prefix, $dryRun);
            $deletedCount += $overlappingCleaned;
        }

        // Clean up stale cache locks (WithoutOverlapping middleware)
        if ($this->option('clear-locks')) {
            $this->info('Cleaning up stale cache locks...');
            $locksCleaned = $this->cleanupCacheLocks($dryRun);
            $deletedCount += $locksCleaned;
        }

        // Clean up stuck jobs (restart mode = aggressive, runtime mode = conservative)
        $isRestart = $this->option('restart');
        if ($isRestart || $this->option('clear-locks')) {
            $this->info($isRestart ? 'Cleaning up stuck jobs (RESTART MODE - aggressive)...' : 'Checking for stuck jobs (runtime mode - conservative)...');
            $jobsCleaned = $this->cleanupStuckJobs($redis, $prefix, $dryRun, $isRestart);
            $deletedCount += $jobsCleaned;
        }

        if ($dryRun) {
            $this->info("DRY RUN: Would delete {$deletedCount} out of {$totalKeys} keys");
        } else {
            $this->info("Deleted {$deletedCount} out of {$totalKeys} keys");
        }
    }

    private function shouldDeleteHashKey($redis, $keyWithoutPrefix, $dryRun)
    {
        $data = $redis->command('hgetall', [$keyWithoutPrefix]);
        $status = data_get($data, 'status');

        // Delete completed and failed jobs
        if (in_array($status, ['completed', 'failed'])) {
            if ($dryRun) {
                $this->line("Would delete job: {$keyWithoutPrefix} (status: {$status})");
            } else {
                $redis->command('del', [$keyWithoutPrefix]);
                $this->line("Deleted job: {$keyWithoutPrefix} (status: {$status})");
            }

            return true;
        }

        return false;
    }

    private function shouldDeleteOtherKey($redis, $keyWithoutPrefix, $fullKey, $dryRun)
    {
        // Clean up various Horizon data structures
        $patterns = [
            'recent_jobs' => 'Recent jobs list',
            'failed_jobs' => 'Failed jobs list',
            'completed_jobs' => 'Completed jobs list',
            'job_classes' => 'Job classes metrics',
            'queues' => 'Queue metrics',
            'processes' => 'Process metrics',
            'supervisors' => 'Supervisor data',
            'metrics' => 'General metrics',
            'workload' => 'Workload data',
        ];

        foreach ($patterns as $pattern => $description) {
            if (str_contains($keyWithoutPrefix, $pattern)) {
                if ($dryRun) {
                    $this->line("Would delete {$description}: {$keyWithoutPrefix}");
                } else {
                    $redis->command('del', [$keyWithoutPrefix]);
                    $this->line("Deleted {$description}: {$keyWithoutPrefix}");
                }

                return true;
            }
        }

        // Clean up old timestamped data (older than 7 days)
        if (preg_match('/(\d{10})/', $keyWithoutPrefix, $matches)) {
            $timestamp = (int) $matches[1];
            $weekAgo = now()->subDays(7)->timestamp;

            if ($timestamp < $weekAgo) {
                if ($dryRun) {
                    $this->line("Would delete old timestamped data: {$keyWithoutPrefix}");
                } else {
                    $redis->command('del', [$keyWithoutPrefix]);
                    $this->line("Deleted old timestamped data: {$keyWithoutPrefix}");
                }

                return true;
            }
        }

        return false;
    }

    private function cleanupOverlappingQueues($redis, $prefix, $dryRun)
    {
        $cleanedCount = 0;
        $queueKeys = [];

        // Find all queue-related keys
        $allKeys = $redis->keys('*');
        foreach ($allKeys as $key) {
            $keyWithoutPrefix = str_replace($prefix, '', $key);
            if (str_contains($keyWithoutPrefix, 'queue:') || preg_match('/queues?[:\-]/', $keyWithoutPrefix)) {
                $queueKeys[] = $keyWithoutPrefix;
            }
        }

        $this->info('Found '.count($queueKeys).' queue-related keys');

        // Group queues by name pattern to find duplicates
        $queueGroups = [];
        foreach ($queueKeys as $queueKey) {
            // Extract queue name (remove timestamps, suffixes)
            $baseName = preg_replace('/[:\-]\d+$/', '', $queueKey);
            $baseName = preg_replace('/[:\-](pending|reserved|delayed|processing)$/', '', $baseName);

            if (! isset($queueGroups[$baseName])) {
                $queueGroups[$baseName] = [];
            }
            $queueGroups[$baseName][] = $queueKey;
        }

        // Process each group for overlaps
        foreach ($queueGroups as $baseName => $keys) {
            if (count($keys) > 1) {
                $cleanedCount += $this->deduplicateQueueGroup($redis, $baseName, $keys, $dryRun);
            }

            // Also check for duplicate jobs within individual queues
            foreach ($keys as $queueKey) {
                $cleanedCount += $this->deduplicateQueueContents($redis, $queueKey, $dryRun);
            }
        }

        return $cleanedCount;
    }

    private function deduplicateQueueGroup($redis, $baseName, $keys, $dryRun)
    {
        $cleanedCount = 0;
        $this->line("Processing queue group: {$baseName} (".count($keys).' keys)');

        // Sort keys to keep the most recent one
        usort($keys, function ($a, $b) {
            // Prefer keys without timestamps (they're usually the main queue)
            $aHasTimestamp = preg_match('/\d{10}/', $a);
            $bHasTimestamp = preg_match('/\d{10}/', $b);

            if ($aHasTimestamp && ! $bHasTimestamp) {
                return 1;
            }
            if (! $aHasTimestamp && $bHasTimestamp) {
                return -1;
            }

            // If both have timestamps, prefer the newer one
            if ($aHasTimestamp && $bHasTimestamp) {
                preg_match('/(\d{10})/', $a, $aMatches);
                preg_match('/(\d{10})/', $b, $bMatches);

                return ($bMatches[1] ?? 0) <=> ($aMatches[1] ?? 0);
            }

            return strcmp($a, $b);
        });

        // Keep the first (preferred) key, remove others that are empty or redundant
        $keepKey = array_shift($keys);

        foreach ($keys as $redundantKey) {
            $type = $redis->command('type', [$redundantKey]);
            $shouldDelete = false;

            if ($type === 1) { // LIST type
                $length = $redis->command('llen', [$redundantKey]);
                if ($length == 0) {
                    $shouldDelete = true;
                }
            } elseif ($type === 3) { // SET type
                $count = $redis->command('scard', [$redundantKey]);
                if ($count == 0) {
                    $shouldDelete = true;
                }
            } elseif ($type === 4) { // ZSET type
                $count = $redis->command('zcard', [$redundantKey]);
                if ($count == 0) {
                    $shouldDelete = true;
                }
            }

            if ($shouldDelete) {
                if ($dryRun) {
                    $this->line("  Would delete empty queue: {$redundantKey}");
                } else {
                    $redis->command('del', [$redundantKey]);
                    $this->line("  Deleted empty queue: {$redundantKey}");
                }
                $cleanedCount++;
            }
        }

        return $cleanedCount;
    }

    private function deduplicateQueueContents($redis, $queueKey, $dryRun)
    {
        $cleanedCount = 0;
        $type = $redis->command('type', [$queueKey]);

        if ($type === 1) { // LIST type - common for job queues
            $length = $redis->command('llen', [$queueKey]);
            if ($length > 1) {
                $items = $redis->command('lrange', [$queueKey, 0, -1]);
                $uniqueItems = array_unique($items);

                if (count($uniqueItems) < count($items)) {
                    $duplicates = count($items) - count($uniqueItems);

                    if ($dryRun) {
                        $this->line("  Would remove {$duplicates} duplicate jobs from queue: {$queueKey}");
                    } else {
                        // Rebuild the list with unique items
                        $redis->command('del', [$queueKey]);
                        foreach (array_reverse($uniqueItems) as $item) {
                            $redis->command('lpush', [$queueKey, $item]);
                        }
                        $this->line("  Removed {$duplicates} duplicate jobs from queue: {$queueKey}");
                    }
                    $cleanedCount += $duplicates;
                }
            }
        }

        return $cleanedCount;
    }

    private function cleanupCacheLocks(bool $dryRun): int
    {
        $cleanedCount = 0;

        // Use the default Redis connection (database 0) where cache locks are stored
        $redis = Redis::connection('default');

        // Get all keys matching WithoutOverlapping lock pattern
        $allKeys = $redis->keys('*');
        $lockKeys = [];

        foreach ($allKeys as $key) {
            // Match cache lock keys: they contain 'laravel-queue-overlap'
            if (preg_match('/overlap/i', $key)) {
                $lockKeys[] = $key;
            }
        }
        if (empty($lockKeys)) {
            $this->info('  No cache locks found.');

            return 0;
        }

        $this->info('  Found '.count($lockKeys).' cache lock(s)');

        foreach ($lockKeys as $lockKey) {
            // Check TTL to identify stale locks
            $ttl = $redis->ttl($lockKey);

            // TTL = -1 means no expiration (stale lock!)
            // TTL = -2 means key doesn't exist
            // TTL > 0 means lock is valid and will expire
            if ($ttl === -1) {
                if ($dryRun) {
                    $this->warn("  Would delete STALE lock (no expiration): {$lockKey}");
                } else {
                    $redis->del($lockKey);
                    $this->info("  ✓ Deleted STALE lock: {$lockKey}");
                }
                $cleanedCount++;
            } elseif ($ttl > 0) {
                $this->line("  Skipping active lock (expires in {$ttl}s): {$lockKey}");
            }
        }

        if ($cleanedCount === 0) {
            $this->info('  No stale locks found (all locks have expiration set)');
        }

        return $cleanedCount;
    }

    /**
     * Clean up stuck jobs based on mode (restart vs runtime).
     *
     * @param  mixed  $redis  Redis connection
     * @param  string  $prefix  Horizon prefix
     * @param  bool  $dryRun  Dry run mode
     * @param  bool  $isRestart  Restart mode (aggressive) vs runtime mode (conservative)
     * @return int Number of jobs cleaned
     */
    private function cleanupStuckJobs($redis, string $prefix, bool $dryRun, bool $isRestart): int
    {
        $cleanedCount = 0;
        $now = time();

        // Get all keys with the horizon prefix
        $cursor = 0;
        $keys = [];
        do {
            $result = $redis->scan($cursor, ['match' => '*', 'count' => 100]);

            // Guard against scan() returning false
            if ($result === false) {
                $this->error('Redis scan failed, stopping key retrieval');
                break;
            }

            $cursor = $result[0];
            $keys = array_merge($keys, $result[1]);
        } while ($cursor !== 0);

        foreach ($keys as $key) {
            $keyWithoutPrefix = str_replace($prefix, '', $key);
            $type = $redis->command('type', [$keyWithoutPrefix]);

            // Only process hash-type keys (individual jobs)
            if ($type !== 5) {
                continue;
            }

            $data = $redis->command('hgetall', [$keyWithoutPrefix]);
            $status = data_get($data, 'status');
            $payload = data_get($data, 'payload');

            // Only process jobs in "processing" or "reserved" state
            if (! in_array($status, ['processing', 'reserved'])) {
                continue;
            }

            // Parse job payload to get job class and started time
            $payloadData = json_decode($payload, true);

            // Check for JSON decode errors
            if ($payloadData === null || json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg = json_last_error_msg();
                $truncatedPayload = is_string($payload) ? substr($payload, 0, 200) : 'non-string payload';
                $this->error("Failed to decode job payload for {$keyWithoutPrefix}: {$errorMsg}. Payload: {$truncatedPayload}");

                continue;
            }

            $jobClass = data_get($payloadData, 'displayName', 'Unknown');

            // Prefer reserved_at (when job started processing), fallback to created_at
            $reservedAt = (int) data_get($data, 'reserved_at', 0);
            $createdAt = (int) data_get($data, 'created_at', 0);
            $startTime = $reservedAt ?: $createdAt;

            // If we can't determine when the job started, skip it
            if (! $startTime) {
                continue;
            }

            // Calculate how long the job has been processing
            $processingTime = $now - $startTime;

            $shouldFail = false;
            $reason = '';

            if ($isRestart) {
                // RESTART MODE: Mark ALL processing/reserved jobs as failed
                // Safe because all workers are dead on restart
                $shouldFail = true;
                $reason = 'System restart - all workers terminated';
            } else {
                // RUNTIME MODE: Only mark truly stuck jobs as failed
                // Be conservative to avoid killing legitimate long-running jobs

                // Skip ApplicationDeploymentJob entirely (has dynamic_timeout, can run 2+ hours)
                if (str_contains($jobClass, 'ApplicationDeploymentJob')) {
                    continue;
                }

                // Skip DatabaseBackupJob (large backups can take hours)
                if (str_contains($jobClass, 'DatabaseBackupJob')) {
                    continue;
                }

                // For other jobs, only fail if processing > 12 hours
                if ($processingTime > 43200) { // 12 hours
                    $shouldFail = true;
                    $reason = 'Processing for more than 12 hours';
                }
            }

            if ($shouldFail) {
                if ($dryRun) {
                    $this->warn("  Would mark as FAILED: {$jobClass} (processing for ".round($processingTime / 60, 1)." min) - {$reason}");
                } else {
                    // Mark job as failed
                    $redis->command('hset', [$keyWithoutPrefix, 'status', 'failed']);
                    $redis->command('hset', [$keyWithoutPrefix, 'failed_at', $now]);
                    $redis->command('hset', [$keyWithoutPrefix, 'exception', "Job cleaned up by cleanup:redis - {$reason}"]);

                    $this->info("  ✓ Marked as FAILED: {$jobClass} (processing for ".round($processingTime / 60, 1).' min) - '.$reason);
                }
                $cleanedCount++;
            }
        }

        if ($cleanedCount === 0) {
            $this->info($isRestart ? '  No jobs to clean up' : '  No stuck jobs found (all jobs running normally)');
        }

        return $cleanedCount;
    }
}
