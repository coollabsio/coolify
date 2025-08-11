# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Coolify is an open-source, self-hostable platform for deploying applications and managing servers - an alternative to Heroku/Netlify/Vercel. It's built with Laravel (PHP) and uses Docker for containerization.

## Development Commands

### Frontend Development
- `npm run dev` - Start Vite development server for frontend assets
- `npm run build` - Build frontend assets for production

### Backend Development
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
- **Frontend**: Livewire + Alpine.js + Tailwind CSS
- **Database**: PostgreSQL 15
- **Cache/Queue**: Redis 7
- **Real-time**: Soketi (WebSocket server)
- **Containerization**: Docker & Docker Compose

### Key Components

#### Core Models
- `Application` - Deployed applications with Git integration
- `Server` - Remote servers managed by Coolify
- `Service` - Docker Compose services
- `Database` - Standalone database instances (PostgreSQL, MySQL, MongoDB, Redis, etc.)
- `Team` - Multi-tenancy support
- `Project` - Grouping of environments and resources

#### Job System
- Uses Laravel Horizon for queue management
- Key jobs: `ApplicationDeploymentJob`, `ServerCheckJob`, `DatabaseBackupJob`
- `ScheduledJobManager` and `ServerResourceManager` handle job scheduling

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
- `bootstrap/helpers/` - Helper functions for various domains
- `database/migrations/` - Database schema evolution

## Development Guidelines

### Code Organization
- Use Actions pattern for complex business logic
- Livewire components handle UI and user interactions  
- Jobs handle asynchronous operations
- Traits provide shared functionality (e.g., `ExecuteRemoteCommand`)

### Testing
- Uses Pest for testing framework
- Tests located in `tests/` directory

### Deployment and Docker
- Applications are deployed using Docker containers
- Configuration generated dynamically based on application settings
- Supports multiple deployment targets and proxy configurations