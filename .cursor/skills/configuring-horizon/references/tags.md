# Tags & Silencing

## Where to Find It

Search with `search-docs`:
- `"horizon tags"` for the tagging API and auto-tagging behaviour
- `"horizon silenced jobs"` for the `silenced` and `silenced_tags` config options

## What to Watch For

### Eloquent model jobs are tagged automatically without any extra code

If a job's constructor accepts Eloquent model instances, Horizon automatically tags the job with `ModelClass:id` such as `App\Models\User:42`. These tags are filterable in the dashboard without any changes to the job class. Only add a `tags()` method when custom tags beyond auto-tagging are needed.

### `silenced` hides jobs from the dashboard completed list but does not stop them from running

Adding a job class to the `silenced` array in `config/horizon.php` removes it from the completed jobs view. The job still runs normally. This is a dashboard noise-reduction tool, not a way to disable jobs.

### `silenced_tags` hides all jobs carrying a matching tag from the completed list

Any job carrying a matching tag string is hidden from the completed jobs view. This is useful for silencing a category of jobs such as all jobs tagged `notifications`, rather than silencing specific classes.