# Coolify AI Documentation

Welcome to the Coolify AI documentation hub. This directory contains all AI assistant instructions organized by topic for easy navigation and maintenance.

## Quick Start

- **For Claude Code**: Start with [CLAUDE.md in root directory](../CLAUDE.md)
- **For Cursor IDE**: Check `.cursor/rules/coolify-ai-docs.mdc` which references this directory
- **For Other AI Tools**: Continue reading below

## Documentation Structure

### 📚 Core Documentation
Essential project information and architecture:

- **[Technology Stack](core/technology-stack.md)** - All versions, packages, and dependencies (Laravel 12.4.1, PHP 8.4.7, etc.)
- **[Project Overview](core/project-overview.md)** - What Coolify is and how it works
- **[Application Architecture](core/application-architecture.md)** - System design and component relationships
- **[Deployment Architecture](core/deployment-architecture.md)** - How deployments work end-to-end, including Coolify Docker Compose extensions (custom fields)

### 💻 Development
Day-to-day development practices:

- **[Workflow](development/development-workflow.md)** - Development setup, commands, and daily workflows
- **[Testing Patterns](development/testing-patterns.md)** - How to write and run tests (Unit vs Feature, Docker requirements)
- **[Laravel Boost](development/laravel-boost.md)** - Laravel-specific guidelines and best practices

### 🎨 Patterns
Code patterns and best practices by domain:

- **[Database Patterns](patterns/database-patterns.md)** - Eloquent, migrations, relationships
- **[Frontend Patterns](patterns/frontend-patterns.md)** - Livewire, Alpine.js, Tailwind CSS
- **[Security Patterns](patterns/security-patterns.md)** - Authentication, authorization, security best practices
- **[Form Components](patterns/form-components.md)** - Enhanced form components with authorization
- **[API & Routing](patterns/api-and-routing.md)** - API design, routing conventions, REST patterns

### 📖 Meta
Documentation about documentation:

- **[Maintaining Docs](meta/maintaining-docs.md)** - How to update and improve this documentation
- **[Sync Guide](meta/sync-guide.md)** - Keeping documentation synchronized across tools

## Quick Decision Tree

**What do you need help with?**

### Running Commands
→ [development/development-workflow.md](development/development-workflow.md)
- Frontend: `npm run dev`, `npm run build`
- Backend: `php artisan serve`, `php artisan migrate`
- Tests: Docker for Feature tests, mocking for Unit tests
- Code quality: `./vendor/bin/pint`, `./vendor/bin/phpstan`

### Writing Tests
→ [development/testing-patterns.md](development/testing-patterns.md)
- **Unit tests**: No database, use mocking, run outside Docker
- **Feature tests**: Can use database, must run inside Docker
- Command: `docker exec coolify php artisan test`

### Building UI
→ [patterns/frontend-patterns.md](patterns/frontend-patterns.md) or [patterns/form-components.md](patterns/form-components.md)
- Livewire components with server-side state
- Alpine.js for client-side interactivity
- Tailwind CSS 4.1.4 for styling
- Form components with built-in authorization

### Database Work
→ [patterns/database-patterns.md](patterns/database-patterns.md)
- Eloquent ORM patterns
- Migration best practices
- Relationship definitions
- Query optimization

### Security & Auth
→ [patterns/security-patterns.md](patterns/security-patterns.md)
- Team-based access control
- Policy and gate patterns
- Form authorization (canGate, canResource)
- API security

### Laravel-Specific Questions
→ [development/laravel-boost.md](development/laravel-boost.md)
- Laravel 12 patterns
- Livewire 3 best practices
- Pest testing patterns
- Laravel conventions

### Docker Compose Extensions
→ [core/deployment-architecture.md](core/deployment-architecture.md#coolify-docker-compose-extensions)
- Custom fields: `exclude_from_hc`, `content`, `isDirectory`
- How to use inline file content
- Health check exclusion patterns
- Volume creation control

### Version Numbers
→ [core/technology-stack.md](core/technology-stack.md)
- **Single source of truth** for all version numbers
- Don't duplicate versions elsewhere, reference this file

## Navigation Tips

1. **Start broad**: Begin with project-overview or ../CLAUDE.md
2. **Get specific**: Navigate to topic-specific files for details
3. **Cross-reference**: Files link to related topics
4. **Single source**: Version numbers and critical data exist in ONE place only

## For AI Assistants

### Important Patterns to Follow

**Testing Commands:**
- Unit tests: `./vendor/bin/pest tests/Unit` (no database, outside Docker)
- Feature tests: `docker exec coolify php artisan test` (requires database, inside Docker)
- NEVER run Feature tests outside Docker - they will fail with database connection errors

**Version Numbers:**
- Always use exact versions from [technology-stack.md](core/technology-stack.md)
- Laravel 12.4.1, PHP 8.4.7, Tailwind 4.1.4
- Don't use "v12" or "8.4" - be precise

**Form Authorization:**
- ALWAYS include `canGate` and `:canResource` on form components
- See [form-components.md](patterns/form-components.md) for examples

**Livewire Components:**
- MUST have exactly ONE root element
- See [frontend-patterns.md](patterns/frontend-patterns.md) for details

**Code Style:**
- Run `./vendor/bin/pint` before finalizing changes
- Follow PSR-12 standards
- Use PHP 8.4 features (constructor promotion, typed properties, etc.)

## Contributing

When updating documentation:
1. Read [meta/maintaining-docs.md](meta/maintaining-docs.md)
2. Follow the single source of truth principle
3. Update cross-references when moving content
4. Test all links work
5. Run Pint on markdown files if applicable

## Questions?

- **Claude Code users**: Check [../CLAUDE.md](../CLAUDE.md) first
- **Cursor IDE users**: Check `.cursor/rules/coolify-ai-docs.mdc`
- **Documentation issues**: See [meta/maintaining-docs.md](meta/maintaining-docs.md)
- **Sync issues**: See [meta/sync-guide.md](meta/sync-guide.md)
