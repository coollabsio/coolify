# Changelog

All notable changes to this project will be documented in this file.

## [5.0.0-alpha.1] - 2026-XX-XX

### Release Highlights

-

### Added

- **v4 to v5 upgrade migration**
  - Added Coolify v4 database as `old_pgsql` connection
  -
- Worker Servers which replace build servers with servers that can also run jobs (horizon workers) in addition to building docker images

### Changed

- Upgraded all Composer and Node dependencies and adopted their latest syntax and features, most notably: PHP to 8.5 (previously 8.2), TailwindCSS to v4.0 (previously v3) and Laravel to v12 (previously v10)
- **Docker:**
  - Upgraded all Docker dependencies, most notably: Postgres to v18 (previously v15) and Redis to v8 (previously v7)
  -
- **Laravel Configurations:**
  - Changed hashing algorithm from `bcrypt` to `argon2id` for enhanced security
  - Use Redis for sessions and expire inactive sessions after 24h (previously 14 days)
  - Encrypt user sessions data
  - Expire password reset tokens after 10 minutes (previously 60 minutes)
  - Jobs now wait for all DB transactions to be finished before being dispatched which prevents race conditions
  - Normal jobs (backups, emails, etc.) and deployment jobs now use separate supervisor configurations and defaults
  - Horizon workers are now restarted after 500 (job workers) or 300 (deployment workers) jobs or after 1 hour to clean up stale memory and CPU usage
  - Reduced default queue timeouts from 10h to 60s for jobs and 300s for deployments to prevent stale jobs
  - Increased `balanceCooldown` from 1s to 2s for jobs to reduce CPU spikes
  - Redirect Laravel logs to `stderr` so they can be viewed in docker logs
  - Configured production logging to rotate automatically and keep only the last 10 days of logs to reduce disk usage
  - Changed production log level from `debug` to `warning` to reduce disk usage and avoid logging sensitive information
  - Configured separate Redis connections for cache, jobs and sessions for easier debugging and separation
  - Updated all Laravel config files to the latest version and removed all unused config options
- Changed license from `Apache-2.0` to `AGPL-3.0`

### Deprecated

-

### Removed

- Session cleanup job as we now use Redis for sessions with a TTL
- A lot of legacy code, outdated configs and dependencies

### Fixed

- `laravel.log` file growing indefinitely and consuming excessive disk space
- Removed logging of failed jobs into the database as we use Horizon for that and it can cause excessive disk usage in some cases
- On v4 when changing the maximum concurrent builds setting to more than 4 builds the setting is no longer respected because there is a maximum of 4 horizon workers available by default
-

### Security

-

### Performance

-

### Maintenance

- **Testing:**
  - Added custom Architecture test that enforces Laravel & PHP best practices to ensure security and consistency across the codebase
- **Tooling:**
  - Added Rector & Rector Laravel with a strict configuration for automatic refactoring of the codebase
  - Added a strict custom Laravel Pint preset for consistent PHP formatting across the codebase
  - Added Larastan (PHPStan) Level `max` for code analysis and type checking
  - Added custom composer scripts to run refactors, formatting, linting, tests and type-coverage
  - Added strict `AppServiceProvider.php`:
    - Optionally enforce HTTPS for the Coolify dashboard
    - Enforce strong password validation rules in production
    - Disable destructive artisan commands in production
    - Automatically eager load all relationships to prevent N+1 queries
    - Configure models and enforce morph map for polymorphic relationships
    - Enforce immutable dates globally
    - Disable queue interruption polling to improve performance
    - Fake sleeps and prevent stray HTTP requests in testing
    - Prevent exception truncation in development
    - Use aggressive Vite prefetching for better performance
  - Only install Ray in development and remove all `ray()` calls from production code via Rector
  - Switched Node package manager from `NPM` to `Bun` for improved security and performance
  - Added `$schema` to `composer.json` and `package.json`
- **Coolify GitHub Repository:**
  - Pinned all GitHub actions to full length git SHAs to minimize the risk of supply chain attacks
  - Set permissions explicitly on each GitHub workflow to only give the minimum required permissions
  - Renamed all GitHub action workflows for improved clarity
  - Cancel in-progress action runs when a new run is triggered
  - Improved `SECURITY.md` formatting and wording and added the support policy for `v5.x`
  - Refactored the GitHub issue templates to use issue types and improved formatting and wording
  - Moved `README.md` assets into `.github/assets/` to more easily exclude them from the core repository code
  - Removed the `chore-remove-labels-and-assignees-on-close.yml` workflow as labels and assignees are now kept when closing Issues and PRs

### Refactored

- Completely refactored all database migrations for a cleaner, more consistent and stable database schema
- Completely refactored all database models
- Queues are now accessed via an enum instead of hardcoded strings

## Issues

- fixed: <https://github.com/coollabsio/coolify/issues/6407>
- fixed: <https://github.com/coollabsio/coolify/issues/3578>
- fixed:
