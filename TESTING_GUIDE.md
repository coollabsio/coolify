# Testing Guide: Scheduled Tasks Improvements

## Overview
This guide covers testing all the improvements made to the scheduled tasks system, including retry logic, timeout handling, and error logging.

## Jobs Modified

1. **CoolifyTask** - Infrastructure job for SSH operations (3 retries, 600s timeout)
2. **ScheduledTaskJob** - Scheduled container commands (3 retries, configurable timeout)
3. **DatabaseBackupJob** - Database backups (2 retries, existing timeout)

---

## Quick Test Commands

### Run Unit Tests (No Database Required)
```bash
./vendor/bin/pest tests/Unit/ScheduledJobsRetryConfigTest.php
```

### Run Feature Tests (Requires Database - Run in Docker)
```bash
docker exec coolify php artisan test --filter=CoolifyTaskRetryTest
```

---

## Manual Testing

### 1. Test ScheduledTaskJob ✅ (You tested this)

**How to test:**
1. Create a scheduled task in the UI
2. Set a short frequency (every minute)
3. Monitor execution in the UI
4. Check logs: `storage/logs/scheduled-errors-2025-11-09.log`

**What to verify:**
- Task executes successfully
- Duration is recorded (in seconds with 2 decimal places)
- Retry count is tracked
- Timeout configuration is respected

---

### 2. Test DatabaseBackupJob ✅ (You tested this)

**How to test:**
1. Create a scheduled database backup
2. Set frequency to manual or very short interval
3. Trigger backup manually or wait for schedule
4. Check logs for any errors

**What to verify:**
- Backup completes successfully
- Retry logic works if there's a transient failure
- Error logging is consistent
- Backoff timing is correct (60s, 300s)

---

### 3. Test CoolifyTask ⚠️ (IMPORTANT - Not tested yet)

CoolifyTask is used throughout the application for ALL SSH operations. Here are multiple ways to test it:

#### **Option A: Server Validation** (Easiest)
1. Go to **Servers** in Coolify UI
2. Select any server
3. Click **"Validate Server"** or **"Check Connection"**
4. This triggers CoolifyTask jobs
5. Check Horizon dashboard for job processing
6. Check logs: `storage/logs/scheduled-errors-2025-11-09.log`

#### **Option B: Container Operations**
1. Go to any **Application** or **Service**
2. Try these actions (each triggers CoolifyTask):
   - Restart container
   - View logs
   - Execute command in container
3. Monitor Horizon for job processing
4. Check logs for errors

#### **Option C: Application Deployment**
1. Deploy or redeploy any application
2. This triggers MANY CoolifyTask jobs
3. Watch Horizon dashboard - you should see:
   - Jobs being dispatched
   - Jobs completing successfully
   - If any fail, they should retry (check "Failed Jobs")
4. Check logs for retry attempts

#### **Option D: Docker Cleanup**
1. Wait for or trigger Docker cleanup (runs on schedule)
2. This uses CoolifyTask for cleanup commands
3. Check logs: `storage/logs/scheduled-errors-2025-11-09.log`

---

## Monitoring & Verification

### Horizon Dashboard
1. Open Horizon: `/horizon`
2. Watch these sections:
   - **Recent Jobs** - See jobs being processed
   - **Failed Jobs** - Jobs that failed permanently after retries
   - **Monitoring** - Job throughput and wait times

### Log Monitoring
```bash
# Watch scheduled errors in real-time
tail -f storage/logs/scheduled-errors-2025-11-09.log

# Check for specific job errors
grep "CoolifyTask" storage/logs/scheduled-errors-2025-11-09.log
grep "ScheduledTaskJob" storage/logs/scheduled-errors-2025-11-09.log
grep "DatabaseBackupJob" storage/logs/scheduled-errors-2025-11-09.log
```

### Database Verification
```sql
-- Check execution tracking
SELECT * FROM scheduled_task_executions
ORDER BY created_at DESC
LIMIT 10;

-- Verify duration is decimal (not throwing errors)
SELECT id, duration, retry_count, started_at, finished_at
FROM scheduled_task_executions
WHERE duration IS NOT NULL;

-- Check for tasks with retries
SELECT * FROM scheduled_task_executions
WHERE retry_count > 0;
```

---

## Expected Behavior

### ✅ Success Indicators

1. **Jobs Complete Successfully**
   - Horizon shows completed jobs
   - No errors in scheduled-errors log
   - Execution records in database

2. **Retry Logic Works**
   - Failed jobs retry automatically
   - Backoff timing is respected (30s, 60s, etc.)
   - Jobs marked failed only after all retries exhausted

3. **Timeout Enforcement**
   - Long-running jobs terminate at timeout
   - Timeout is configurable per task
   - No hanging jobs

4. **Error Logging**
   - All errors logged to `storage/logs/scheduled-errors-2025-11-09.log`
   - Consistent format with job name, attempt count, error details
   - Trace included for debugging

5. **Execution Tracking**
   - Duration recorded correctly (decimal with 2 places)
   - Retry count incremented on failures
   - Started/finished timestamps accurate

---

## Troubleshooting

### Issue: Jobs fail immediately without retrying
**Check:**
- Verify `$tries` property is set on the job
- Check if exception is being caught and re-thrown correctly
- Look for `maxExceptions` being reached

### Issue: "Invalid text representation" errors
**Fix Applied:**
- Duration field changed from integer to decimal(10,2)
- If you see this, run migrations again

### Issue: Jobs not appearing in Horizon
**Check:**
- Horizon is running (`php artisan horizon`)
- Queue workers are active
- Job is dispatched to correct queue ('high' for these jobs)

### Issue: Timeout not working
**Check:**
- Timeout is set on job (CoolifyTask: 600s, ScheduledTask: configurable)
- PHP `max_execution_time` allows job timeout
- Queue worker timeout is higher than job timeout

---

## Test Checklist

- [ ] Unit tests pass: `./vendor/bin/pest tests/Unit/ScheduledJobsRetryConfigTest.php`
- [ ] ScheduledTaskJob tested manually ✅
- [ ] DatabaseBackupJob tested manually ✅
- [ ] CoolifyTask tested manually (server validation, container ops, or deployment)
- [ ] Retry logic verified (force a failure, watch retry attempts)
- [ ] Timeout enforcement tested (create long-running task with short timeout)
- [ ] Error logs checked: `storage/logs/scheduled-errors-2025-11-09.log`
- [ ] Horizon dashboard shows jobs processing correctly
- [ ] Database execution records show duration as decimal
- [ ] UI shows timeout configuration field for scheduled tasks

---

## Next Steps After Testing

1. If all tests pass, run migrations on production/staging:
   ```bash
   php artisan migrate
   ```

2. Monitor logs for the first 24 hours:
   ```bash
   tail -f storage/logs/scheduled-errors-2025-11-09.log
   ```

3. Check Horizon for any failed jobs needing attention

4. Verify existing scheduled tasks now have retry capability

---

## Questions?

If you encounter issues:
1. Check `storage/logs/scheduled-errors-2025-11-09.log` first
2. Check `storage/logs/laravel.log` for general errors
3. Look at Horizon "Failed Jobs" for detailed error info
4. Review database execution records for patterns
