# Docker Compose Specification for Coolify

Reference guide for Docker Compose features compatible with Coolify service templates.

## Core Structure

```yaml
# Metadata (Coolify-specific)
# documentation: https://docs.example.com/
# slogan: Service description
# category: category_name
# tags: tag1,tag2
# logo: svgs/service.svg
# port: 8080

services:
  service_name:
    image: image:tag
    environment:
      - KEY=value
    volumes:
      - volume_name:/path
    ports:
      - "8080:8080"
    depends_on:
      - other_service
    healthcheck:
      test: ["CMD", "command"]
    networks:
      - network_name

volumes:
  volume_name:

networks:
  network_name:
```

## Services Configuration

### Image

```yaml
services:
  app:
    image: nginx:alpine
    # or
    image: nginx:latest
    # or with registry
    image: ghcr.io/owner/repo:tag
```

### Environment Variables

```yaml
services:
  app:
    environment:
      # Simple key-value
      - KEY=value
      
      # Using Coolify magic variables
      - SERVICE_FQDN_APP
      
      # With defaults
      - PORT=${PORT:-8080}
      
      # Required (error if not set)
      - API_KEY=${API_KEY:?}
```

### Volumes

Named volumes with Coolify magic:

```yaml
services:
  app:
    volumes:
      # Coolify persistent storage
      - ${COOLIFY_VOLUME_APP}:/app/data
      
      # Config directory
      - ${COOLIFY_VOLUME_APP_CONFIG}:/app/config
      
      # Multiple volumes
      - ${COOLIFY_VOLUME_APP_DATA}:/data
      - ${COOLIFY_VOLUME_APP_LOGS}:/logs
```

### Ports

```yaml
services:
  app:
    ports:
      # Standard port mapping
      - "8080:8080"
      
      # Expose on all interfaces
      - "0.0.0.0:8080:8080"
      
      # Just expose (internal)
      - "8080"
```

**Note**: Coolify uses the `port` metadata header for proxy configuration, not compose ports.

**Caution**: Mapping ports directly makes the service available outside proxy control. If the same compose file is used for development, this may expose private services unintentionally.

### Health Checks

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

Common healthcheck patterns:

**HTTP endpoint:**
```yaml
healthcheck:
  test: ["CMD", "wget", "-q", "--spider", "http://localhost:8080/healthz"]
```

**TCP port:**
```yaml
healthcheck:
  test: ["CMD-SHELL", "bash -c ':</dev/tcp/127.0.0.1/8080' || exit 1"]
```

**Database (PostgreSQL):**
```yaml
healthcheck:
  test: ["CMD-SHELL", "pg_isready -U ${SERVICE_USER_POSTGRES}"]
```

**Database (MySQL/MariaDB):**
```yaml
healthcheck:
  test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
```

**Redis:**
```yaml
healthcheck:
  test: ["CMD", "redis-cli", "ping"]
```

### Exclude from Health Checks

For one-time tasks like migrations:

```yaml
services:
  migration:
    image: app:latest
    exclude_from_hc: true
    command: ["migrate", "up"]
    depends_on:
      postgres:
        condition: service_healthy
```

### Depends On

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

### Restart Policy

```yaml
services:
  app:
    restart: unless-stopped
    # or
    restart: always
    # or
    restart: on-failure:3
```

### User and Permissions

```yaml
services:
  app:
    user: "1000:1000"
    # or
    user: ${PUID:-1000}:${PGID:-1000}
```

### Capabilities

```yaml
services:
  app:
    cap_add:
      - SYS_ADMIN
      - NET_ADMIN
    cap_drop:
      - ALL
```

### Command and Entrypoint

```yaml
services:
  app:
    command: ["serve", "--host", "0.0.0.0"]
    entrypoint: ["/bin/sh", "-c"]
```

### Working Directory

```yaml
services:
  app:
    working_dir: /app
```

### Logging

```yaml
services:
  app:
    logging:
      driver: json-file
      options:
        max-size: 10m
        max-file: "3"
```

### Labels

For raw compose deployment with Traefik:

```yaml
services:
  app:
    labels:
      # Coolify labels (auto-added if not set)
      - coolify.managed=true
      - coolify.applicationId=5
      - coolify.type=application
      
      # Traefik routing
      - traefik.enable=true
      - "traefik.http.routers.myapp.rule=Host(`example.com`) && PathPrefix(`/`)"
      - traefik.http.routers.myapp.entryPoints=http
```

## Networks

### Default Network

Coolify automatically creates networks. Most services don't need explicit network configuration.

