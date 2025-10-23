# Fix for Stale Lock Issue in ScheduledJobManager

## Issue
GitHub Issue: #4539 - Scheduled tasks not executing on schedule

### Symptoms
- Scheduled tasks stop executing after working for weeks/months
- Backups don't run
- Auto-updates don't work
- Error in Horizon: `Illuminate\Queue\MaxAttemptsExceededException: App\Jobs\ScheduledJobManager has been attempted too many times`
- Running `horizon:clear`, `cleanup:redis`, `schedule:clear-cache` doesn't fix the problem

## Root Cause

The `ScheduledJobManager` was using `WithoutOverlapping` middleware with only `releaseAfter(60)`:

```php
(new WithoutOverlapping('scheduled-job-manager'))
    ->releaseAfter(60)
```

**Problems with this approach:**

1. **No automatic lock expiration**: Without `expireAfter()`, locks persist indefinitely if:
   - Process hangs or becomes unresponsive
   - Job takes longer than expected
   - Unexpected termination occurs

2. **Race condition with releaseAfter()**:
   - Job acquires lock
   - Job gets stuck/hangs
   - After 60s, job is released back to queue
   - New attempt can't acquire lock (still held by hung process)
   - Repeats until MaxAttemptsExceededException

3. **Against Laravel best practices**: Laravel docs explicitly recommend using `expireAfter()` to prevent stale locks

## Solution

This fix has two parts:

### Part 1: Prevention (Fix Future Locks)

Changed the middleware to match the pattern used by other Coolify jobs:

```php
// File: app/Jobs/ScheduledJobManager.php
(new WithoutOverlapping('scheduled-job-manager'))
    ->expireAfter(60)   // Lock expires after 1 minute (matches job frequency)
    ->dontRelease()     // Don't re-queue on lock conflict
```

### Part 2: Recovery (Clear Existing Stale Locks)

Enhanced `cleanup:redis` command to clear existing stale locks:

```php
// File: app/Console/Commands/CleanupRedis.php
// Added --clear-locks flag
php artisan cleanup:redis --clear-locks
```

**What it does:**
- Scans Redis for `laravel-queue-overlap` keys (WithoutOverlapping locks)
- Checks TTL of each lock
- Deletes locks with TTL = -1 (no expiration = stale!)
- Skips active locks that have proper expiration
- Called automatically during `app:init` (on Coolify startup/update)

### Why This Works

✅ **Auto-expiring locks**: Lock automatically expires after 60 seconds, even if:
   - Process crashes
   - Job hangs
   - Network issues occur

✅ **No retry storms**: `dontRelease()` prevents failed jobs from being re-queued repeatedly

✅ **Consistent pattern**: Matches other Coolify jobs like:
   - `DockerCleanupJob`: `expireAfter(600)->dontRelease()`
   - `ServerCheckJob`: `expireAfter(60)->dontRelease()`
   - `RestartProxyJob`: `expireAfter(60)->dontRelease()`

✅ **Laravel recommended**: Follows official Laravel documentation for preventing stale locks

### Why 60 Seconds?

- Job runs **every minute** (`everyMinute()` schedule)
- Matches the job frequency (1:1 ratio)
- Matches `CleanupInstanceStuffsJob` pattern (also runs frequently with 60s expiry)
- Allows next cycle to run if current job hangs
- Still reasonable timeout to prevent long-held locks

## Testing

### Manual Lock Key Inspection

To check for locks in Redis:

```bash
docker exec -it coolify-redis redis-cli
SELECT 0
KEYS *laravel-queue-overlap*ScheduledJobManager*
```

Full key format:
```
coolify_development_database_coolify_development_cache_laravel-queue-overlap:App\Jobs\ScheduledJobManager:scheduled-job-manager
```

Check TTL:
```bash
TTL "<full-key-from-above>"
```

- `-1` = No expiration (STALE LOCK - the bug!)
- `-2` = Key doesn't exist
- Positive number = Seconds until expiration (GOOD!)

### Testing the Fix

Created test jobs to demonstrate the fix:
- `TestStaleLockJob.php` - Uses broken pattern (`releaseAfter` only)
- `TestFixedLockJob.php` - Uses fixed pattern (`expireAfter` + `dontRelease`)

## Impact

This fix will:
- ✅ **Immediate recovery**: Existing stale locks cleared on upgrade/restart
- ✅ **Future prevention**: New locks auto-expire, preventing issue recurrence
- ✅ **Self-recovery**: System can recover from transient issues automatically
- ✅ **Zero manual intervention**: No need for users to manually clear locks
- ✅ **Reliable operations**: Backups, tasks, and auto-updates run consistently

## Files Modified

1. **app/Jobs/ScheduledJobManager.php**
   - Changed middleware to use `expireAfter(120)->dontRelease()`

2. **app/Console/Commands/CleanupRedis.php**
   - Added `--clear-locks` flag
   - Added `cleanupCacheLocks()` method

3. **app/Console/Commands/Init.php**
   - Updated to call `cleanup:redis --clear-locks` on startup

4. **tests/Unit/ScheduledJobManagerLockTest.php**
   - New unit test to prevent regression

## References

- Laravel Docs: https://laravel.com/docs/12.x/queues#preventing-job-overlaps
- GitHub Issue: https://github.com/coollabsio/coolify/issues/4539
- Related Pattern: All other Coolify jobs use `expireAfter()->dontRelease()`
