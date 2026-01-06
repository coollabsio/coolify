# Coolify Deployment Architecture

## Deployment Philosophy

Coolify orchestrates **Docker-based deployments** across multiple servers with automated configuration generation, zero-downtime deployments, and comprehensive monitoring.

## Core Deployment Components

### Deployment Models
- **[Application.php](mdc:app/Models/Application.php)** - Main application entity with deployment configurations
- **[ApplicationDeploymentQueue.php](mdc:app/Models/ApplicationDeploymentQueue.php)** - Deployment job orchestration
- **[Service.php](mdc:app/Models/Service.php)** - Multi-container service definitions
- **[Server.php](mdc:app/Models/Server.php)** - Target deployment infrastructure

### Infrastructure Management
- **[PrivateKey.php](mdc:app/Models/PrivateKey.php)** - SSH key management for secure server access
- **[StandaloneDocker.php](mdc:app/Models/StandaloneDocker.php)** - Single container deployments
- **[SwarmDocker.php](mdc:app/Models/SwarmDocker.php)** - Docker Swarm orchestration

## Deployment Workflow

### 1. Source Code Integration
```
Git Repository → Webhook → Coolify → Build & Deploy
```

#### Source Control Models
- **[GithubApp.php](mdc:app/Models/GithubApp.php)** - GitHub integration and webhooks
- **[GitlabApp.php](mdc:app/Models/GitlabApp.php)** - GitLab CI/CD integration

#### Deployment Triggers
- **Git push** to configured branches
- **Manual deployment** via UI
- **Scheduled deployments** via cron
- **API-triggered** deployments

### 2. Build Process
```
Source Code → Docker Build → Image Registry → Deployment
```

#### Build Configurations
- **Dockerfile detection** and custom Dockerfile support
- **Buildpack integration** for framework detection
- **Multi-stage builds** for optimization
- **Cache layer** management for faster builds

### 3. Deployment Orchestration
```
Queue Job → Configuration Generation → Container Deployment → Health Checks
```

## Deployment Actions

### Location: [app/Actions/](mdc:app/Actions)

