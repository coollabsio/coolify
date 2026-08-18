---
name: laravel-best-practices
description: "Apply this skill whenever writing, reviewing, or refactoring Laravel PHP code. This includes creating or modifying controllers, models, migrations, form requests, policies, jobs, scheduled commands, service classes, and Eloquent queries. Triggers for N+1 and query performance issues, caching strategies, authorization and security patterns, validation, error handling, queue and job configuration, route definitions, and architectural decisions. Also use for Laravel code reviews and refactoring existing Laravel code to follow best practices. Covers any task involving Laravel backend PHP code patterns."
license: MIT
metadata:
  author: laravel
---

# Laravel Best Practices

Best practices for Laravel, prioritized by impact. Each rule teaches what to do and why. For exact API syntax, verify with `search-docs`.

## Consistency First

Before applying any rule, check what the application already does. Laravel offers multiple valid approaches — the best choice is the one the codebase already uses, even if another pattern would be theoretically better. Inconsistency is worse than a suboptimal pattern.

Check sibling files, related controllers, models, or tests for established patterns. If one exists, follow it — don't introduce a second way. These rules are defaults for when no pattern exists yet, not overrides.

## Quick Reference

### 1. Database Performance → `rules/db-performance.md`

- Eager load with `with()` to prevent N+1 queries
- Enable `Model::preventLazyLoading()` in development
- Select only needed columns, avoid `SELECT *`
- `chunk()` / `chunkById()` for large datasets
- Index columns used in `WHERE`, `ORDER BY`, `JOIN`
- `withCount()` instead of loading relations to count
- `cursor()` for memory-efficient read-only iteration
- Never query in Blade templates

### 2. Advanced Query Patterns → `rules/advanced-queries.md`

- `addSelect()` subqueries over eager-loading entire has-many for a single value
- Dynamic relationships via subquery FK + `belongsTo`
- Conditional aggregates (`CASE WHEN` in `selectRaw`) over multiple count queries
- `setRelation()` to prevent circular N+1 queries
- `whereIn` + `pluck()` over `whereHas` for better index usage
- Two simple queries can beat one complex query
- Compound indexes matching `orderBy` column order
- Correlated subqueries in `orderBy` for has-many sorting (avoid joins)

### 3. Security → `rules/security.md`

- Define `$fillable` or `$guarded` on every model, authorize every action via policies or gates
- No raw SQL with user input — use Eloquent or query builder
- `{{ }}` for output escaping, `@csrf` on all POST/PUT/DELETE forms, `throttle` on auth and API routes
- Validate MIME type, extension, and size for file uploads
- Never commit `.env`, use `config()` for secrets, `encrypted` cast for sensitive DB fields

### 4. Caching → `rules/caching.md`

- `Cache::remember()` over manual get/put
- `Cache::flexible()` for stale-while-revalidate on high-traffic data
- `Cache::memo()` to avoid redundant cache hits within a request
- Cache tags to invalidate related groups
- `Cache::add()` for atomic conditional writes
- `once()` to memoize per-request or per-object lifetime
- `Cache::lock()` / `lockForUpdate()` for race conditions
- Failover cache stores in production

### 5. Eloquent Patterns → `rules/eloquent.md`

- Correct relationship types with return type hints
- Local scopes for reusable query constraints
- Global scopes sparingly — document their existence
- Attribute casts in the `casts()` method
- Cast date columns, use Carbon instances in templates
- `whereBelongsTo($model)` for cleaner queries
- Never hardcode table names — use `(new Model)->getTable()` or Eloquent queries

### 6. Validation & Forms → `rules/validation.md`

- Form Request classes, not inline validation
- Array notation `['required', 'email']` for new code; follow existing convention
- `$request->validated()` only — never `$request->all()`
- `Rule::when()` for conditional validation
- `after()` instead of `withValidator()`

### 7. Configuration → `rules/config.md`

- `env()` only inside config files
- `App::environment()` or `app()->isProduction()`
- Config, lang files, and constants over hardcoded text

### 8. Testing Patterns → `rules/testing.md`

- `LazilyRefreshDatabase` over `RefreshDatabase` for speed
- `assertModelExists()` over raw `assertDatabaseHas()`
- Factory states and sequences over manual overrides
- Use fakes (`Event::fake()`, `Exceptions::fake()`, etc.) — but always after factory setup, not before
- `recycle()` to share relationship instances across factories

