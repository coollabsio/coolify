# Coolify Magic Environment Variables

Coolify provides auto-generated environment variables for services. These variables are automatically created based on service configuration and can be used in docker-compose files.

## Service-Specific Variables

Replace `<SERVICE>` with your service name (uppercase, underscores for spaces).

### Domain and URL Variables

| Variable | Description | Example Value |
|----------|-------------|---------------|
| `SERVICE_FQDN_<SERVICE>` | Auto-generated domain for the service | `myapp.example.com` |
| `SERVICE_URL_<SERVICE>` | Full URL with protocol | `https://myapp.example.com` |
| `SERVICE_BASE64_<SERVICE>` | Base64 encoded domain | - |
| `SERVICE_BASE64_64_<SERVICE>` | Base64 encoded 64-char domain | - |

**URL/FQDN with Paths and Ports:**

```yaml
services:
  appwrite:
    environment:
      # Basic URL
      - SERVICE_URL_APPWRITE
      # URL with path - proxies to default port
      - SERVICE_URL_APPWRITE=/v1/realtime
      # URL with port - proxies to port 3000
      - SERVICE_URL_APPWRITE_3000
      # FQDN (domain only, no protocol)
      - SERVICE_FQDN_APPWRITE
```

**Important Naming Rules:**
- Use hyphens (`-`) NOT underscores (`_`) in identifiers when using ports
- `SERVICE_URL_APPWRITE_SERVICE_3000` ❌
- `SERVICE_URL_APPWRITE-SERVICE_3000` ✅

### Authentication Variables

| Variable | Description | Example Value |
|----------|-------------|---------------|
| `SERVICE_USER_<SERVICE>` | Auto-generated username | `service_abc123` |
| `SERVICE_PASSWORD_<SERVICE>` | Auto-generated password | `xyz789pass` |
| `SERVICE_PASSWORD_64_<SERVICE>` | 64-char auto-generated password | - |

### Database Variables (Auto-created)

When a service named `postgres`, `mysql`, `mariadb`, or `mongodb` exists:

| Variable | Description | Example Value |
|----------|-------------|---------------|
| `COOLIFY_DATABASE_URL` | Full connection string | `postgres://user:pass@host:5432/db` |
| `COOLIFY_REDIS_URL` | Redis connection string | `redis://host:6379` |
| `COOLIFY_MONGODB_URL` | MongoDB connection string | `mongodb://user:pass@host:27017/db` |

### Storage Variables

| Variable | Description | Example Value |
|----------|-------------|---------------|
| `COOLIFY_VOLUME_<SERVICE>` | Persistent volume path | `/data/coolify/services/...` |
| `COOLIFY_VOLUME_<SERVICE>_CONFIG` | Config volume path | `/data/coolify/services/...` |
| `COOLIFY_VOLUME_<SERVICE>_DATA` | Data volume path | `/data/coolify/services/...` |
| `COOLIFY_VOLUME_<SERVICE>_LOGS` | Logs volume path | `/data/coolify/services/...` |

### Email Variables (if SMTP configured)

| Variable | Description |
|----------|-------------|
| `COOLIFY_SMTP_HOST` | SMTP server hostname |
| `COOLIFY_SMTP_PORT` | SMTP server port |
| `COOLIFY_SMTP_USERNAME` | SMTP username |
| `COOLIFY_SMTP_PASSWORD` | SMTP password |
| `COOLIFY_SMTP_ENCRYPTION` | Encryption type (tls/ssl) |
| `COOLIFY_SMTP_FROM_ADDRESS` | From email address |
| `COOLIFY_SMTP_FROM_NAME` | From display name |

### Service Stack Variables

| Variable | Description |
|----------|-------------|
| `SERVICE_NAME_<SERVICE>` | Service name for a given service |

Example: For a service named `web`, use `SERVICE_NAME_WEB`. Useful for preview deployments where service names vary.

## Predefined Application Variables

These are set automatically by Coolify:

