# CLAUDE.md

This file provides guidance to **Claude Code** (claude.ai/code) when working with code in this repository.

> **Note for AI Assistants**: This file is specifically for Claude Code. All detailed documentation is in the `.ai/` directory. Both Claude Code and Cursor IDE use the same source files in `.ai/` for consistency.
>
> **Maintaining Instructions**: When updating AI instructions, see [.ai/meta/sync-guide.md](.ai/meta/sync-guide.md) and [.ai/meta/maintaining-docs.md](.ai/meta/maintaining-docs.md) for guidelines.

## Project Overview

Coolify is an open-source, self-hostable platform for deploying applications and managing servers - an alternative to Heroku/Netlify/Vercel. It's built with Laravel (PHP) and uses Docker for containerization.

## Development Commands

### Frontend Development
- `npm run dev` - Start Vite development server for frontend assets
- `npm run build` - Build frontend assets for production

### Backend Development
Only run artisan commands inside "coolify" container when in development.
- `php artisan serve` - Start Laravel development server
- `php artisan migrate` - Run database migrations
- `php artisan queue:work` - Start queue worker for background jobs
- `php artisan horizon` - Start Laravel Horizon for queue monitoring
- `php artisan tinker` - Start interactive PHP REPL

### Code Quality
- `./vendor/bin/pint` - Run Laravel Pint for code formatting
- `./vendor/bin/phpstan` - Run PHPStan for static analysis
- `./vendor/bin/pest tests/Unit` - Run unit tests only (no database, can run outside Docker)
- `./vendor/bin/pest` - Run ALL tests (includes Feature tests, may require database)

### Running Tests
**IMPORTANT**: Tests that require database connections MUST be run inside the Docker container:
- **Inside Docker**: `docker exec coolify php artisan test` (for feature tests requiring database)
- **Outside Docker**: `./vendor/bin/pest tests/Unit` (for pure unit tests without database dependencies)
- Unit tests should use mocking and avoid database connections
- Feature tests that require database must be run in the `coolify` container

## Architecture Overview

### Technology Stack
- **Backend**: Laravel 12.4.1 (PHP 8.4.7)
- **Frontend**: Livewire 3.5.20 with Alpine.js and Tailwind CSS 4.1.4
- **Database**: PostgreSQL 15 (primary), Redis 7 (cache/queues)
- **Real-time**: Soketi (WebSocket server)
- **Containerization**: Docker & Docker Compose
- **Queue Management**: Laravel Horizon 5.30.3

> **Note**: For complete version information and all dependencies, see [.ai/core/technology-stack.md](.ai/core/technology-stack.md)

### Key Components

#### Core Models
- `Application` - Deployed applications with Git integration (74KB, highly complex)
- `Server` - Remote servers managed by Coolify (46KB, complex)
- `Service` - Docker Compose services (58KB, complex)
- `Database` - Standalone database instances (PostgreSQL, MySQL, MongoDB, Redis, etc.)
- `Team` - Multi-tenancy support
- `Project` - Grouping of environments and resources
- `Environment` - Environment isolation (staging, production, etc.)

#### Job System
- Uses Laravel Horizon for queue management
- Key jobs: `ApplicationDeploymentJob`, `ServerCheckJob`, `DatabaseBackupJob`
- `ServerManagerJob` and `ServerConnectionCheckJob` handle job scheduling

#### Deployment Flow
1. Git webhook triggers deployment
2. `ApplicationDeploymentJob` handles build and deployment
3. Docker containers are managed on target servers
4. Proxy configuration (Nginx/Traefik) is updated

#### Server Management
- SSH-based server communication via `ExecuteRemoteCommand` trait
- Docker installation and management
- Proxy configuration generation
- Resource monitoring and cleanup

### Directory Structure
- `app/Actions/` - Domain-specific actions (Application, Database, Server, etc.)
- `app/Jobs/` - Background queue jobs
- `app/Livewire/` - Frontend components (full-stack with Livewire)
- `app/Models/` - Eloquent models
- `app/Rules/` - Custom validation rules
- `app/Http/Middleware/` - HTTP middleware
- `bootstrap/helpers/` - Helper functions for various domains
- `database/migrations/` - Database schema evolution
- `routes/` - Application routing (web.php, api.php, webhooks.php, channels.php)
- `resources/views/livewire/` - Livewire component views
- `tests/` - Pest tests (Feature and Unit)

## Development Guidelines

### Frontend Philosophy
Coolify uses a **server-side first** approach with minimal JavaScript:
- **Livewire** for server-side rendering with reactive components
- **Alpine.js** for lightweight client-side interactions
- **Tailwind CSS** for utility-first styling with dark mode support
- **Enhanced Form Components** with built-in authorization system
- Real-time updates via WebSocket without page refreshes

