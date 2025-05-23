# Coolify Project Summary

## Core Info
- **Purpose**: Open-source self-hostable Heroku/Netlify/Vercel alternative
- **Function**: Manage servers, apps, and databases on your own hardware via SSH
- **Stack**: Laravel 12.4 (PHP 8.4), Vue 3.5, Tailwind, PostgreSQL, Redis, Docker

## Key Structure
```
/app            → Core code (Actions, Controllers, Models)
/database       → Migrations, seeders
/resources      → Frontend assets
/tests          → Unit, Feature, Browser tests
```

## Quick Setup
```bash
# Copy env and start containers
cp .env.development.example .env
docker-compose -f docker-compose.dev.yml up -d

# Install dependencies and initialize
docker exec -it coolify composer install
docker exec -it coolify php artisan key:generate
docker exec -it coolify php artisan migrate --seed
docker exec -it coolify-vite npm install && npm run build
```

## Testing Commands
```bash
# Create test DB
docker exec -it coolify-postgres psql -U coolify -c "CREATE DATABASE coolify_test;"

# Run tests
docker exec -it coolify php artisan test
docker exec -it coolify php artisan dusk  # Browser tests
```

## Dev Workflow
- Follow PSR-12 standards
- Use Laravel Pint for formatting
- Branch from and PR to `next` branch
- Use conventional commit messages

## Utilities
- Laravel Horizon: http://localhost:8000/horizon
- Test emails: http://localhost:8025
- Telescope (if enabled): http://localhost:8000/telescope

## Common Fixes
- Use `postgres` not `localhost` for DB host in containers
- Use `redis` for Redis host
- Run `php artisan dusk:chrome-driver` if browser tests fail
