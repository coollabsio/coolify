# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
- `./vendor/bin/pest` - Run Pest tests

## Architecture Overview

### Technology Stack
- **Backend**: Laravel 12 (PHP 8.4)
- **Frontend**: Livewire 3.5+ with Alpine.js and Tailwind CSS 4.1+
- **Database**: PostgreSQL 15 (primary), Redis 7 (cache/queues)
- **Real-time**: Soketi (WebSocket server)
- **Containerization**: Docker & Docker Compose
- **Queue Management**: Laravel Horizon

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

For more detailed guidelines and patterns, refer to the `.cursor/rules/` directory:

### Architecture & Patterns
- [Application Architecture](.cursor/rules/application-architecture.mdc) - Detailed application structure
- [Deployment Architecture](.cursor/rules/deployment-architecture.mdc) - Deployment patterns and flows
- [Database Patterns](.cursor/rules/database-patterns.mdc) - Database design and query patterns
- [Frontend Patterns](.cursor/rules/frontend-patterns.mdc) - Livewire and Alpine.js patterns
- [API & Routing](.cursor/rules/api-and-routing.mdc) - API design and routing conventions

### Development & Security
- [Development Workflow](.cursor/rules/development-workflow.mdc) - Development best practices
- [Security Patterns](.cursor/rules/security-patterns.mdc) - Security implementation details
- [Form Components](.cursor/rules/form-components.mdc) - Enhanced form components with authorization
- [Testing Patterns](.cursor/rules/testing-patterns.mdc) - Testing strategies and examples

### Project Information
- [Project Overview](.cursor/rules/project-overview.mdc) - High-level project structure
- [Technology Stack](.cursor/rules/technology-stack.mdc) - Detailed tech stack information
- [Cursor Rules Guide](.cursor/rules/cursor_rules.mdc) - How to maintain cursor rules