### Form Authorization Pattern
**IMPORTANT**: When creating or editing forms, ALWAYS include authorization:

#### For Form Components (Input, Select, Textarea, Checkbox, Button):
Use `canGate` and `canResource` attributes for automatic authorization:
```html
<x-forms.input canGate="update" :canResource="$resource" id="name" label="Name" />
<x-forms.select canGate="update" :canResource="$resource" id="type" label="Type">...</x-forms.select>
<x-forms.checkbox instantSave canGate="update" :canResource="$resource" id="enabled" label="Enabled" />
<x-forms.button canGate="update" :canResource="$resource" type="submit">Save</x-forms.button>
```

#### For Modal Components:
Wrap with `@can` directives:
```html
@can('update', $resource)
    <x-modal-confirmation title="Confirm Action?" buttonTitle="Confirm">...</x-modal-confirmation>
    <x-modal-input buttonTitle="Edit" title="Edit Settings">...</x-modal-input>
@endcan
```

#### In Livewire Components:
Always add the `AuthorizesRequests` trait and check permissions:
```php
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class MyComponent extends Component
{
    use AuthorizesRequests;
    
    public function mount()
    {
        $this->authorize('view', $this->resource);
    }
    
    public function update()
    {
        $this->authorize('update', $this->resource);
        // ... update logic
    }
}
```

### Livewire Component Structure
- Components located in `app/Livewire/`
- Views in `resources/views/livewire/`
- State management handled on the server
- Use wire:model for two-way data binding
- Dispatch events for component communication
- **CRITICAL**: Livewire component views **MUST** have exactly ONE root element. ALL content must be contained within this single root element. Placing ANY elements (`<style>`, `<script>`, `<div>`, comments, or any other HTML) outside the root element will break Livewire's component tracking and cause `wire:click` and other directives to fail silently.

### Code Organization Patterns
- **Actions Pattern**: Use Actions for complex business logic (`app/Actions/`)
- **Livewire Components**: Handle UI and user interactions
- **Jobs**: Handle asynchronous operations
- **Traits**: Provide shared functionality (e.g., `ExecuteRemoteCommand`)
- **Helper Functions**: Domain-specific helpers in `bootstrap/helpers/`

### Database Patterns
- Use Eloquent ORM for database interactions
- Implement relationships properly (HasMany, BelongsTo, etc.)
- Use database transactions for critical operations
- Leverage query scopes for reusable queries
- Apply indexes for performance-critical queries
- **CRITICAL**: When adding new database columns, ALWAYS update the model's `$fillable` array to allow mass assignment

### Security Best Practices
- **Authentication**: Multi-provider auth via Laravel Fortify & Sanctum
- **Authorization**: Team-based access control with policies and enhanced form components
- **Form Component Security**: Built-in `canGate` authorization system for UI components
- **API Security**: Token-based auth with IP allowlisting
- **Secrets Management**: Never log or expose sensitive data
- **Input Validation**: Always validate user input with Form Requests or Rules
- **SQL Injection Prevention**: Use Eloquent ORM or parameterized queries

### API Development
- RESTful endpoints in `routes/api.php`
- Use API Resources for response formatting
- Implement rate limiting for public endpoints
- Version APIs when making breaking changes
- Document endpoints with clear examples

### Testing Strategy
- **Framework**: Pest for expressive testing
- **Structure**: Feature tests for user flows, Unit tests for isolated logic
- **Coverage**: Test critical paths and edge cases
- **Mocking**: Use Laravel's built-in mocking for external services
- **Database**: Use RefreshDatabase trait for test isolation

#### Test Execution Environment
**CRITICAL**: Database-dependent tests MUST run inside Docker container:
- **Unit Tests** (`tests/Unit/`): Should NOT use database. Use mocking. Run with `./vendor/bin/pest tests/Unit`
- **Feature Tests** (`tests/Feature/`): May use database. MUST run inside Docker with `docker exec coolify php artisan test`
- If a test needs database (factories, migrations, etc.), it belongs in `tests/Feature/`
- Always mock external services and SSH connections in tests

#### Test Design Philosophy
**PREFER MOCKING**: When designing features and writing tests:
- **Design for testability**: Structure code so it can be tested without database (use dependency injection, interfaces)
- **Mock by default**: Unit tests should mock models and external dependencies using Mockery
- **Avoid database when possible**: If you can test the logic without database, write it as a Unit test
- **Only use database when necessary**: Feature tests should test integration points, not isolated logic
- **Example**: Instead of `Server::factory()->create()`, use `Mockery::mock('App\Models\Server')` in unit tests

