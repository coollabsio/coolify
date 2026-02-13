---
name: coolify-service-templates
description: Create new one-click service templates for Coolify - a self-hosted PaaS platform. Use when adding new services to Coolify's template library, creating docker-compose templates with Coolify magic environment variables, or contributing service definitions with logos and metadata.
---

# Coolify Service Templates Skill

Create new one-click deployable service templates for [Coolify](https://coolify.io).

## Overview

Coolify services are Docker Compose files with Coolify-specific metadata and magic environment variables. This skill guides you through creating properly structured service templates that can be deployed with one click.

## File Locations

- **Compose templates**: `/templates/compose/<service-name>.yaml`
- **Service logos**: `/svgs/<service-name>.svg` (SVG preferred)
- **Generated registry**: `/templates/service-templates.json` (auto-generated)
- **Documentation**: `/docs/services/<service-name>.md` (for docs site)

## Quick Workflow

1. **Check for existing service** - Ensure the service doesn't already exist
2. **Create compose file** - Write the Docker Compose with Coolify metadata
3. **Add logo** - Place SVG logo in `/svgs/` folder
4. **Test template** - Validate YAML syntax and structure
5. **Add documentation** - Create docs file for the service

## Creating a Service Template

### 1. Compose File Structure

Create `/templates/compose/<service-name>.yaml`:

```yaml
# documentation: https://docs.example.com/
# slogan: Brief description of your service (max 100 chars)
# category: Choose from categories list below
# tags: tag1,tag2,tag3,tag4
# logo: svgs/<service-name>.svg
# port: 8080

services:
  app:
    image: your-service-image:tag
    environment:
      # Required - must be set by user
      - API_KEY=${API_KEY:?}
      
      # Required with default
      - PORT=${PORT:-8080}
      
      # Optional with default
      - DEBUG=${DEBUG:-false}
      
      # Coolify magic variables
      - SERVICE_URL=${SERVICE_FQDN_APP}
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
    volumes:
      - ${COOLIFY_VOLUME_APP}:/data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://127.0.0.1:8080/health"]
      interval: 5s
      timeout: 10s
      retries: 3
```

### 2. Required Metadata Headers

| Field | Required | Description |
|-------|----------|-------------|
| `documentation` | Yes | Official docs URL with `?utm_source=coolify.io` |
| `slogan` | Yes | Brief description (50-100 chars) |
| `category` | Yes | See categories list below |
| `tags` | Yes | Comma-separated keywords for search |
| `logo` | Yes | Path to logo (SVG preferred) |
| `port` | Yes | Main service port (required for proxy) |

### 3. Categories

Available categories (use exact values):
- `ai` - AI/ML services
- `analytics` - Analytics platforms
- `automation` - Workflow automation
- `chat` - Communication tools
- `cms` - Content management
- `database` - Database services
- `development` - Dev tools
- `finance` - Financial apps
- `games` - Gaming servers
- `media` - Media servers
- `monitoring` - Monitoring/observability
- `productivity` - Productivity tools
- `search` - Search engines
- `security` - Security tools
- `storage` - Storage services
- `wiki` - Knowledge bases

### 4. Coolify Magic Environment Variables

See [references/coolify-env-vars.md](references/coolify-env-vars.md) for complete list.

Commonly used:

| Variable | Description | Example |
|----------|-------------|---------|
| `SERVICE_FQDN_<SERVICE>` | Auto-generated domain | `app.example.com` |
| `SERVICE_URL_<SERVICE>` | Full URL | `https://app.example.com` |
| `COOLIFY_DATABASE_URL` | Database connection string | `postgres://user:pass@host:5432/db` |
| `COOLIFY_VOLUME_<SERVICE>` | Persistent storage path | `/data/coolify/services/...` |
| `SERVICE_PASSWORD_<SERVICE>` | Auto-generated password | `abc123xyz` |
| `SERVICE_USER_<SERVICE>` | Auto-generated username | `service_user` |

### 5. Required vs Optional Variables

**Required (user must set):**
```yaml
- API_KEY=${API_KEY:?}
```

**Required with sensible default:**
```yaml
- PORT=${PORT:-8080}
- LOG_LEVEL=${LOG_LEVEL:-info}
```

**Optional with default:**
```yaml
- DEBUG=${DEBUG:-false}
- CACHE_TTL=${CACHE_TTL:-3600}
```

### 6. Storage Options

#### Named Volumes

```yaml
services:
  app:
    volumes:
      - ${COOLIFY_VOLUME_APP}:/app/data
```

#### Create Empty Directory

Use `is_directory: true` to create directories:

```yaml
services:
  app:
    volumes:
      - type: bind
        source: ./data
        target: /app/data
        is_directory: true
```

#### Create File with Content

Use `content:` to create files dynamically:

```yaml
services:
  app:
    volumes:
      - type: bind
        source: ./config.json
        target: /app/config.json
        content: |
          {
            "api_url": "${SERVICE_URL_APP}",
            "debug": false
          }
```

#### Using Configs (Alternative)

```yaml
services:
  app:
    configs:
      - source: myconfig
        target: /app/config.json

configs:
  myconfig:
    content: |
      {
        "api_url": "${SERVICE_URL_APP}"
      }
```

### 7. Health Checks

Configure health checks for services:

```yaml
services:
  app:
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/health"]
      interval: 5s
      timeout: 10s
      retries: 3
      start_period: 10s
```

**Exclude from Health Checks**

For one-time tasks (like migrations):

```yaml
services:
  migration:
    image: app:latest
    exclude_from_hc: true
    command: ["migrate", "up"]
```

### 8. Networking

#### Default Networking

Services automatically join the default network and can reach each other by service name:

```yaml
services:
  backend:
    image: backend:latest
  frontend:
    image: frontend:latest
    environment:
      - API_URL=http://backend:3000
```

#### Port Mapping

Map container ports to host (use sparingly):

```yaml
services:
  app:
    ports:
      - "3000:3000"           # All interfaces
      - "127.0.0.1:3000:3000" # Localhost only
```

#### Custom Networks

```yaml
services:
  frontend:
    networks:
      - frontend
  backend:
    networks:
      - frontend
      - backend
  database:
    networks:
      - backend

networks:
  frontend:
  backend:
    internal: true
```

### 9. Depends On

Control service startup order:

```yaml
services:
  app:
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_started
```

Conditions:
- `service_started` - Wait for container to start
- `service_healthy` - Wait for health check to pass

### 10. Labels (for Raw Compose)

If not using Coolify's magic, add Traefik labels:

```yaml
services:
  app:
    labels:
      - coolify.managed=true
      - coolify.applicationId=5
      - coolify.type=application
      - traefik.enable=true
      - "traefik.http.routers.myapp.rule=Host(`example.com`)"
      - traefik.http.routers.myapp.entryPoints=http
```

## Logo Requirements

- **Format**: SVG strongly preferred, PNG acceptable
- **Location**: `/svgs/<service-name>.svg`
- **Size**: Square aspect ratio, 512x512px or larger
- **Style**: Clean, recognizable at small sizes
- **Naming**: Must match service name exactly (lowercase, hyphens for spaces)

## Common Service Patterns

### Single Container Service

```yaml
# documentation: https://docs.n8n.io/
# slogan: Workflow automation for technical teams
# category: automation
# tags: workflow,automation,nocode
# logo: svgs/n8n.svg
# port: 5678

services:
  n8n:
    image: n8nio/n8n:latest
    environment:
      - SERVICE_FQDN_N8N
      - N8N_BASIC_AUTH_ACTIVE=true
      - N8N_BASIC_AUTH_USER=${SERVICE_USER_N8N}
      - N8N_BASIC_AUTH_PASSWORD=${SERVICE_PASSWORD_N8N}
    volumes:
      - ${COOLIFY_VOLUME_N8N}:/home/node/.n8n
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://127.0.0.1:5678/healthz"]
      interval: 5s
      timeout: 10s
      retries: 3
```

### Service with Database

```yaml
# documentation: https://docs.example.com/
# slogan: App with PostgreSQL database
# category: productivity
# tags: app,postgres
# logo: svgs/myapp.svg
# port: 3000

services:
  app:
    image: myapp:latest
    environment:
      - SERVICE_FQDN_APP
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
      - REDIS_URL=${COOLIFY_REDIS_URL}
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

  postgres:
    image: postgres:16-alpine
    environment:
      - POSTGRES_USER=${SERVICE_USER_POSTGRES}
      - POSTGRES_PASSWORD=${SERVICE_PASSWORD_POSTGRES}
      - POSTGRES_DB=${POSTGRES_DB:-app}
    volumes:
      - ${COOLIFY_VOLUME_POSTGRES}:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${SERVICE_USER_POSTGRES}"]
      interval: 5s
      timeout: 10s
      retries: 10

  redis:
    image: redis:7-alpine
    volumes:
      - ${COOLIFY_VOLUME_REDIS}:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 10s
      retries: 3
```

### Service with Persistent Storage

```yaml
# documentation: https://docs.example.com/
# slogan: File storage and sync
# category: storage
# tags: storage,files,sync
# logo: svgs/storage.svg
# port: 8080

services:
  storage:
    image: storage-app:latest
    environment:
      - SERVICE_FQDN_STORAGE
      - DATA_DIR=/data
    volumes:
      - ${COOLIFY_VOLUME_STORAGE}:/data
      - ${COOLIFY_VOLUME_STORAGE_CONFIG}:/config
```

### Service with Initial Config File

```yaml
# documentation: https://docs.example.com/
# slogan: App with preconfigured settings
# category: productivity
# tags: app,config
# logo: svgs/myapp.svg
# port: 3000

services:
  app:
    image: myapp:latest
    environment:
      - SERVICE_FQDN_APP
    volumes:
      - type: bind
        source: ./settings.json
        target: /app/config/settings.json
        content: |
          {
            "host": "0.0.0.0",
            "port": 3000,
            "public_url": "${SERVICE_URL_APP}"
          }
```

## Validation Checklist

Before submitting:

- [ ] YAML syntax is valid
- [ ] All required metadata headers present
- [ ] Logo exists in `/svgs/` folder
- [ ] Port is specified
- [ ] Uses Coolify magic variables where applicable
- [ ] Health checks configured
- [ ] Uses sensible defaults for optional variables
- [ ] Documentation URL includes `?utm_source=coolify.io`
- [ ] Category is from approved list
- [ ] Tags are lowercase, comma-separated

## Testing Your Template

1. Create test compose file in Coolify
2. Deploy as "Docker Compose Empty"
3. Verify service starts correctly
4. Check environment variables are substituted
5. Confirm volumes are persisted

## Submitting Your Service

### 1. Pull Request to Coolify

- Add compose file to `/templates/compose/<service>.yaml`
- Add logo to `/svgs/<service>.svg`
- Target `main` branch

### 2. Add Documentation (Optional)

After template is merged, contribute documentation:

1. Add logo to `/docs/public/images/services/`
2. Create `/docs/services/<service-name>.md`:

```markdown
---
title: "Service Name"
description: "Here you can find the documentation for hosting Service Name with Coolify."
---

# [Service Name]

<ZoomableImage src="/docs/images/services/service.svg" alt="Service dashboard" />

## What is [Service Name]?

Brief description and use cases.

## Links

- [Official website](https://example.com?utm_source=coolify.io)
- [GitHub](https://github.com/example/repo?utm_source=coolify.io)
```

3. Add to service list in `docs/.vitepress/theme/components/Services/List.vue`
4. Target `next` branch for docs PR

## References

- [Coolify Environment Variables](references/coolify-env-vars.md)
- [Docker Compose Specification](references/docker-compose-spec.md)
- [Service Template Examples](references/examples.md)
