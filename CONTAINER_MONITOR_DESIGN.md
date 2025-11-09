# Coolify Container Monitor - System Design

## Executive Summary

A standalone microservice for monitoring, managing, and analyzing Docker containers across all Coolify-managed servers. Built with Laravel, PostgreSQL + TimescaleDB, and Livewire, following Coolify's architectural patterns.

---

## 1. System Architecture

### High-Level Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    Container Monitor Dashboard                   │
│              (Laravel + Livewire + Alpine.js)                   │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                  Container Monitor Backend API                   │
│  ┌──────────────┐  ┌──────────────┐  ┌─────────────────────┐  │
│  │   Metrics    │  │   Alerting   │  │   Container         │  │
│  │  Collector   │  │   Engine     │  │   Actions           │  │
│  └──────────────┘  └──────────────┘  └─────────────────────┘  │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│              PostgreSQL + TimescaleDB (Metrics DB)               │
│  ┌────────────────┐  ┌──────────────┐  ┌──────────────────┐   │
│  │ container_     │  │ metric_      │  │ alert_rules /    │   │
│  │ snapshots      │  │ timeseries   │  │ alert_history    │   │
│  └────────────────┘  └──────────────┘  └──────────────────┘   │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Coolify API Layer                          │
│  - Authentication (Sanctum tokens)                               │
│  - Server/Project/Application data                               │
│  - Container deployment triggers                                 │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Managed Servers (Docker)                      │
│  - Docker Engine API (stats, container info)                    │
│  - Optional: Monitoring agent for enhanced metrics              │
└─────────────────────────────────────────────────────────────────┘
```

### Component Breakdown

#### 1. **Container Monitor Backend**
- **Tech**: Laravel 12 (PHP 8.4)
- **Purpose**: Core business logic, API endpoints, job processing
- **Key Modules**:
  - Coolify API client
  - Docker metrics collector
  - Alert engine
  - Container action executor

#### 2. **Container Monitor Dashboard**
- **Tech**: Livewire 3 + Alpine.js + Tailwind CSS 4
- **Purpose**: Web UI for viewing and managing containers
- **Key Features**:
  - Multi-server container overview
  - Real-time metrics charts
  - Sorting/filtering by server, project, health, uptime
  - One-click container actions (update, restart, stop)
  - Alert configuration UI

#### 3. **Metrics Database**
- **Tech**: PostgreSQL 15 + TimescaleDB extension (open-source)
- **Purpose**: Store time-series metrics and container metadata
- **Retention**: 90 days (configurable)

#### 4. **Queue System**
- **Tech**: Laravel Horizon + Redis
- **Purpose**: Background job processing for metrics collection and alerts

---

## 2. Technology Stack

### Backend
- **Framework**: Laravel 12 (PHP 8.4)
- **Database**: PostgreSQL 15 + TimescaleDB 2.x (OSS)
- **Cache/Queue**: Redis 7
- **Queue Manager**: Laravel Horizon
- **Testing**: Pest 3 + PHPUnit 11

### Frontend
- **Components**: Livewire 3
- **JavaScript**: Alpine.js (bundled with Livewire)
- **Styling**: Tailwind CSS 4
- **Charts**: Chart.js or ApexCharts

### Infrastructure
- **Container**: Docker + Docker Compose
- **Deployment**: Self-hosted (local or remote)

### External Integrations
- **Coolify API**: Sanctum token authentication
- **Docker Engine API**: Direct TCP/Unix socket access

---

## 3. Database Schema

### Core Tables

#### 3.1 **coolify_instances**
Stores Coolify instance connections.

```sql
CREATE TABLE coolify_instances (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    api_token TEXT NOT NULL,
    is_active BOOLEAN DEFAULT true,
    last_synced_at TIMESTAMP,
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_coolify_instances_active ON coolify_instances(is_active);
```

#### 3.2 **servers**
Synced from Coolify, stores server information.

```sql
CREATE TABLE servers (
    id BIGSERIAL PRIMARY KEY,
    coolify_instance_id BIGINT REFERENCES coolify_instances(id) ON DELETE CASCADE,
    coolify_server_id BIGINT NOT NULL, -- ID from Coolify
    name VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    port INTEGER DEFAULT 22,
    docker_endpoint VARCHAR(500), -- Docker API endpoint
    is_reachable BOOLEAN DEFAULT true,
    metadata JSONB DEFAULT '{}', -- Store team_id, labels, etc.
    last_checked_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(coolify_instance_id, coolify_server_id)
);

CREATE INDEX idx_servers_instance ON servers(coolify_instance_id);
CREATE INDEX idx_servers_reachable ON servers(is_reachable);
```

#### 3.3 **containers**
Tracks all Docker containers.

```sql
CREATE TABLE containers (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT REFERENCES servers(id) ON DELETE CASCADE,
    container_id VARCHAR(64) NOT NULL, -- Docker container ID
    container_name VARCHAR(255) NOT NULL,
    image VARCHAR(500) NOT NULL,
    image_tag VARCHAR(100),
    status VARCHAR(50), -- running, stopped, paused, etc.

    -- Coolify relationships (nullable for non-Coolify containers)
    coolify_application_id BIGINT,
    coolify_service_id BIGINT,
    coolify_database_id BIGINT,
    coolify_project_id BIGINT,
    coolify_environment_id BIGINT,

    -- Container metadata
    labels JSONB DEFAULT '{}',
    ports JSONB DEFAULT '[]',
    networks JSONB DEFAULT '[]',
    volumes JSONB DEFAULT '[]',

    -- Health & uptime
    health_status VARCHAR(50), -- healthy, unhealthy, none
    started_at TIMESTAMP,

    -- Tracking
    first_seen_at TIMESTAMP DEFAULT NOW(),
    last_seen_at TIMESTAMP DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),

    UNIQUE(server_id, container_id)
);

CREATE INDEX idx_containers_server ON containers(server_id);
CREATE INDEX idx_containers_status ON containers(status);
CREATE INDEX idx_containers_coolify_app ON containers(coolify_application_id);
CREATE INDEX idx_containers_coolify_service ON containers(coolify_service_id);
CREATE INDEX idx_containers_coolify_db ON containers(coolify_database_id);
CREATE INDEX idx_containers_image ON containers(image);
```

#### 3.4 **container_metrics** (TimescaleDB Hypertable)
Time-series metrics for containers.

```sql
CREATE TABLE container_metrics (
    time TIMESTAMPTZ NOT NULL,
    container_id BIGINT REFERENCES containers(id) ON DELETE CASCADE,

    -- CPU metrics
    cpu_usage_percent NUMERIC(5,2),
    cpu_system_usage BIGINT,
    cpu_online_cpus INTEGER,

    -- Memory metrics
    memory_usage_bytes BIGINT,
    memory_limit_bytes BIGINT,
    memory_usage_percent NUMERIC(5,2),
    memory_cache_bytes BIGINT,

    -- Network metrics
    network_rx_bytes BIGINT,
    network_tx_bytes BIGINT,
    network_rx_packets BIGINT,
    network_tx_packets BIGINT,

    -- Disk/Block I/O metrics
    block_read_bytes BIGINT,
    block_write_bytes BIGINT,

    -- PIDs
    pids_current INTEGER,
    pids_limit INTEGER,

    created_at TIMESTAMP DEFAULT NOW()
);

-- Convert to TimescaleDB hypertable
SELECT create_hypertable('container_metrics', 'time');

-- Create indexes
CREATE INDEX idx_container_metrics_container_time ON container_metrics(container_id, time DESC);
CREATE INDEX idx_container_metrics_time ON container_metrics(time DESC);

-- Set retention policy (90 days)
SELECT add_retention_policy('container_metrics', INTERVAL '90 days');
```

#### 3.5 **alert_rules**
Alert configuration.

```sql
CREATE TABLE alert_rules (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,

    -- Scope
    scope VARCHAR(50) NOT NULL, -- 'global', 'server', 'container', 'application'
    scope_id BIGINT, -- server_id, container_id, etc.

    -- Rule definition
    metric VARCHAR(100) NOT NULL, -- 'cpu_usage', 'memory_usage', 'container_down', etc.
    operator VARCHAR(20) NOT NULL, -- '>', '<', '>=', '<=', '==', '!='
    threshold NUMERIC(10,2) NOT NULL,
    duration_seconds INTEGER DEFAULT 60, -- How long condition must persist

    -- Alert settings
    severity VARCHAR(20) DEFAULT 'warning', -- 'info', 'warning', 'critical'
    notification_channels JSONB DEFAULT '[]', -- ['email', 'slack', 'webhook']
    cooldown_minutes INTEGER DEFAULT 15, -- Prevent alert spam

    is_active BOOLEAN DEFAULT true,
    metadata JSONB DEFAULT '{}',

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_alert_rules_active ON alert_rules(is_active);
CREATE INDEX idx_alert_rules_scope ON alert_rules(scope, scope_id);
```

#### 3.6 **alert_history**
Alert event history.

```sql
CREATE TABLE alert_history (
    id BIGSERIAL PRIMARY KEY,
    alert_rule_id BIGINT REFERENCES alert_rules(id) ON DELETE CASCADE,
    container_id BIGINT REFERENCES containers(id) ON DELETE CASCADE,

    triggered_at TIMESTAMP NOT NULL,
    resolved_at TIMESTAMP,

    severity VARCHAR(20),
    message TEXT,
    metric_value NUMERIC(10,2),
    threshold NUMERIC(10,2),

    notifications_sent JSONB DEFAULT '{}', -- {'email': true, 'slack': false}

    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_alert_history_rule ON alert_history(alert_rule_id);
CREATE INDEX idx_alert_history_container ON alert_history(container_id);
CREATE INDEX idx_alert_history_triggered ON alert_history(triggered_at DESC);
```

#### 3.7 **collection_settings**
Configurable collection intervals.

```sql
CREATE TABLE collection_settings (
    id BIGSERIAL PRIMARY KEY,
    scope VARCHAR(50) NOT NULL, -- 'global', 'server', 'container'
    scope_id BIGINT, -- NULL for global

    collection_interval_seconds INTEGER DEFAULT 60,
    is_enabled BOOLEAN DEFAULT true,

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),

    UNIQUE(scope, scope_id)
);

-- Global default
INSERT INTO collection_settings (scope, collection_interval_seconds)
VALUES ('global', 60);
```

#### 3.8 **container_actions_log**
Audit log for container actions.

```sql
CREATE TABLE container_actions_log (
    id BIGSERIAL PRIMARY KEY,
    container_id BIGINT REFERENCES containers(id) ON DELETE CASCADE,
    action VARCHAR(50) NOT NULL, -- 'update', 'restart', 'stop', 'start', 'redeploy'
    triggered_by VARCHAR(100), -- user email or 'system'

    status VARCHAR(20) NOT NULL, -- 'pending', 'success', 'failed'
    error_message TEXT,

    metadata JSONB DEFAULT '{}',

    created_at TIMESTAMP DEFAULT NOW(),
    completed_at TIMESTAMP
);

CREATE INDEX idx_actions_log_container ON container_actions_log(container_id);
CREATE INDEX idx_actions_log_created ON container_actions_log(created_at DESC);
```

---

## 4. API Integration with Coolify

### 4.1 Authentication
- Use Coolify's **Sanctum API tokens** for authentication
- Store token securely in `coolify_instances.api_token`
- Support multiple Coolify instances

### 4.2 Required Coolify API Endpoints

We'll need to interact with these Coolify endpoints:

```php
// Coolify API Client Interface
interface CoolifyApiInterface
{
    // Authentication
    public function testConnection(): bool;

    // Server data
    public function getServers(): Collection;
    public function getServer(int $serverId): Server;

    // Projects & Applications
    public function getProjects(): Collection;
    public function getApplications(): Collection;
    public function getApplication(int $applicationId): Application;

    // Services & Databases
    public function getServices(): Collection;
    public function getDatabases(): Collection;

    // Container actions
    public function deployApplication(int $applicationId): bool;
    public function restartContainer(int $serverId, string $containerId): bool;
    public function updateAndRestart(int $applicationId): bool;

    // Docker API access
    public function getDockerStats(int $serverId, string $containerId): array;
    public function listContainers(int $serverId): Collection;
}
```

### 4.3 Sync Strategy

**Initial Sync Job** (`SyncCoolifyDataJob`):
- Runs on-demand or scheduled (hourly)
- Fetches all servers, projects, applications, services, databases from Coolify
- Updates local cache tables

**Incremental Updates**:
- Container discovery runs every X seconds (configurable)
- Compares current containers with database
- Marks containers as removed if no longer present

---

## 5. Metrics Collection Strategy

### 5.1 Collection Flow

```
┌─────────────────────────────────────────────────────────────┐
│  ScheduleMetricsCollectionJob (every 20s - 1hr)            │
│  - Determines which containers need metrics                │
│  - Dispatches individual collection jobs                   │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  CollectContainerMetricsJob (per container)                │
│  1. Connect to server's Docker API                         │
│  2. Fetch container stats (CPU, memory, network, disk)     │
│  3. Store in container_metrics hypertable                  │
│  4. Check alert rules                                       │
└─────────────────────────────────────────────────────────────┘
```

### 5.2 Docker API Integration

Use Docker Engine API to collect metrics:

```php
class DockerMetricsCollector
{
    public function getContainerStats(Server $server, string $containerId): array
    {
        // Docker API: GET /containers/{id}/stats?stream=false
        $response = $this->dockerClient->get("/containers/{$containerId}/stats", [
            'query' => ['stream' => false]
        ]);

        return $this->parseStats($response);
    }

    private function parseStats(array $stats): array
    {
        return [
            'cpu_usage_percent' => $this->calculateCpuPercent($stats),
            'memory_usage_bytes' => $stats['memory_stats']['usage'] ?? 0,
            'memory_limit_bytes' => $stats['memory_stats']['limit'] ?? 0,
            'memory_usage_percent' => $this->calculateMemoryPercent($stats),
            'network_rx_bytes' => $this->sumNetworkRx($stats),
            'network_tx_bytes' => $this->sumNetworkTx($stats),
            // ... etc
        ];
    }
}
```

### 5.3 Collection Intervals

- **Global default**: 60 seconds
- **Per-server override**: Configure specific servers for faster/slower collection
- **Per-container override**: Critical containers can have 20s intervals
- **On-demand**: Manual trigger from dashboard

---

## 6. Container Actions

### 6.1 Supported Actions

#### **Pull Latest Image + Restart**
```php
class UpdateContainerImageAction
{
    public function execute(Container $container): bool
    {
        // 1. Pull latest image
        $this->dockerClient->post("/images/create", [
            'fromImage' => $container->image,
            'tag' => $container->image_tag ?? 'latest'
        ]);

        // 2. Stop container
        $this->dockerClient->post("/containers/{$container->container_id}/stop");

        // 3. Remove old container
        $this->dockerClient->delete("/containers/{$container->container_id}");

        // 4. Recreate with same config
        $this->dockerClient->post("/containers/create", [
            'Image' => $container->image,
            // ... same volumes, networks, env, etc
        ]);

        // 5. Start new container
        $this->dockerClient->post("/containers/{$newContainerId}/start");

        return true;
    }
}
```

#### **Trigger Coolify Deployment**
```php
class RedeployApplicationAction
{
    public function execute(Container $container): bool
    {
        if (!$container->coolify_application_id) {
            throw new Exception("Not a Coolify-managed application");
        }

        return $this->coolifyApi->deployApplication(
            $container->coolify_application_id
        );
    }
}
```

### 6.2 Other Actions
- **Restart**: `docker restart {container_id}`
- **Stop**: `docker stop {container_id}`
- **Start**: `docker start {container_id}`
- **View Logs**: Stream last 500 lines

All actions are logged in `container_actions_log`.

---

## 7. Alerting & Notification System

### 7.1 Alert Engine

**Job**: `ProcessAlertRulesJob`
- Runs every 30 seconds
- Evaluates active alert rules
- Triggers notifications if thresholds breached

```php
class ProcessAlertRulesJob implements ShouldQueue
{
    public function handle()
    {
        $activeRules = AlertRule::where('is_active', true)->get();

        foreach ($activeRules as $rule) {
            $this->evaluateRule($rule);
        }
    }

    private function evaluateRule(AlertRule $rule)
    {
        // Get containers in scope
        $containers = $this->getContainersForRule($rule);

        foreach ($containers as $container) {
            $metricValue = $this->getLatestMetric($container, $rule->metric);

            if ($this->checkThreshold($metricValue, $rule->operator, $rule->threshold)) {
                $this->triggerAlert($rule, $container, $metricValue);
            }
        }
    }
}
```

### 7.2 Notification Channels

- **Email**: Laravel Mail
- **Slack**: Slack webhook integration
- **Webhook**: POST to custom URL with alert payload
- **Database**: Store in `alert_history`

### 7.3 Alert Examples

1. **High CPU Alert**
   - Metric: `cpu_usage_percent`
   - Operator: `>`
   - Threshold: `85`
   - Duration: `120s`

2. **Memory Critical**
   - Metric: `memory_usage_percent`
   - Operator: `>`
   - Threshold: `95`
   - Duration: `60s`

3. **Container Down**
   - Metric: `container_status`
   - Operator: `==`
   - Threshold: `stopped`
   - Duration: `0s`

---

## 8. Docker Compose Configuration

### 8.1 `docker-compose.yml`

```yaml
version: '3.8'

services:
  app:
    image: container-monitor:latest
    build:
      context: .
      dockerfile: Dockerfile
    container_name: container-monitor
    restart: unless-stopped
    working_dir: /var/www
    volumes:
      - ./:/var/www
      - ./storage/app:/var/www/storage/app
      - ./storage/logs:/var/www/storage/logs
    networks:
      - container-monitor-network
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - DB_CONNECTION=pgsql
      - DB_HOST=postgres
      - DB_DATABASE=container_monitor
      - DB_USERNAME=container_monitor
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=redis
      - REDIS_PASSWORD=${REDIS_PASSWORD}
    depends_on:
      - postgres
      - redis
    ports:
      - "${APP_PORT:-8080}:80"

  postgres:
    image: timescale/timescaledb:latest-pg15
    container_name: container-monitor-postgres
    restart: unless-stopped
    environment:
      POSTGRES_DB: container_monitor
      POSTGRES_USER: container_monitor
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - postgres-data:/var/lib/postgresql/data
    networks:
      - container-monitor-network
    ports:
      - "${POSTGRES_PORT:-5433}:5432"

  redis:
    image: redis:7-alpine
    container_name: container-monitor-redis
    restart: unless-stopped
    command: redis-server --requirepass ${REDIS_PASSWORD}
    volumes:
      - redis-data:/data
    networks:
      - container-monitor-network

  horizon:
    image: container-monitor:latest
    container_name: container-monitor-horizon
    restart: unless-stopped
    working_dir: /var/www
    volumes:
      - ./:/var/www
    networks:
      - container-monitor-network
    environment:
      - APP_ENV=production
      - DB_CONNECTION=pgsql
      - DB_HOST=postgres
      - REDIS_HOST=redis
    depends_on:
      - postgres
      - redis
      - app
    command: php artisan horizon

  scheduler:
    image: container-monitor:latest
    container_name: container-monitor-scheduler
    restart: unless-stopped
    working_dir: /var/www
    volumes:
      - ./:/var/www
    networks:
      - container-monitor-network
    environment:
      - APP_ENV=production
      - DB_CONNECTION=pgsql
      - DB_HOST=postgres
      - REDIS_HOST=redis
    depends_on:
      - postgres
      - redis
      - app
    command: sh -c "while true; do php artisan schedule:run --verbose --no-interaction & sleep 60; done"

networks:
  container-monitor-network:
    driver: bridge

volumes:
  postgres-data:
  redis-data:
```

### 8.2 Environment Variables (`.env`)

```bash
APP_NAME="Container Monitor"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost:8080
APP_PORT=8080

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=container_monitor
DB_USERNAME=container_monitor
DB_PASSWORD=

POSTGRES_PORT=5433

REDIS_HOST=redis
REDIS_PASSWORD=
REDIS_PORT=6379

QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# Coolify Integration
COOLIFY_DEFAULT_URL=https://your-coolify.com
COOLIFY_DEFAULT_TOKEN=

# Collection Settings
METRICS_COLLECTION_INTERVAL=60
METRICS_RETENTION_DAYS=90

# Alerting
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=

SLACK_WEBHOOK_URL=
```

---

## 9. Directory Structure

Following Laravel/Coolify conventions:

```
container-monitor/
├── app/
│   ├── Actions/
│   │   ├── Container/
│   │   │   ├── UpdateContainerImageAction.php
│   │   │   ├── RestartContainerAction.php
│   │   │   ├── RedeployApplicationAction.php
│   │   │   └── StopContainerAction.php
│   │   ├── Metrics/
│   │   │   ├── CollectContainerMetricsAction.php
│   │   │   └── CalculateAggregateMetricsAction.php
│   │   ├── Alerts/
│   │   │   ├── EvaluateAlertRuleAction.php
│   │   │   └── SendAlertNotificationAction.php
│   │   └── Sync/
│   │       ├── SyncCoolifyServersAction.php
│   │       ├── SyncCoolifyApplicationsAction.php
│   │       └── DiscoverContainersAction.php
│   ├── Jobs/
│   │   ├── SyncCoolifyDataJob.php
│   │   ├── DiscoverContainersJob.php
│   │   ├── ScheduleMetricsCollectionJob.php
│   │   ├── CollectContainerMetricsJob.php
│   │   ├── ProcessAlertRulesJob.php
│   │   ├── SendAlertNotificationJob.php
│   │   └── CleanupOldMetricsJob.php
│   ├── Livewire/
│   │   ├── Dashboard/
│   │   │   ├── Overview.php
│   │   │   ├── ContainersList.php
│   │   │   └── MetricsCharts.php
│   │   ├── Containers/
│   │   │   ├── ContainerDetail.php
│   │   │   ├── ContainerActions.php
│   │   │   └── ContainerLogs.php
│   │   ├── Servers/
│   │   │   ├── ServersList.php
│   │   │   └── ServerDetail.php
│   │   ├── Alerts/
│   │   │   ├── AlertRulesList.php
│   │   │   ├── CreateAlertRule.php
│   │   │   └── AlertHistory.php
│   │   └── Settings/
│   │       ├── CoolifyInstances.php
│   │       ├── CollectionSettings.php
│   │       └── NotificationChannels.php
│   ├── Models/
│   │   ├── CoolifyInstance.php
│   │   ├── Server.php
│   │   ├── Container.php
│   │   ├── ContainerMetric.php
│   │   ├── AlertRule.php
│   │   ├── AlertHistory.php
│   │   ├── CollectionSetting.php
│   │   └── ContainerActionLog.php
│   ├── Services/
│   │   ├── CoolifyApiClient.php
│   │   ├── DockerApiClient.php
│   │   ├── MetricsCollector.php
│   │   ├── AlertEngine.php
│   │   └── NotificationService.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/
│   │   │       ├── ContainerController.php
│   │   │       ├── MetricsController.php
│   │   │       └── AlertController.php
│   │   └── Middleware/
│   │       └── ValidateCoolifyToken.php
│   └── Console/
│       └── Commands/
│           ├── SyncCoolifyData.php
│           ├── CollectMetrics.php
│           └── ProcessAlerts.php
├── bootstrap/
│   └── helpers/
│       ├── docker.php
│       └── metrics.php
├── database/
│   ├── migrations/
│   │   ├── 2024_01_01_000001_create_coolify_instances_table.php
│   │   ├── 2024_01_01_000002_create_servers_table.php
│   │   ├── 2024_01_01_000003_create_containers_table.php
│   │   ├── 2024_01_01_000004_create_container_metrics_table.php
│   │   ├── 2024_01_01_000005_create_alert_rules_table.php
│   │   ├── 2024_01_01_000006_create_alert_history_table.php
│   │   ├── 2024_01_01_000007_create_collection_settings_table.php
│   │   ├── 2024_01_01_000008_create_container_actions_log_table.php
│   │   └── 2024_01_01_000009_enable_timescaledb.php
│   ├── factories/
│   │   ├── ServerFactory.php
│   │   ├── ContainerFactory.php
│   │   └── AlertRuleFactory.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       └── DemoDataSeeder.php
├── resources/
│   └── views/
│       ├── livewire/
│       │   ├── dashboard/
│       │   │   ├── overview.blade.php
│       │   │   ├── containers-list.blade.php
│       │   │   └── metrics-charts.blade.php
│       │   ├── containers/
│       │   │   ├── container-detail.blade.php
│       │   │   ├── container-actions.blade.php
│       │   │   └── container-logs.blade.php
│       │   ├── servers/
│       │   │   ├── servers-list.blade.php
│       │   │   └── server-detail.blade.php
│       │   ├── alerts/
│       │   │   ├── alert-rules-list.blade.php
│       │   │   ├── create-alert-rule.blade.php
│       │   │   └── alert-history.blade.php
│       │   └── settings/
│       │       ├── coolify-instances.blade.php
│       │       ├── collection-settings.blade.php
│       │       └── notification-channels.blade.php
│       └── components/
│           ├── layouts/
│           │   └── app.blade.php
│           ├── metrics/
│           │   ├── cpu-chart.blade.php
│           │   ├── memory-chart.blade.php
│           │   └── network-chart.blade.php
│           └── container-card.blade.php
├── routes/
│   ├── web.php
│   ├── api.php
│   └── console.php
├── tests/
│   ├── Feature/
│   │   ├── CoolifyApiIntegrationTest.php
│   │   ├── MetricsCollectionTest.php
│   │   ├── AlertEngineTest.php
│   │   └── ContainerActionsTest.php
│   └── Unit/
│       ├── DockerMetricsCollectorTest.php
│       ├── AlertRuleEvaluatorTest.php
│       └── MetricsCalculatorTest.php
├── docker-compose.yml
├── Dockerfile
├── .env.example
└── README.md
```

---

## 10. Key Models & Relationships

### Model Relationships

```php
// CoolifyInstance.php
class CoolifyInstance extends Model
{
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}

// Server.php
class Server extends Model
{
    public function coolifyInstance(): BelongsTo
    {
        return $this->belongsTo(CoolifyInstance::class);
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }
}

// Container.php
class Container extends Model
{
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ContainerMetric::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(ContainerMetric::class)->latestOfMany('time');
    }

    public function alertHistory(): HasMany
    {
        return $this->hasMany(AlertHistory::class);
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ContainerActionLog::class);
    }

    // Helper scopes
    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    public function scopeCoolifyManaged($query)
    {
        return $query->whereNotNull('coolify_application_id')
                    ->orWhereNotNull('coolify_service_id')
                    ->orWhereNotNull('coolify_database_id');
    }
}

// AlertRule.php
class AlertRule extends Model
{
    public function alertHistory(): HasMany
    {
        return $this->hasMany(AlertHistory::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
```

---

## 11. Background Jobs & Scheduling

### Laravel Scheduler (`app/Console/Kernel.php`)

```php
protected function schedule(Schedule $schedule)
{
    // Sync Coolify data every hour
    $schedule->job(new SyncCoolifyDataJob)->hourly();

    // Discover new/removed containers every 5 minutes
    $schedule->job(new DiscoverContainersJob)->everyFiveMinutes();

    // Schedule metrics collection (frequency depends on settings)
    $schedule->job(new ScheduleMetricsCollectionJob)->everyMinute();

    // Process alert rules every 30 seconds
    $schedule->job(new ProcessAlertRulesJob)->everyThirtySeconds();

    // Cleanup old metrics beyond retention period (daily at 3am)
    $schedule->job(new CleanupOldMetricsJob)->dailyAt('03:00');
}
```

### Job Priority

Configure Horizon queues (`config/horizon.php`):

```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'queue' => ['critical', 'default', 'low'],
            'balance' => 'auto',
            'processes' => 10,
        ],
    ],
],

'queues' => [
    'critical' => [
        'ProcessAlertRulesJob',
        'SendAlertNotificationJob',
    ],
    'default' => [
        'CollectContainerMetricsJob',
        'DiscoverContainersJob',
    ],
    'low' => [
        'SyncCoolifyDataJob',
        'CleanupOldMetricsJob',
    ],
],
```

---

## 12. Security Considerations

### 12.1 Team-based Permissions

Since Coolify uses team-based access control, we need to respect this:

```php
// Middleware: FilterContainersByTeam
class FilterContainersByTeam
{
    public function handle($request, Closure $next)
    {
        // Get user's team IDs from Coolify
        $userTeams = $this->getUserTeamsFromCoolify($request->user());

        // Store in request for later filtering
        $request->merge(['accessible_team_ids' => $userTeams]);

        return $next($request);
    }
}

// In queries
Container::query()
    ->whereJsonContains('metadata->team_id', $request->accessible_team_ids)
    ->get();
```

### 12.2 API Security

- Validate Coolify API tokens on each request
- Store tokens encrypted in database
- Rate limit API endpoints (60 requests/minute)
- Log all container actions for audit trail

### 12.3 Docker API Access

- Use SSH tunneling for remote Docker API access
- Validate server credentials before connecting
- Never expose Docker socket directly
- Implement connection timeouts (10s default)

---

## 13. UI/UX Design

### 13.1 Main Dashboard

**Route**: `/dashboard`

Components:
- **Overall Stats Cards**: Total containers, running, stopped, unhealthy
- **Server Health Overview**: Grid of servers with status indicators
- **Top Alerts**: Recent critical/warning alerts
- **Resource Usage Summary**: Aggregate CPU/memory across all containers

### 13.2 Containers List

**Route**: `/containers`

Features:
- **Table View** with columns:
  - Container name
  - Server
  - Image
  - Status (badge)
  - CPU% (sparkline)
  - Memory% (sparkline)
  - Uptime
  - Actions (dropdown)

- **Filters/Sorts**:
  - By server
  - By project/application
  - By status (running/stopped/unhealthy)
  - By resource usage (high CPU/memory first)
  - By uptime
  - Search by name/image

- **Bulk Actions**:
  - Restart selected
  - Update selected
  - Stop selected

### 13.3 Container Detail

**Route**: `/containers/{id}`

Tabs:
1. **Overview**: Current stats, metadata, labels
2. **Metrics**: Time-series charts (CPU, memory, network, disk)
3. **Logs**: Live streaming logs (last 500 lines)
4. **Actions**: Update, restart, stop, redeploy
5. **Alerts**: Alert rules for this container
6. **History**: Action log

### 13.4 Servers View

**Route**: `/servers`

- List all servers
- Show aggregate stats per server
- Health indicators
- Click to drill down to containers on that server

### 13.5 Alerts Management

**Route**: `/alerts`

- List all alert rules
- Create/edit alert rules with form
- Test alert rules
- View alert history
- Configure notification channels

### 13.6 Settings

**Route**: `/settings`

Tabs:
1. **Coolify Instances**: Add/edit/remove Coolify connections
2. **Collection Settings**: Configure collection intervals per scope
3. **Notifications**: Configure email/Slack/webhook settings
4. **Retention**: Configure metrics retention period

---

## 14. Example Livewire Components

### 14.1 ContainersList Component

```php
namespace App\Livewire\Containers;

use App\Models\Container;
use Livewire\Component;
use Livewire\WithPagination;

class ContainersList extends Component
{
    use WithPagination;

    public $search = '';
    public $filterServer = null;
    public $filterStatus = null;
    public $sortBy = 'name';
    public $sortDirection = 'asc';

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function sortBy($field)
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function render()
    {
        $containers = Container::query()
            ->with(['server', 'latestMetric'])
            ->when($this->search, function ($query) {
                $query->where('container_name', 'like', "%{$this->search}%")
                      ->orWhere('image', 'like', "%{$this->search}%");
            })
            ->when($this->filterServer, function ($query) {
                $query->where('server_id', $this->filterServer);
            })
            ->when($this->filterStatus, function ($query) {
                $query->where('status', $this->filterStatus);
            })
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(25);

        return view('livewire.containers.containers-list', [
            'containers' => $containers,
        ]);
    }
}
```

### 14.2 ContainerActions Component

```php
namespace App\Livewire\Containers;

use App\Actions\Container\RestartContainerAction;
use App\Actions\Container\UpdateContainerImageAction;
use App\Models\Container;
use Livewire\Component;

class ContainerActions extends Component
{
    public Container $container;

    public function restart()
    {
        try {
            app(RestartContainerAction::class)->execute($this->container);

            $this->dispatch('alert', [
                'type' => 'success',
                'message' => 'Container restarted successfully'
            ]);
        } catch (\Exception $e) {
            $this->dispatch('alert', [
                'type' => 'error',
                'message' => 'Failed to restart: ' . $e->getMessage()
            ]);
        }
    }

    public function updateImage()
    {
        try {
            app(UpdateContainerImageAction::class)->execute($this->container);

            $this->dispatch('alert', [
                'type' => 'success',
                'message' => 'Container image updated and restarted'
            ]);
        } catch (\Exception $e) {
            $this->dispatch('alert', [
                'type' => 'error',
                'message' => 'Failed to update: ' . $e->getMessage()
            ]);
        }
    }

    public function render()
    {
        return view('livewire.containers.container-actions');
    }
}
```

---

## 15. Testing Strategy

### 15.1 Unit Tests

Test isolated logic without database:

```php
// tests/Unit/DockerMetricsCollectorTest.php
it('calculates CPU percentage correctly', function () {
    $collector = new DockerMetricsCollector();

    $stats = [
        'cpu_stats' => [
            'cpu_usage' => ['total_usage' => 1000000000],
            'system_cpu_usage' => 10000000000,
            'online_cpus' => 2,
        ],
        'precpu_stats' => [
            'cpu_usage' => ['total_usage' => 500000000],
            'system_cpu_usage' => 9000000000,
        ],
    ];

    $result = $collector->calculateCpuPercent($stats);

    expect($result)->toBeFloat();
    expect($result)->toBeGreaterThanOrEqual(0);
    expect($result)->toBeLessThanOrEqual(100);
});
```

### 15.2 Feature Tests (run in Docker)

Test full integration flows:

```php
// tests/Feature/MetricsCollectionTest.php
use App\Jobs\CollectContainerMetricsJob;
use App\Models\Container;

it('collects and stores container metrics', function () {
    $container = Container::factory()->create([
        'status' => 'running',
    ]);

    // Mock Docker API response
    Http::fake([
        '*/containers/*/stats*' => Http::response([
            'cpu_stats' => [...],
            'memory_stats' => [...],
        ]),
    ]);

    CollectContainerMetricsJob::dispatch($container);

    $this->assertDatabaseHas('container_metrics', [
        'container_id' => $container->id,
    ]);
});
```

---

## 16. Implementation Roadmap

### Phase 1: Foundation (Week 1-2)
- [ ] Set up Laravel project structure
- [ ] Configure Docker Compose with TimescaleDB
- [ ] Create database migrations
- [ ] Set up models and factories
- [ ] Implement Coolify API client
- [ ] Implement Docker API client
- [ ] Basic Livewire layout and navigation

### Phase 2: Data Sync & Discovery (Week 2-3)
- [ ] Sync Coolify servers/projects/applications
- [ ] Discover containers on servers
- [ ] Store container metadata
- [ ] Implement background jobs for syncing

### Phase 3: Metrics Collection (Week 3-4)
- [ ] Implement metrics collector service
- [ ] Create collection jobs with configurable intervals
- [ ] Store metrics in TimescaleDB hypertable
- [ ] Build Horizon queue configuration

### Phase 4: Dashboard & UI (Week 4-5)
- [ ] Dashboard overview component
- [ ] Containers list with filtering/sorting
- [ ] Container detail page
- [ ] Metrics charts (CPU, memory, network)
- [ ] Servers view

### Phase 5: Container Actions (Week 5-6)
- [ ] Restart container action
- [ ] Update image action
- [ ] Redeploy via Coolify action
- [ ] Container logs viewer
- [ ] Action logging and history

### Phase 6: Alerting System (Week 6-7)
- [ ] Alert rule creation UI
- [ ] Alert rule evaluation engine
- [ ] Notification service (email, Slack, webhook)
- [ ] Alert history and management
- [ ] Alert cooldown and deduplication

### Phase 7: Team Permissions (Week 7)
- [ ] Integrate Coolify team metadata
- [ ] Implement permission filtering
- [ ] User vs. admin views
- [ ] Team-scoped queries

### Phase 8: Testing & Polish (Week 8)
- [ ] Write comprehensive unit tests
- [ ] Write feature tests
- [ ] Performance optimization
- [ ] Documentation
- [ ] Deployment guide

### Phase 9: Advanced Features (Future)
- [ ] Aggregate metrics across servers
- [ ] Cost tracking (resource usage × time)
- [ ] Custom dashboards
- [ ] Metrics export (CSV, JSON)
- [ ] Advanced alerting (ML-based anomaly detection)
- [ ] Multi-user collaboration features

---

## 17. Performance Considerations

### 17.1 Database Optimization

- **Indexes**: Already defined on all foreign keys and frequently queried columns
- **TimescaleDB compression**: Enable after testing
  ```sql
  ALTER TABLE container_metrics SET (
    timescaledb.compress,
    timescaledb.compress_segmentby = 'container_id'
  );

  SELECT add_compression_policy('container_metrics', INTERVAL '7 days');
  ```
- **Continuous aggregates**: Pre-compute hourly/daily averages
  ```sql
  CREATE MATERIALIZED VIEW container_metrics_hourly
  WITH (timescaledb.continuous) AS
  SELECT
    time_bucket('1 hour', time) AS hour,
    container_id,
    AVG(cpu_usage_percent) as avg_cpu,
    AVG(memory_usage_percent) as avg_memory
  FROM container_metrics
  GROUP BY hour, container_id;
  ```

### 17.2 API Rate Limiting

- Limit Coolify API calls (1 full sync per hour, incremental updates)
- Cache server/project data locally
- Batch container discovery per server

### 17.3 Queue Optimization

- Use job batching for metrics collection
- Implement job throttling (max X jobs per server)
- Priority queues for critical alerts

---

## 18. Deployment Guide

### Quick Start

```bash
# Clone repository
git clone https://github.com/your-org/container-monitor.git
cd container-monitor

# Copy environment file
cp .env.example .env

# Generate secure passwords
DB_PASSWORD=$(openssl rand -base64 32)
REDIS_PASSWORD=$(openssl rand -base64 32)
APP_KEY=$(php artisan key:generate --show)

# Update .env with passwords and Coolify details
nano .env

# Build and start containers
docker-compose up -d

# Run migrations
docker exec container-monitor php artisan migrate --force

# Enable TimescaleDB
docker exec container-monitor php artisan timescale:setup

# Create admin user
docker exec container-monitor php artisan user:create

# Access dashboard
# http://localhost:8080
```

### Production Checklist

- [ ] Set `APP_DEBUG=false`
- [ ] Configure proper SMTP for email alerts
- [ ] Set up SSL/TLS (reverse proxy with Let's Encrypt)
- [ ] Configure backup for PostgreSQL
- [ ] Set up log rotation
- [ ] Configure Horizon authentication
- [ ] Review and adjust resource limits in Docker Compose
- [ ] Set up monitoring for Container Monitor itself (meta!)

---

## 19. Future Enhancements

### Advanced Metrics
- **GPU usage** (for containers using NVIDIA runtime)
- **Custom metrics** via Prometheus exporters
- **Application-level metrics** (HTTP requests, response times)

### Integrations
- **Prometheus/Grafana** export
- **Datadog/NewRelic** integration
- **PagerDuty** for critical alerts
- **Terraform** for infrastructure-as-code

### AI/ML Features
- **Anomaly detection** for unusual resource patterns
- **Predictive scaling** recommendations
- **Cost optimization** suggestions

### Multi-tenancy
- **Full multi-tenant mode** (SaaS offering)
- **Organization hierarchy** (teams within orgs)
- **Usage billing** per organization

---

## 20. Summary

This design provides:

✅ **Modular architecture** following Coolify's patterns
✅ **Separate microservice** with API integration
✅ **PostgreSQL + TimescaleDB** for efficient time-series storage
✅ **Comprehensive monitoring** of all containers (Coolify-managed + others)
✅ **Flexible collection intervals** (20s - 1hr, configurable)
✅ **90-day retention** (easily adjustable)
✅ **Team-based permissions** (user + admin views)
✅ **Container actions** (update, restart, redeploy)
✅ **Smart alerting** with notifications
✅ **Docker Compose deployment** (local or remote)
✅ **Livewire dashboard** (separate from Coolify UI)
✅ **Scalable & maintainable** codebase

**Next Steps**: Review this design, provide feedback, and I'll begin implementing Phase 1!

---

## Questions for You

1. **Naming**: Happy with "Container Monitor" or prefer something else?
2. **Authentication**: Should this have its own user system, or authenticate via Coolify SSO?
3. **Branding**: Should the UI match Coolify's design system, or have its own identity?
4. **Charts Library**: Preference between Chart.js, ApexCharts, or something else?
5. **Demo Data**: Want a seeder that creates fake metrics for testing?

Let me know if you'd like me to adjust anything or start building! 🚀