| Variable | Description |
|----------|-------------|
| `COOLIFY_FQDN` | Fully qualified domain name(s) of the application |
| `COOLIFY_URL` | URL(s) of the application |
| `COOLIFY_BRANCH` | Branch name of the source code |
| `COOLIFY_RESOURCE_UUID` | Unique resource identifier |
| `COOLIFY_CONTAINER_NAME` | Name of the container |
| `SOURCE_COMMIT` | Commit hash of the source code |
| `PORT` | Port Expose's first port (if not set) |
| `HOST` | Set to `0.0.0.0` (if not set) |

## Shared Variables

Use shared variables defined at different scopes:

### Team-Based
```yaml
- API_KEY={{team.API_KEY}}
```

### Project-Based
```yaml
- DATABASE_URL={{project.DATABASE_URL}}
```

### Environment-Based
```yaml
- NODE_ENV={{environment.NODE_ENV}}
```

## Build Time Variables

Mark variables for use during build:

```yaml
services:
  app:
    environment:
      - BUILD_ARG=${BUILD_ARG}
    build:
      args:
        - BUILD_ARG=${BUILD_ARG}
```

## Usage in Docker Compose

### Basic Usage

```yaml
services:
  app:
    environment:
      - SERVICE_FQDN_APP
      - API_URL=${SERVICE_URL_APP}
```

### With Default Values

```yaml
services:
  app:
    environment:
      - PORT=${PORT:-3000}
      - DEBUG=${DEBUG:-false}
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
```

### Required Values

Use `:?` to mark a variable as required:

```yaml
services:
  app:
    environment:
      - API_KEY=${API_KEY:?}
      - SECRET=${SECRET:?}
```

### Required with Defaults

```yaml
services:
  app:
    environment:
      # Required - appears first, red border if empty
      - DATABASE_URL=${DATABASE_URL:?}
      
      # Required with default - prefilled but editable
      - PORT=${PORT:?8080}
      - LOG_LEVEL=${LOG_LEVEL:?info}
```

### In Volume Definitions

```yaml
services:
  app:
    volumes:
      - ${COOLIFY_VOLUME_APP}:/app/data
      - ${COOLIFY_VOLUME_APP_CONFIG}:/app/config
```

## Variable Substitution Syntax

| Syntax | Description | Example |
|--------|-------------|---------|
| `${VAR}` | Use value or empty if not set | `${PORT}` |
| `${VAR:-default}` | Use default if not set | `${PORT:-8080}` |
| `${VAR:=default}` | Set and use default if not set | `${PORT:=8080}` |
| `${VAR:?}` | Error if not set | `${API_KEY:?}` |
| `${VAR:?message}` | Error with custom message | `${API_KEY:?required}` |

## Cross-Service Variable Sharing

Reuse variables across services:

```yaml
services:
  appwrite:
    environment:
      - SERVICE_PASSWORD_APPWRITE
  
  not-appwrite:
    environment:
      # Reuses password from appwrite service
      - APPWRITE_PASSWORD=${SERVICE_PASSWORD_APPWRITE}
```

## Complete Example

```yaml
# documentation: https://docs.example.com/
# slogan: Full-featured app template
# category: productivity
# tags: app,database,api
# logo: svgs/example.svg
# port: 3000

services:
  app:
    image: example/app:latest
    environment:
      # Domain/URL
      - SERVICE_FQDN_APP
      - PUBLIC_URL=${SERVICE_URL_APP}
      
      # Auth
      - ADMIN_USER=${SERVICE_USER_APP}
      - ADMIN_PASSWORD=${SERVICE_PASSWORD_APP}
      
      # Database (auto-detected)
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
      - REDIS_URL=${COOLIFY_REDIS_URL}
      
      # Required config
      - API_KEY=${API_KEY:?}
      
      # Optional with defaults
      - PORT=${PORT:-3000}
      - LOG_LEVEL=${LOG_LEVEL:-info}
      - NODE_ENV=${NODE_ENV:-production}
      
      # Shared variables
      - TEAM_SECRET={{team.SECRET_KEY}}
    volumes:
      - ${COOLIFY_VOLUME_APP}:/app/data
      - ${COOLIFY_VOLUME_APP_CONFIG}:/app/config
    depends_on:
      postgres:
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
