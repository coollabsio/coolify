---
type: changed
commit: 06c41f57d6
---
The notifications of scheduled jobs that keep failing, to the first failure and then at most once a day

- This covers scheduled tasks, database backups, storage backups and Docker cleanup
- The first successful run after a failure sends one "works again" notification to the failure channels
- A database backup whose S3 upload keeps failing also notifies at most once a day
- A scheduled task disables itself after 10 failures in a row when the first failure is more than 24 hours old, one notification and the task page give the reason, and enabling the task again clears it
- The scheduled tasks API returns `consecutive_failures`, `failing_since` and `disabled_reason`
- Backups and Docker cleanup are never disabled automatically, so they start again by themselves after an outage
- A scheduled task skips a run when the application or service has no running container, without a notification and without a count toward the automatic disable