### Routing Conventions
- Group routes by middleware and prefix
- Use route model binding for cleaner controllers
- Name routes consistently (resource.action)
- Implement proper HTTP verbs (GET, POST, PUT, DELETE)

### Error Handling
- Use `handleError()` helper for consistent error handling
- Log errors with appropriate context
- Return user-friendly error messages
- Implement proper HTTP status codes

### Performance Considerations
- Use eager loading to prevent N+1 queries
- Implement caching for frequently accessed data
- Queue heavy operations
- Optimize database queries with proper indexes
- Use chunking for large data operations

### Code Style
- Follow PSR-12 coding standards
- Use Laravel Pint for automatic formatting
- Write descriptive variable and method names
- Keep methods small and focused
- Document complex logic with clear comments

## Cloud Instance Considerations

We have a cloud instance of Coolify (hosted version) with:
- 2 Horizon worker servers
- Thousands of connected servers
- Thousands of active users
- High-availability requirements

When developing features:
- Consider scalability implications
- Test with large datasets
- Implement efficient queries
- Use queues for heavy operations
- Consider rate limiting and resource constraints
- Implement proper error recovery mechanisms

## Important Reminders

- Always run code formatting: `./vendor/bin/pint`
- Test your changes: `./vendor/bin/pest`
- Check for static analysis issues: `./vendor/bin/phpstan`
- Use existing patterns and helpers
- Follow the established directory structure
- Maintain backward compatibility
- Document breaking changes
- Consider performance impact on large-scale deployments

## Additional Documentation

This file contains high-level guidelines for Claude Code. For **more detailed, topic-specific documentation**, refer to the `.ai/` directory:

> **Documentation Hub**: The `.ai/` directory contains comprehensive, detailed documentation organized by topic. Start with [.ai/README.md](.ai/README.md) for navigation, then explore specific topics below.

### Core Documentation
- [Technology Stack](.ai/core/technology-stack.md) - All versions, packages, and dependencies (single source of truth)
- [Project Overview](.ai/core/project-overview.md) - What Coolify is and how it works
- [Application Architecture](.ai/core/application-architecture.md) - System design and component relationships
- [Deployment Architecture](.ai/core/deployment-architecture.md) - How deployments work end-to-end

### Development Practices
- [Development Workflow](.ai/development/development-workflow.md) - Development setup, commands, and workflows
- [Testing Patterns](.ai/development/testing-patterns.md) - Testing strategies and examples (Docker requirements!)
- [Laravel Boost](.ai/development/laravel-boost.md) - Laravel-specific guidelines and best practices

### Code Patterns
- [Database Patterns](.ai/patterns/database-patterns.md) - Eloquent, migrations, relationships
- [Frontend Patterns](.ai/patterns/frontend-patterns.md) - Livewire, Alpine.js, Tailwind CSS
- [Security Patterns](.ai/patterns/security-patterns.md) - Authentication, authorization, security
- [Form Components](.ai/patterns/form-components.md) - Enhanced form components with authorization
- [API & Routing](.ai/patterns/api-and-routing.md) - API design and routing conventions

### Meta Documentation
- [Maintaining Docs](.ai/meta/maintaining-docs.md) - How to update and improve AI documentation
- [Sync Guide](.ai/meta/sync-guide.md) - Keeping documentation synchronized

## Laravel Boost Guidelines

> **Full Guidelines**: See [.ai/development/laravel-boost.md](.ai/development/laravel-boost.md) for complete Laravel Boost guidelines.

### Essential Laravel Patterns

- Use PHP 8.4 constructor property promotion and typed properties
- Follow PSR-12 (run `./vendor/bin/pint` before committing)
- Use Eloquent ORM, avoid raw queries
- Use Form Request classes for validation
- Queue heavy operations with Laravel Horizon
- Never use `env()` outside config files
- Use named routes with `route()` function
- Laravel 12 with Laravel 10 structure (no bootstrap/app.php)

### Testing Requirements

- **Unit tests**: No database, use mocking, run with `./vendor/bin/pest tests/Unit`
- **Feature tests**: Can use database, run with `docker exec coolify php artisan test`
- Every change must have tests
- Use Pest for all tests

### Livewire & Frontend

- Livewire components require single root element
- Use `wire:model.live` for real-time updates
- Alpine.js included with Livewire
- Tailwind CSS 4.1.4 (use new utilities, not deprecated ones)
- Use `gap` utilities for spacing, not margins


Random other things you should remember:
- App\Models\Application::team must return a relationship instance., always use team()