Services can reach each other by service name:

```yaml
services:
  backend:
    image: backend:latest
  frontend:
    image: frontend:latest
    environment:
      - API_URL=http://backend:3000
```

### Custom Networks

```yaml
services:
  app:
    networks:
      - frontend
      - backend

networks:
  frontend:
    driver: bridge
  backend:
    internal: true
```

### Links (Aliases)

Define extra aliases for service discovery:

```yaml
services:
  web:
    links:
      - "db:database"
      - "db:postgres"
  db:
    image: postgres:16
```

The `db` service is reachable from `web` as `db`, `database`, and `postgres`.

### Connect to Predefined Networks

To connect to resources in other stacks (e.g., a database in another compose deployment):

1. Enable "Connect to Predefined Network" option in Coolify UI
2. Use the full service name with UUID: `postgres-<uuid>`

```yaml
services:
  app:
    environment:
      - DATABASE_URL=postgres://user:pass@postgres-vgsco4o:5432/db
```

**Note**: This changes internal Docker DNS behavior. Services are renamed to `<service>-<uuid>` to prevent collisions.

### External Networks

Use pre-existing networks:

```yaml
services:
  app:
    networks:
      - my-network

networks:
  my-network:
    name: my-pre-existing-network
    external: true
```

## Volume Types

### Named Volumes

```yaml
services:
  app:
    volumes:
      - app-data:/data
      - app-config:/config

volumes:
  app-data:
  app-config:
```

### Bind Mounts

```yaml
services:
  app:
    volumes:
      - /host/path:/container/path:ro
```

### Tmpfs Mounts

```yaml
services:
  app:
    volumes:
      - type: tmpfs
        target: /tmp
        tmpfs:
          size: 100M
```

### Creating Directories

Use `is_directory: true` (Coolify-specific):

```yaml
services:
  app:
    volumes:
      - type: bind
        source: ./data
        target: /app/data
        is_directory: true
```

### Creating Files with Content

Use `content:` (Coolify-specific):

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

## Configs

Alternative to inline file creation:

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

Or from file:

```yaml
configs:
  http_config:
    file: ./httpd.conf
```

Or external:

```yaml
configs:
  http_config:
    external: true
```

## Complete Example Patterns

### Simple Web Service

```yaml
# documentation: https://nginx.org/
# slogan: High-performance web server
# category: development
# tags: web,server,proxy
# logo: svgs/nginx.svg
# port: 80

services:
  nginx:
    image: nginx:alpine
    environment:
      - SERVICE_FQDN_NGINX
    volumes:
      - ${COOLIFY_VOLUME_NGINX}:/usr/share/nginx/html:ro
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://localhost/"]
      interval: 5s
      timeout: 10s
      retries: 3
```

### Full-Stack Application

```yaml
# documentation: https://docs.example.com/
# slogan: Complete web application stack
# category: productivity
# tags: web,app,database
# logo: svgs/app.svg
# port: 3000

services:
  app:
    image: myapp:latest
    environment:
      - SERVICE_FQDN_APP
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
      - REDIS_URL=${COOLIFY_REDIS_URL}
      - SECRET_KEY=${SERVICE_PASSWORD_APP}
    volumes:
      - ${COOLIFY_VOLUME_APP}:/app/data
      - ${COOLIFY_VOLUME_APP_UPLOADS}:/app/uploads
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:3000/health"]
      interval: 5s
      timeout: 10s
      retries: 3

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

  worker:
    image: myapp:latest
    command: ["worker"]
    environment:
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
      - REDIS_URL=${COOLIFY_REDIS_URL}
    depends_on:
      - redis
      - postgres
```

### Service with Migration

```yaml
# documentation: https://docs.example.com/
# slogan: App with database migration
# category: productivity
# tags: app,database
# logo: svgs/app.svg
# port: 3000

services:
  app:
    image: myapp:latest
    environment:
      - SERVICE_FQDN_APP
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
    depends_on:
      migration:
        condition: service_completed_successfully
      postgres:
        condition: service_healthy

  migration:
    image: myapp:latest
    exclude_from_hc: true
    command: ["migrate", "up"]
    environment:
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
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
```

## Limitations

Not all Docker Compose features are supported:

- **Build contexts**: Use pre-built images
- **Docker Swarm configs/secrets**: Not supported
- **Device mappings**: Limited support
- **PID namespace**: Not supported
- **External volumes**: Use Coolify volumes

## References

- [Docker Compose Specification](https://docs.docker.com/compose/compose-file/)
- [Coolify Environment Variables](coolify-env-vars.md)