### 9. Queue & Job Patterns → `rules/queue-jobs.md`

- `retry_after` must exceed job `timeout`; use exponential backoff `[1, 5, 10]`
- `ShouldBeUnique` to prevent duplicates; `ShouldBeUniqueUntilProcessing` for early lock release
- Always implement `failed()`; with `retryUntil()`, set `$tries = 0`
- `RateLimited` middleware for external API calls; `Bus::batch()` for related jobs
- Horizon for complex multi-queue scenarios

### 10. Routing & Controllers → `rules/routing.md`

- Implicit route model binding
- Scoped bindings for nested resources
- `Route::resource()` or `apiResource()`
- Methods under 10 lines — extract to actions/services
- Type-hint Form Requests for auto-validation

### 11. HTTP Client → `rules/http-client.md`

- Explicit `timeout` and `connectTimeout` on every request
- `retry()` with exponential backoff for external APIs
- Check response status or use `throw()`
- `Http::pool()` for concurrent independent requests
- `Http::fake()` and `preventStrayRequests()` in tests

### 12. Events, Notifications & Mail → `rules/events-notifications.md`, `rules/mail.md`

- Event discovery over manual registration; `event:cache` in production
- `ShouldDispatchAfterCommit` / `afterCommit()` inside transactions
- Queue notifications and mailables with `ShouldQueue`
- On-demand notifications for non-user recipients
- `HasLocalePreference` on notifiable models
- `assertQueued()` not `assertSent()` for queued mailables
- Markdown mailables for transactional emails

### 13. Error Handling → `rules/error-handling.md`

- `report()`/`render()` on exception classes or in `bootstrap/app.php` — follow existing pattern
- `ShouldntReport` for exceptions that should never log
- Throttle high-volume exceptions to protect log sinks
- `dontReportDuplicates()` for multi-catch scenarios
- Force JSON rendering for API routes
- Structured context via `context()` on exception classes

### 14. Task Scheduling → `rules/scheduling.md`

- `withoutOverlapping()` on variable-duration tasks
- `onOneServer()` on multi-server deployments
- `runInBackground()` for concurrent long tasks
- `environments()` to restrict to appropriate environments
- `takeUntilTimeout()` for time-bounded processing
- Schedule groups for shared configuration

### 15. Architecture → `rules/architecture.md`

- Single-purpose Action classes; dependency injection over `app()` helper
- Prefer official Laravel packages and follow conventions, don't override defaults
- Default to `ORDER BY id DESC` or `created_at DESC`; `mb_*` for UTF-8 safety
- `defer()` for post-response work; `Context` for request-scoped data; `Concurrency::run()` for parallel execution

### 16. Migrations → `rules/migrations.md`

- Generate migrations with `php artisan make:migration`
- `constrained()` for foreign keys
- Never modify migrations that have run in production
- Add indexes in the migration, not as an afterthought
- Mirror column defaults in model `$attributes`
- Reversible `down()` by default; forward-fix migrations for intentionally irreversible changes
- One concern per migration — never mix DDL and DML

### 17. Collections → `rules/collections.md`

- Higher-order messages for simple collection operations
- `cursor()` vs. `lazy()` — choose based on relationship needs
- `lazyById()` when updating records while iterating
- `toQuery()` for bulk operations on collections

### 18. Blade & Views → `rules/blade-views.md`

- `$attributes->merge()` in component templates
- Blade components over `@include`; `@pushOnce` for per-component scripts
- View Composers for shared view data
- `@aware` for deeply nested component props

### 19. Conventions & Style → `rules/style.md`

- Follow Laravel naming conventions for all entities
- Prefer Laravel helpers (`Str`, `Arr`, `Number`, `Uri`, `Str::of()`, `$request->string()`) over raw PHP functions
- No JS/CSS in Blade, no HTML in PHP classes
- Code should be readable; comments only for config files

## How to Apply

Always use a sub-agent to read rule files and explore this skill's content.

1. Identify the file type and select relevant sections (e.g., migration → §16, controller → §1, §3, §5, §6, §10)
2. Check sibling files for existing patterns — follow those first per Consistency First
3. Verify API syntax with `search-docs` for the installed Laravel version
