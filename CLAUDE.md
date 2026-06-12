# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Coolify is an open-source, self-hostable PaaS (alternative to Heroku/Netlify/Vercel). It manages servers, applications, databases, and services via SSH. Built with Laravel 12 (using Laravel 10 file structure), Livewire 3, and Tailwind CSS v4.

## Design Reference

For UI/UX design specifications, principles, and visual standards, consult `DESIGN.md` in the [coollabsio/architecture](https://github.com/coollabsio/architecture) repo.

## Development Environment

Docker Compose-based dev setup with services: coolify (app), postgres, redis, soketi (WebSockets), vite, testing-host, mailpit, minio.

```bash
# Start dev environment (uses docker-compose.dev.yml)
spin up                          # or: docker compose -f docker-compose.dev.yml up -d
spin down                        # stop services
```

The app runs at `localhost:8000` by default. Vite dev server on port 5173.

## Common Commands

```bash
# Tests (Pest 4)
php artisan test --compact                          # all tests
php artisan test --compact --filter=testName         # single test
php artisan test --compact tests/Feature/SomeTest.php  # specific file

# Code formatting (Pint, Laravel preset)
vendor/bin/pint --dirty --format agent              # format changed files

# Frontend
npm run dev                     # vite dev server
npm run build                   # production build
```

## Architecture

### Backend Structure (app/)
- **Actions/** — Domain actions organized by area (Application, Database, Docker, Proxy, Server, Service, Shared, Stripe, User, CoolifyTask, Fortify). Uses `lorisleiva/laravel-actions` with `AsAction` trait — actions can be called as objects, dispatched as jobs, or used as controllers.
- **Livewire/** — All UI components (Livewire 3). Pages organized by domain: Server, Project, Settings, Security, Notifications, Terminal, Subscription, SharedVariables. This is the primary UI layer — no traditional Blade controllers. Components listen to private team channels for real-time status updates via Soketi.
- **Jobs/** — Queue jobs for deployments (`ApplicationDeploymentJob`), backups, Docker cleanup, server management, proxy configuration. Uses Redis queue with Horizon for monitoring.
- **Models/** — Eloquent models extending `BaseModel` which provides auto-CUID2 UUID generation. Key models: `Server`, `Application`, `Service`, `Project`, `Environment`, `Team`, plus standalone database models (`StandalonePostgresql`, `StandaloneMysql`, etc.). Common traits: `HasConfiguration`, `HasMetrics`, `HasSafeStringAttribute`, `ClearsGlobalSearchCache`.
- **Services/** — Business logic services (ConfigurationGenerator, DockerImageParser, ContainerStatusAggregator, HetznerService, etc.). Use Services for complex orchestration; use Actions for single-purpose domain operations.
- **Helpers/** — Global helpers loaded via `bootstrap/includeHelpers.php` from `bootstrap/helpers/` — organized into `shared.php`, `constants.php`, `versions.php`, `subscriptions.php`, `domains.php`, `docker.php`, `services.php`, `github.php`, `proxy.php`, `notifications.php`.
- **Data/** — Spatie Laravel Data DTOs (e.g., `ServerMetadata`).
- **Enums/** — PHP enums (TitleCase keys). Key enums: `ProcessStatus`, `Role` (MEMBER/ADMIN/OWNER with rank comparison), `BuildPackTypes`, `ProxyTypes`, `ContainerStatusTypes`.
- **Rules/** — Custom validation rules (`ValidGitRepositoryUrl`, `ValidServerIp`, `ValidHostname`, `DockerImageFormat`, etc.).

### API Layer
- REST API at `/api/v1/` with OpenAPI 3.0 attributes (`use OpenApi\Attributes as OA`) for auto-generated docs
- Authentication via Laravel Sanctum with custom `ApiAbility` middleware for token abilities (read, write, deploy)
- `ApiSensitiveData` middleware masks sensitive fields (IDs, credentials) in responses
- API controllers in `app/Http/Controllers/Api/` use inline `Validator` (not Form Request classes)
- Response serialization via `serializeApiResponse()` helper

### Authorization
- Policy-based authorization with ~15 model-to-policy mappings in `AuthServiceProvider`
- Custom gates: `createAnyResource`, `canAccessTerminal`
- Role hierarchy: `Role::MEMBER` (1) < `Role::ADMIN` (2) < `Role::OWNER` (3) with `lt()`/`gt()` comparison methods
- Multi-tenancy via Teams — team auto-initializes notification settings on creation

### Event Broadcasting
- Soketi WebSocket server for real-time updates (ports 6001-6002 in dev)
- Status change events: `ApplicationStatusChanged`, `ServiceStatusChanged`, `DatabaseStatusChanged`, `ProxyStatusChanged`
- Livewire components subscribe to private team channels via `getListeners()`

### Key Domain Concepts
- **Server** — A managed host connected via SSH. Has settings, proxy config, and destinations.
- **Application** — A deployed app (from Git or Docker image) with environment variables, previews, deployment queue.
- **Service** — A pre-configured service stack from templates (`templates/service-templates-latest.json`).
- **Standalone Databases** — Individual database instances (Postgres, MySQL, MariaDB, MongoDB, Redis, Clickhouse, KeyDB, Dragonfly).
- **Project/Environment** — Organizational hierarchy: Team → Project → Environment → Resources.
- **Proxy** — Traefik reverse proxy managed per server.

### Frontend
- Livewire 3 components with Alpine.js for client-side interactivity
- Blade templates in `resources/views/livewire/`
- Tailwind CSS v4 with `@tailwindcss/forms` and `@tailwindcss/typography`
- Vite for asset bundling

### Laravel 10 Structure (NOT Laravel 11+ slim structure)
- Middleware in `app/Http/Middleware/` — custom middleware includes `CheckForcePasswordReset`, `DecideWhatToDoWithUser`, `ApiAbility`, `ApiSensitiveData`
- Kernels: `app/Http/Kernel.php`, `app/Console/Kernel.php`
- Exception handler: `app/Exceptions/Handler.php`
- Service providers in `app/Providers/`

## Key Conventions

- Use `php artisan make:*` commands with `--no-interaction` to create files
- Use Eloquent relationships, avoid `DB::` facade — prefer `Model::query()`
- PHP 8.4: constructor property promotion, explicit return types, type hints
- Validation uses inline `Validator` facade in controllers/Livewire components and custom rules in `app/Rules/` — not Form Request classes
- Run `vendor/bin/pint --dirty --format agent` before finalizing changes
- Every change must have tests — write or update tests, then run them. For bug fixes, follow TDD: write a failing test first, then fix the bug (see Test Enforcement below)
- Check sibling files for conventions before creating new files

## Git Workflow

- Main branch: `v4.x`
- Development branch: `next`
- PRs should target `v4.x`

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/mcp (MCP) - v0
- laravel/nightwatch (NIGHTWATCH) - v1
- laravel/pail (PAIL) - v1
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/socialite (SOCIALITE) - v5
- livewire/livewire (LIVEWIRE) - v3
- laravel/boost (BOOST) - v2
- laravel/dusk (DUSK) - v8
- laravel/pint (PINT) - v1
- laravel/telescope (TELESCOPE) - v5
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- This project upgraded from Laravel 10 without migrating to the new streamlined Laravel file structure.
- This is perfectly fine and recommended by Laravel. Follow the existing structure from Laravel 10. We do not need to migrate to the new Laravel structure unless the user explicitly requests it.

## Laravel 10 Structure

- Middleware typically lives in `app/Http/Middleware/` and service providers in `app/Providers/`.
- There is no `bootstrap/app.php` application configuration in a Laravel 10 structure:
    - Middleware registration happens in `app/Http/Kernel.php`
    - Exception handling is in `app/Exceptions/Handler.php`
    - Console commands and schedule register in `app/Console/Kernel.php`
    - Rate limits likely exist in `RouteServiceProvider` or `app/Http/Kernel.php`

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