#### Application Deployment Actions
- **Application/** - Core application deployment logic
- **Docker/** - Docker container management
- **Service/** - Multi-container service orchestration
- **Proxy/** - Reverse proxy configuration

#### Database Actions
- **Database/** - Database deployment and management
- Automated backup scheduling
- Connection management and health checks

#### Server Management Actions
- **Server/** - Server provisioning and configuration
- SSH connection establishment
- Docker daemon management

## Configuration Generation

### Dynamic Configuration
- **[ConfigurationGenerator.php](mdc:app/Services/ConfigurationGenerator.php)** - Generates deployment configurations
- **[ConfigurationRepository.php](mdc:app/Services/ConfigurationRepository.php)** - Configuration management

### Generated Configurations
#### Docker Compose Files
```yaml
# Generated docker-compose.yml structure
version: '3.8'
services:
  app:
    image: ${APP_IMAGE}
    environment:
      - ${ENV_VARIABLES}
    labels:
      - traefik.enable=true
      - traefik.http.routers.app.rule=Host(`${FQDN}`)
    volumes:
      - ${VOLUME_MAPPINGS}
    networks:
      - coolify
```

#### Nginx Configurations
- **Reverse proxy** setup
- **SSL termination** with automatic certificates
- **Load balancing** for multiple instances
- **Custom headers** and routing rules

## Container Orchestration

### Docker Integration
- **[DockerImageParser.php](mdc:app/Services/DockerImageParser.php)** - Parse and validate Docker images
- **Container lifecycle** management
- **Resource allocation** and limits
- **Network isolation** and communication

### Volume Management
- **[LocalFileVolume.php](mdc:app/Models/LocalFileVolume.php)** - Persistent file storage
- **[LocalPersistentVolume.php](mdc:app/Models/LocalPersistentVolume.php)** - Data persistence
- **Backup integration** for volume data

### Network Configuration
- **Custom Docker networks** for isolation
- **Service discovery** between containers
- **Port mapping** and exposure
- **SSL/TLS termination**

## Environment Management

### Environment Isolation
- **[Environment.php](mdc:app/Models/Environment.php)** - Development, staging, production environments
- **[EnvironmentVariable.php](mdc:app/Models/EnvironmentVariable.php)** - Application-specific variables
- **[SharedEnvironmentVariable.php](mdc:app/Models/SharedEnvironmentVariable.php)** - Cross-application variables

### Configuration Hierarchy
```
Instance Settings → Server Settings → Project Settings → Application Settings
```

## Preview Environments

### Git-Based Previews
- **[ApplicationPreview.php](mdc:app/Models/ApplicationPreview.php)** - Preview environment management
- **Automatic PR/MR previews** for feature branches
- **Isolated environments** for testing
- **Automatic cleanup** after merge/close

### Preview Workflow
```
Feature Branch → Auto-Deploy → Preview URL → Review → Cleanup
```

## SSL & Security

### Certificate Management
- **[SslCertificate.php](mdc:app/Models/SslCertificate.php)** - SSL certificate automation
- **Let's Encrypt** integration for free certificates
- **Custom certificate** upload support
- **Automatic renewal** and monitoring

### Security Patterns
- **Private Docker networks** for container isolation
- **SSH key-based** server authentication
- **Environment variable** encryption
- **Access control** via team permissions

## Backup & Recovery

### Database Backups
- **[ScheduledDatabaseBackup.php](mdc:app/Models/ScheduledDatabaseBackup.php)** - Automated database backups
- **[ScheduledDatabaseBackupExecution.php](mdc:app/Models/ScheduledDatabaseBackupExecution.php)** - Backup execution tracking
- **S3-compatible storage** for backup destinations

### Application Backups
- **Volume snapshots** for persistent data
- **Configuration export** for disaster recovery
- **Cross-region replication** for high availability

## Monitoring & Logging

### Real-Time Monitoring
- **[ActivityMonitor.php](mdc:app/Livewire/ActivityMonitor.php)** - Live deployment monitoring
- **WebSocket-based** log streaming
- **Container health checks** and alerts
- **Resource usage** tracking

### Deployment Logs
- **Build process** logging
- **Container startup** logs
- **Application runtime** logs
- **Error tracking** and alerting

## Queue System

### Background Jobs
Location: [app/Jobs/](mdc:app/Jobs)
- **Deployment jobs** for async processing
- **Server monitoring** jobs
- **Backup scheduling** jobs
- **Notification delivery** jobs

### Queue Processing
- **Redis-backed** job queues
- **Laravel Horizon** for queue monitoring
- **Failed job** retry mechanisms
- **Queue worker** auto-scaling

## Multi-Server Deployment

### Server Types
- **Standalone servers** - Single Docker host
- **Docker Swarm** - Multi-node orchestration
- **Remote servers** - SSH-based deployment
- **Local development** - Docker Desktop integration

### Load Balancing
- **Traefik integration** for automatic load balancing
- **Health check** based routing
- **Blue-green deployments** for zero downtime
- **Rolling updates** with configurable strategies

## Deployment Strategies

### Zero-Downtime Deployment
```
Old Container → New Container Build → Health Check → Traffic Switch → Old Container Cleanup
```

### Blue-Green Deployment
- **Parallel environments** for safe deployments
- **Instant rollback** capability
- **Database migration** handling
- **Configuration synchronization**

### Rolling Updates
- **Gradual instance** replacement
- **Configurable update** strategy
- **Automatic rollback** on failure
- **Health check** validation

## API Integration

### Deployment API
Routes: [routes/api.php](mdc:routes/api.php)
- **RESTful endpoints** for deployment management
- **Webhook receivers** for CI/CD integration
- **Status reporting** endpoints
- **Deployment triggering** via API

### Authentication
- **Laravel Sanctum** API tokens
- **Team-based** access control
- **Rate limiting** for API calls
- **Audit logging** for API usage

## Error Handling & Recovery

### Deployment Failure Recovery
- **Automatic rollback** on deployment failure
- **Health check** failure handling
- **Container crash** recovery
- **Resource exhaustion** protection

### Monitoring & Alerting
- **Failed deployment** notifications
- **Resource threshold** alerts
- **SSL certificate** expiry warnings
- **Backup failure** notifications

## Performance Optimization

### Build Optimization
- **Docker layer** caching
- **Multi-stage builds** for smaller images
- **Build artifact** reuse
- **Parallel build** processing

### Docker Build Cache Preservation

Coolify provides settings to preserve Docker build cache across deployments, addressing cache invalidation issues.

#### The Problem

By default, Coolify injects `ARG` statements into user Dockerfiles for build-time variables. This breaks Docker's cache mechanism because:
1. **ARG declarations invalidate cache** - Any change in ARG values after the `ARG` instruction invalidates all subsequent layers
2. **SOURCE_COMMIT changes every commit** - Causes full rebuilds even when code changes are minimal

#### Application Settings

Two toggles in **Advanced Settings** control this behavior:

| Setting | Default | Description |
|---------|---------|-------------|
| `inject_build_args_to_dockerfile` | `true` | Controls whether Coolify adds `ARG` statements to Dockerfile |
| `include_source_commit_in_build` | `false` | Controls whether `SOURCE_COMMIT` is included in build context |

**Database columns:** `application_settings.inject_build_args_to_dockerfile`, `application_settings.include_source_commit_in_build`

#### Buildpack Coverage

| Build Pack | ARG Injection | Method |
|------------|---------------|--------|
| **Dockerfile** | ✅ Yes | `add_build_env_variables_to_dockerfile()` |
| **Docker Compose** (with `build:`) | ✅ Yes | `modify_dockerfiles_for_compose()` |
| **PR Deployments** (Dockerfile only) | ✅ Yes | `add_build_env_variables_to_dockerfile()` |
| **Nixpacks** | ❌ No | Generates its own Dockerfile internally |
| **Static** | ❌ No | Uses internal Dockerfile |
| **Docker Image** | ❌ No | No build phase |

#### How It Works

**When `inject_build_args_to_dockerfile` is enabled (default):**
```dockerfile
# Coolify modifies your Dockerfile to add:
FROM node:20
ARG MY_VAR=value
ARG COOLIFY_URL=...
ARG SOURCE_COMMIT=abc123  # (if include_source_commit_in_build is true)
# ... rest of your Dockerfile
```

**When `inject_build_args_to_dockerfile` is disabled:**
- Coolify does NOT modify the Dockerfile
- `--build-arg` flags are still passed (harmless without matching `ARG` in Dockerfile)
- User must manually add `ARG` statements for any build-time variables they need

**When `include_source_commit_in_build` is disabled (default):**
- `SOURCE_COMMIT` is NOT included in build-time variables
- `SOURCE_COMMIT` is still available at **runtime** (in container environment)
- Docker cache preserved across different commits

#### Recommended Configuration

| Use Case | inject_build_args | include_source_commit | Cache Behavior |
|----------|-------------------|----------------------|----------------|
| Maximum cache preservation | `false` | `false` | Best cache retention |
| Need build-time vars, no commit | `true` | `false` | Cache breaks on var changes |
| Need commit at build-time | `true` | `true` | Cache breaks every commit |
| Manual ARG management | `false` | `true` | Cache preserved (no ARG in Dockerfile) |

#### Implementation Details

**Files:**
- `app/Jobs/ApplicationDeploymentJob.php`:
  - `set_coolify_variables()` - Conditionally adds SOURCE_COMMIT to Docker build context based on `include_source_commit_in_build` setting
  - `generate_coolify_env_variables(bool $forBuildTime)` - Distinguishes build-time vs. runtime variables; excludes cache-busting variables like SOURCE_COMMIT from build context unless explicitly enabled
  - `generate_env_variables()` - Populates `$this->env_args` with build-time ARG values, respecting `include_source_commit_in_build` toggle
  - `add_build_env_variables_to_dockerfile()` - Injects ARG statements into Dockerfiles after FROM instructions; skips injection if `inject_build_args_to_dockerfile` is disabled
  - `modify_dockerfiles_for_compose()` - Applies ARG injection to Docker Compose service Dockerfiles; respects `inject_build_args_to_dockerfile` toggle
- `app/Models/ApplicationSetting.php` - Defines `inject_build_args_to_dockerfile` and `include_source_commit_in_build` boolean properties
- `app/Livewire/Project/Application/Advanced.php` - Livewire component providing UI bindings for cache preservation toggles
- `resources/views/livewire/project/application/advanced.blade.php` - Checkbox UI elements for user-facing toggles

**Note:** Docker Compose services without a `build:` section (image-only) are automatically skipped.

### Runtime Optimization
- **Container resource** limits
- **Auto-scaling** based on metrics
- **Connection pooling** for databases
- **CDN integration** for static assets

## Compliance & Governance

### Audit Trail
- **Deployment history** tracking
- **Configuration changes** logging
- **User action** auditing
- **Resource access** monitoring

### Backup Compliance
- **Retention policies** for backups
- **Encryption at rest** for sensitive data
- **Cross-region** backup replication
- **Recovery testing** automation

## Integration Patterns

### CI/CD Integration
- **GitHub Actions** compatibility
- **GitLab CI** pipeline integration
- **Custom webhook** endpoints
- **Build status** reporting

### External Services
- **S3-compatible** storage integration
- **External database** connections
- **Third-party monitoring** tools
- **Custom notification** channels

---

## Coolify Docker Compose Extensions

Coolify extends standard Docker Compose with custom fields (often called "magic fields") that provide Coolify-specific functionality. These extensions are processed during deployment and stripped before sending the final compose file to Docker, maintaining full compatibility with Docker's compose specification.

### Overview

**Why Custom Fields?**
- Enable Coolify-specific features without breaking Docker Compose compatibility
- Simplify configuration by embedding content directly in compose files
- Allow fine-grained control over health check monitoring
- Reduce external file dependencies

**Processing Flow:**
1. User defines compose file with custom fields
2. Coolify parses and processes custom fields (creates files, stores settings)
3. Custom fields are stripped from final compose sent to Docker
4. Docker receives standard, valid compose file

### Service-Level Extensions

#### `exclude_from_hc`

**Type:** Boolean
**Default:** `false`
**Purpose:** Exclude specific services from health check monitoring while still showing their status

**Example Usage:**
```yaml
services:
  watchtower:
    image: containrrr/watchtower
    exclude_from_hc: true  # Don't monitor this service's health

  backup:
    image: postgres:16
    exclude_from_hc: true  # Backup containers don't need monitoring
    restart: always
```

**Behavior:**
- Container status is still calculated from Docker state (running, exited, etc.)
- Status displays with `:excluded` suffix (e.g., `running:healthy:excluded`)
- UI shows "Monitoring Disabled" indicator
- Functionally equivalent to `restart: no` for health check purposes
- See [Container Status with All Excluded](application-architecture.md#container-status-when-all-containers-excluded) for detailed status handling

**Use Cases:**
- Sidecar containers (watchtower, log collectors)
- Backup/maintenance containers
- One-time initialization containers
- Containers that intentionally restart frequently

**Implementation:**
- Parsed: `bootstrap/helpers/parsers.php`
- Status logic: `app/Traits/CalculatesExcludedStatus.php`
- Validation: `tests/Unit/ExcludeFromHealthCheckTest.php`

### Volume-Level Extensions

Volume extensions only work with **long syntax** (array/object format), not short syntax (string format).

#### `content`

**Type:** String (supports multiline with `|` or `>`)
**Purpose:** Embed file content directly in compose file for automatic creation during deployment

**Example Usage:**
```yaml
services:
  app:
    image: node:20
    volumes:
      # Inline entrypoint script
      - type: bind
        source: ./entrypoint.sh
        target: /app/entrypoint.sh
        content: |
          #!/bin/sh
          set -e
          echo "Starting application..."
          npm run migrate
          exec "$@"

      # Configuration file with environment variables
      - type: bind
        source: ./config.xml
        target: /etc/app/config.xml
        content: |
          <?xml version='1.0' encoding='UTF-8'?>
          <config>
            <database>
              <host>${DB_HOST}</host>
              <port>${DB_PORT}</port>
            </database>
          </config>
```

**Behavior:**
- Content is written to the host at `source` path before container starts
- File is created with mode `644` (readable by all, writable by owner)
- Environment variables in content are interpolated at deployment time
- Content is stored in `LocalFileVolume` model (encrypted at rest)
- Original `docker_compose_raw` retains content for editing

**Use Cases:**
- Entrypoint scripts
- Configuration files
- Environment-specific settings
- Small initialization scripts
- Templates that require dynamic content

**Limitations:**
- Not suitable for large files (use git repo or external storage instead)
- Binary files not supported
- Changes require redeployment

**Real-World Examples:**
- `templates/compose/traccar.yaml` - XML configuration file
- `templates/compose/supabase.yaml` - Multiple config files
- `templates/compose/chaskiq.yaml` - Entrypoint script

**Implementation:**
- Parsed: `bootstrap/helpers/parsers.php` in `parseCompose()` function (handles `content` field extraction)
- Storage: `app/Models/LocalFileVolume.php`
- Validation: `tests/Unit/StripCoolifyCustomFieldsTest.php`

#### `is_directory` / `isDirectory`

**Type:** Boolean
**Default:** `true` (if neither `content` nor explicit flag provided)
**Purpose:** Indicate whether bind mount source should be created as directory or file

**Example Usage:**
```yaml
services:
  app:
    volumes:
      # Explicit file
      - type: bind
        source: ./config.json
        target: /app/config.json
        is_directory: false  # Create as file

      # Explicit directory
      - type: bind
        source: ./logs
        target: /var/log/app
        is_directory: true   # Create as directory

      # Auto-detected as file (has content)
      - type: bind
        source: ./script.sh
        target: /entrypoint.sh
        content: |
          #!/bin/sh
          echo "Hello"
        # is_directory: false implied by content presence
```

**Behavior:**
- If `is_directory: true` → Creates directory with `mkdir -p`
- If `is_directory: false` → Creates empty file with `touch`
- If `content` provided → Implies `is_directory: false`
- If neither specified → Defaults to `true` (directory)

**Naming Conventions:**
- `is_directory` (snake_case) - **Preferred**, consistent with PHP/Laravel conventions
- `isDirectory` (camelCase) - **Legacy support**, both work identically

**Use Cases:**
- Disambiguating files vs directories when no content provided
- Ensuring correct bind mount type for Docker
- Pre-creating mount points before container starts

**Implementation:**
- Parsed: `bootstrap/helpers/parsers.php` in `parseCompose()` function (handles `is_directory`/`isDirectory` field extraction)
- Storage: `app/Models/LocalFileVolume.php` (`is_directory` column)
- Validation: `tests/Unit/StripCoolifyCustomFieldsTest.php`

### Custom Field Stripping

**Function:** `stripCoolifyCustomFields()` in `bootstrap/helpers/docker.php`

All custom fields are removed before the compose file is sent to Docker. This happens in two contexts:

**1. Validation (User-Triggered)**
```php
// In validateComposeFile() - Edit Docker Compose modal
$yaml_compose = Yaml::parse($compose);
$yaml_compose = stripCoolifyCustomFields($yaml_compose);  // Strip custom fields
// Send to docker compose config for validation
```

**2. Deployment (Automatic)**
```php
// In Service::parse() - During deployment
$docker_compose = parseCompose($docker_compose_raw);
// Custom fields are processed and then stripped
// Final compose sent to Docker has no custom fields
```

**What Gets Stripped:**
- Service-level: `exclude_from_hc`
- Volume-level: `content`, `isDirectory`, `is_directory`

**What's Preserved:**
- All standard Docker Compose fields
- Environment variables
- Standard volume definitions (after custom fields removed)

### Important Notes

#### Long vs Short Volume Syntax

**✅ Long Syntax (Works with Custom Fields):**
```yaml
volumes:
  - type: bind
    source: ./data
    target: /app/data
    content: "Hello"    # ✅ Custom fields work here
```

**❌ Short Syntax (Custom Fields Ignored):**
```yaml
volumes:
  - "./data:/app/data"  # ❌ Cannot add custom fields to strings
```

#### Docker Compose Compatibility

Custom fields are **Coolify-specific** and won't work with standalone `docker compose` CLI:

```bash
# ❌ Won't work - Docker doesn't recognize custom fields
docker compose -f compose.yaml up

# ✅ Works - Use Coolify's deployment (strips custom fields first)
# Deploy through Coolify UI or API
```

#### Editing Custom Fields

When editing in "Edit Docker Compose" modal:
- Custom fields are preserved in the editor
- "Validate" button strips them temporarily for Docker validation
- "Save" button preserves them in `docker_compose_raw`
- They're processed again on next deployment

### Template Examples

See these templates for real-world usage:

**Service Exclusions:**
- `templates/compose/budibase.yaml` - Excludes watchtower from monitoring
- `templates/compose/pgbackweb.yaml` - Excludes backup service
- `templates/compose/elasticsearch-with-kibana.yaml` - Excludes elasticsearch

**Inline Content:**
- `templates/compose/traccar.yaml` - XML configuration (multiline)
- `templates/compose/supabase.yaml` - Multiple config files
- `templates/compose/searxng.yaml` - Settings file
- `templates/compose/invoice-ninja.yaml` - Nginx config

**Directory Flags:**
- `templates/compose/paperless.yaml` - Explicit directory creation

### Testing

**Unit Tests:**
- `tests/Unit/StripCoolifyCustomFieldsTest.php` - Custom field stripping logic
- `tests/Unit/ExcludeFromHealthCheckTest.php` - Health check exclusion behavior
- `tests/Unit/ContainerStatusAggregatorTest.php` - Status aggregation with exclusions

**Test Coverage:**
- ✅ All custom fields (exclude_from_hc, content, isDirectory, is_directory)
- ✅ Multiline content (YAML `|` syntax)
- ✅ Short vs long volume syntax
- ✅ Field stripping without data loss
- ✅ Standard Docker Compose field preservation
