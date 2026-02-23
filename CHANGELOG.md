# Changelog

All notable changes to this project will be documented in this file.

## [5.0.0-alpha.1] - 2026-XX-XX

<!-- one or two sentence summary of this version -->

### Breaking Changes

-

### Security

-

### Added

- **v4 to v5 upgrade migration**
  - Coolify v4 database as `old_pgsql` connection
  -
- Worker Servers that replace build servers with servers that can also run jobs (Horizon workers) in addition to building Docker images

### Changed

- Upgrade PHP from 8.2 to 8.5, TailwindCSS from v3 to v4.0, Laravel from v10 to v12 and all other Composer and Node dependencies to their latest versions and syntax
- **Docker**
  - Upgrade Postgres from v15 to v18, Redis from v7 to v8 and all other Docker dependencies to latest
  -
- **Laravel Configurations**
  - Hashing algorithm from `bcrypt` to `argon2id` for enhanced security
  - Session driver to Redis with inactive sessions expiring after 24h (previously 14 days)
  - Encrypted user session data
  - Password reset token expiration from 60 minutes to 10 minutes
  - Jobs dispatch only after all DB transactions complete, preventing race conditions
  - Normal jobs (backups, emails, etc.) and deployment jobs to separate supervisor configurations and defaults
  - Horizon worker restart threshold to 500 jobs (job workers) or 300 jobs (deployment workers) or 1 hour to clean up stale memory and CPU usage
  - Default queue timeouts from 10h to 60s for jobs and 300s for deployments to prevent stale jobs
  - `balanceCooldown` from 1s to 2s for jobs to reduce CPU spikes
  - Laravel logs to `stderr` so they can be viewed in Docker logs
  - Production logging to rotate automatically and keep only the last 10 days of logs to reduce disk usage
  - Production log level from `debug` to `warning` to reduce disk usage and avoid logging sensitive information
  - Redis connections to separate instances for cache, jobs and sessions for easier debugging and separation
  - Upgrade all Laravel config files to the latest version and remove unused options
- License from `Apache-2.0` to `AGPL-3.0`

### Deprecated

-

### Fixed

- `laravel.log` file growing indefinitely and consuming excessive disk space
- Failed jobs being logged into the database (Horizon already handles this) causing excessive disk usage in some cases
- Maximum concurrent builds setting not being respected when set to more than 4 on v4.x because only 4 Horizon workers are available by default
-

### Removed

- Session cleanup job as we now use Redis for sessions with a TTL
- A lot of legacy code, outdated configs and dependencies

### Refactored

- All database migrations for a cleaner, more consistent and stable database schema
- All database models for improved maintainability
- Replace hardcoded queue strings with a `ProcessingQueue` enum
- `config/constants.php` to `config/coolify.php` for all Coolify-specific settings
- Environment variable naming to be shorter and more consistent

### Maintenance

- **Testing**
  - Add custom Architecture test that enforces Laravel and PHP best practices to ensure security and consistency across the codebase
- **Tooling**
  - Add Rector & Rector Laravel with a strict configuration for automatic refactoring of the codebase
  - Add a strict custom Laravel Pint preset for consistent PHP formatting across the codebase
  - Add Larastan (PHPStan) level `max` for code analysis and type checking
  - Add custom Composer scripts to run refactors, formatting, linting, tests and type-coverage
  - Add strict `AppServiceProvider.php`
    - Optionally enforce HTTPS for the Coolify dashboard
    - Enforce strong password validation rules in production
    - Disable destructive Artisan commands in production
    - Automatically eager load all relationships to prevent N+1 queries
    - Configure models and enforce morph map for polymorphic relationships
    - Enforce immutable dates globally
    - Disable queue interruption polling to improve performance
    - Fake sleeps and prevent stray HTTP requests in testing
    - Prevent exception truncation in development
    - Use aggressive Vite prefetching for better performance
  - Only install Ray in development and remove all `ray()` calls from production code via Rector
  - Switch Node package manager from `NPM` to `Bun` for improved security and performance
  - Add `$schema` to `composer.json` and `package.json`
- **Coolify GitHub Repository**
  - Pin all GitHub Actions to full-length git SHAs to minimize the risk of supply chain attacks
  - Set permissions explicitly on each GitHub workflow to only give the minimum required permissions
  - Rename all GitHub Action workflows for improved clarity
  - Cancel in-progress action runs when a new run is triggered
  - Improve `SECURITY.md` formatting and wording and add the support policy for `v5.x`
  - Add 3 PR templates: `version.md` for release PRs, `default.md` for non-version PRs and a contributor template as the default for external PRs
  - Improve the GitHub issue templates to use issue types and improve formatting and wording
  - Move `README.md` assets into `.github/assets/` to more easily exclude them from the core repository code
  - Remove the `chore-remove-labels-and-assignees-on-close.yml` workflow as labels and assignees are now kept when closing issues and PRs

### Issues

- Fixes <https://github.com/coollabsio/coolify/issues/6407>
- Fixes <https://github.com/coollabsio/coolify/issues/3578>
- Fixes
