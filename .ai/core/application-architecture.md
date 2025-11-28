# Coolify Application Architecture

## Laravel Project Structure

### **Core Application Directory** ([app/](mdc:app))

```
app/
├── Actions/           # Business logic actions (Action pattern)
├── Console/           # Artisan commands
├── Contracts/         # Interface definitions
├── Data/              # Data Transfer Objects (Spatie Laravel Data)
├── Enums/             # Enumeration classes
├── Events/            # Event classes
├── Exceptions/        # Custom exception classes
├── Helpers/           # Utility helper classes
├── Http/              # HTTP layer (Controllers, Middleware, Requests)
├── Jobs/              # Background job classes
├── Listeners/         # Event listeners
├── Livewire/          # Livewire components (Frontend)
├── Models/            # Eloquent models (Domain entities)
├── Notifications/     # Notification classes
├── Policies/          # Authorization policies
├── Providers/         # Service providers
├── Repositories/      # Repository pattern implementations
├── Services/          # Service layer classes
├── Traits/            # Reusable trait classes
└── View/              # View composers and creators
```

## Core Domain Models

### **Infrastructure Management**

#### **[Server.php](mdc:app/Models/Server.php)** (46KB, 1343 lines)
- **Purpose**: Physical/virtual server management
- **Key Relationships**:
  - `hasMany(Application::class)` - Deployed applications
  - `hasMany(StandalonePostgresql::class)` - Database instances
  - `belongsTo(Team::class)` - Team ownership
- **Key Features**:
  - SSH connection management
  - Resource monitoring
  - Proxy configuration (Traefik/Caddy)
  - Docker daemon interaction

#### **[Application.php](mdc:app/Models/Application.php)** (74KB, 1734 lines)
- **Purpose**: Application deployment and management
- **Key Relationships**:
  - `belongsTo(Server::class)` - Deployment target
  - `belongsTo(Environment::class)` - Environment context
  - `hasMany(ApplicationDeploymentQueue::class)` - Deployment history
- **Key Features**:
  - Git repository integration
  - Docker build and deployment
  - Environment variable management
  - SSL certificate handling

#### **[Service.php](mdc:app/Models/Service.php)** (58KB, 1325 lines)
- **Purpose**: Multi-container service orchestration
- **Key Relationships**:
  - `hasMany(ServiceApplication::class)` - Service components
  - `hasMany(ServiceDatabase::class)` - Service databases
  - `belongsTo(Environment::class)` - Environment context
- **Key Features**:
  - Docker Compose generation
  - Service dependency management
  - Health check configuration

### **Team & Project Organization**

#### **[Team.php](mdc:app/Models/Team.php)** (8.9KB, 308 lines)
- **Purpose**: Multi-tenant team management
- **Key Relationships**:
  - `hasMany(User::class)` - Team members
  - `hasMany(Project::class)` - Team projects
  - `hasMany(Server::class)` - Team servers
- **Key Features**:
  - Resource limits and quotas
  - Team-based access control
  - Subscription management

#### **[Project.php](mdc:app/Models/Project.php)** (4.3KB, 156 lines)
- **Purpose**: Project organization and grouping
- **Key Relationships**:
  - `hasMany(Environment::class)` - Project environments
  - `belongsTo(Team::class)` - Team ownership
- **Key Features**:
  - Environment isolation
  - Resource organization

#### **[Environment.php](mdc:app/Models/Environment.php)**
- **Purpose**: Environment-specific configuration
- **Key Relationships**:
  - `hasMany(Application::class)` - Environment applications
  - `hasMany(Service::class)` - Environment services
  - `belongsTo(Project::class)` - Project context

### **Database Management Models**

#### **Standalone Database Models**
- **[StandalonePostgresql.php](mdc:app/Models/StandalonePostgresql.php)** (11KB, 351 lines)
- **[StandaloneMysql.php](mdc:app/Models/StandaloneMysql.php)** (11KB, 351 lines)
- **[StandaloneMariadb.php](mdc:app/Models/StandaloneMariadb.php)** (10KB, 337 lines)
- **[StandaloneMongodb.php](mdc:app/Models/StandaloneMongodb.php)** (12KB, 370 lines)
- **[StandaloneRedis.php](mdc:app/Models/StandaloneRedis.php)** (12KB, 394 lines)
- **[StandaloneKeydb.php](mdc:app/Models/StandaloneKeydb.php)** (11KB, 347 lines)
- **[StandaloneDragonfly.php](mdc:app/Models/StandaloneDragonfly.php)** (11KB, 347 lines)
- **[StandaloneClickhouse.php](mdc:app/Models/StandaloneClickhouse.php)** (10KB, 336 lines)

**Common Features**:
- Database configuration management
- Backup scheduling and execution
- Connection string generation
- Health monitoring

### **Configuration & Settings**

#### **[EnvironmentVariable.php](mdc:app/Models/EnvironmentVariable.php)** (7.6KB, 219 lines)
- **Purpose**: Application environment variable management
- **Key Features**:
  - Encrypted value storage
  - Build-time vs runtime variables
  - Shared variable inheritance

#### **[InstanceSettings.php](mdc:app/Models/InstanceSettings.php)** (3.2KB, 124 lines)
- **Purpose**: Global Coolify instance configuration
- **Key Features**:
  - FQDN and port configuration
  - Auto-update settings
  - Security configurations

## Architectural Patterns

### **Action Pattern** ([app/Actions/](mdc:app/Actions))

Using [lorisleiva/laravel-actions](mdc:composer.json) for business logic encapsulation:

```php
// Example Action structure
class DeployApplication extends Action
{
    public function handle(Application $application): void
    {
        // Business logic for deployment
    }
    
    public function asJob(Application $application): void
    {
        // Queue job implementation
    }
}
```

**Key Action Categories**:
- **Application/**: Deployment and management actions
- **Database/**: Database operations
- **Server/**: Server management actions
- **Service/**: Service orchestration actions

### **Repository Pattern** ([app/Repositories/](mdc:app/Repositories))

Data access abstraction layer:
- Encapsulates database queries
- Provides testable data layer
- Abstracts complex query logic

### **Service Layer** ([app/Services/](mdc:app/Services))

Business logic services:
- External API integrations
- Complex business operations
- Cross-cutting concerns

## Data Flow Architecture

### **Request Lifecycle**

1. **HTTP Request** → [routes/web.php](mdc:routes/web.php)
2. **Middleware** → Authentication, authorization
3. **Livewire Component** → [app/Livewire/](mdc:app/Livewire)
4. **Action/Service** → Business logic execution
5. **Model/Repository** → Data persistence
6. **Response** → Livewire reactive update

### **Background Processing**

1. **Job Dispatch** → Queue system (Redis)
2. **Job Processing** → [app/Jobs/](mdc:app/Jobs)
3. **Action Execution** → Business logic
4. **Event Broadcasting** → Real-time updates
5. **Notification** → User feedback

## Security Architecture

### **Multi-Tenant Isolation**

```php
// Team-based query scoping
class Application extends Model
{
    public function scopeOwnedByCurrentTeam($query)
    {
        return $query->whereHas('environment.project.team', function ($q) {
            $q->where('id', currentTeam()->id);
        });
    }
}
```

### **Authorization Layers**

1. **Team Membership** → User belongs to team
2. **Resource Ownership** → Resource belongs to team
3. **Policy Authorization** → [app/Policies/](mdc:app/Policies)
4. **Environment Isolation** → Project/environment boundaries

### **Data Protection**

- **Environment Variables**: Encrypted at rest
- **SSH Keys**: Secure storage and transmission
- **API Tokens**: Sanctum-based authentication
- **Audit Logging**: [spatie/laravel-activitylog](mdc:composer.json)

## Configuration Hierarchy

### **Global Configuration**
- **[InstanceSettings](mdc:app/Models/InstanceSettings.php)**: System-wide settings
- **[config/](mdc:config)**: Laravel configuration files

### **Team Configuration**
- **[Team](mdc:app/Models/Team.php)**: Team-specific settings
- **[ServerSetting](mdc:app/Models/ServerSetting.php)**: Server configurations

### **Project Configuration**
- **[ProjectSetting](mdc:app/Models/ProjectSetting.php)**: Project settings
- **[Environment](mdc:app/Models/Environment.php)**: Environment variables

### **Application Configuration**
- **[ApplicationSetting](mdc:app/Models/ApplicationSetting.php)**: App-specific settings
- **[EnvironmentVariable](mdc:app/Models/EnvironmentVariable.php)**: Runtime configuration

## Event-Driven Architecture

### **Event Broadcasting** ([app/Events/](mdc:app/Events))

Real-time updates using Laravel Echo and WebSockets:

```php
// Example event structure
class ApplicationDeploymentStarted implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("team.{$this->application->team->id}"),
        ];
    }
}
```

### **Event Listeners** ([app/Listeners/](mdc:app/Listeners))

- Deployment status updates
- Resource monitoring alerts
- Notification dispatching
- Audit log creation

## Database Design Patterns

### **Polymorphic Relationships**

```php
// Environment variables can belong to multiple resource types
class EnvironmentVariable extends Model
{
    public function resource(): MorphTo
    {
        return $this->morphTo();
    }
}
```

### **Team-Based Soft Scoping**

All major resources include team-based query scoping:

```php
// Automatic team filtering
$applications = Application::ownedByCurrentTeam()->get();
$servers = Server::ownedByCurrentTeam()->get();
```

### **Configuration Inheritance**

Environment variables cascade from:
1. **Shared Variables** → Team-wide defaults
2. **Project Variables** → Project-specific overrides
3. **Application Variables** → Application-specific values

## Integration Patterns

### **Git Provider Integration**

Abstracted git operations supporting:
- **GitHub**: [app/Models/GithubApp.php](mdc:app/Models/GithubApp.php)
- **GitLab**: [app/Models/GitlabApp.php](mdc:app/Models/GitlabApp.php)
- **Bitbucket**: Webhook integration
- **Gitea**: Self-hosted Git support

### **Docker Integration**

- **Container Management**: Direct Docker API communication
- **Image Building**: Dockerfile and Buildpack support
- **Network Management**: Custom Docker networks
- **Volume Management**: Persistent storage handling

### **SSH Communication**

- **[phpseclib/phpseclib](mdc:composer.json)**: Secure SSH connections
- **Multiplexing**: Connection pooling for efficiency
- **Key Management**: [PrivateKey](mdc:app/Models/PrivateKey.php) model

## Testing Architecture

### **Test Structure** ([tests/](mdc:tests))

```
tests/
├── Feature/           # Integration tests
├── Unit/              # Unit tests
├── Browser/           # Dusk browser tests
├── Traits/            # Test helper traits
├── Pest.php           # Pest configuration
└── TestCase.php       # Base test case
```

### **Testing Patterns**

- **Feature Tests**: Full request lifecycle testing
- **Unit Tests**: Individual class/method testing
- **Browser Tests**: End-to-end user workflows
- **Database Testing**: Factories and seeders

## Performance Considerations

### **Query Optimization**

- **Eager Loading**: Prevent N+1 queries
- **Query Scoping**: Team-based filtering
- **Database Indexing**: Optimized for common queries

### **Caching Strategy**

- **Redis**: Session and cache storage
- **Model Caching**: Frequently accessed data
- **Query Caching**: Expensive query results

### **Background Processing**

- **Queue Workers**: Horizon-managed job processing
- **Job Batching**: Related job grouping
- **Failed Job Handling**: Automatic retry logic

## Container Status Monitoring System

### **Overview**

Container health status is monitored and updated through **multiple independent paths**. When modifying status logic, **ALL paths must be updated** to ensure consistency.

### **Critical Implementation Locations**

#### **1. SSH-Based Status Updates (Scheduled)**
**File**: [app/Actions/Docker/GetContainersStatus.php](mdc:app/Actions/Docker/GetContainersStatus.php)
**Method**: `aggregateApplicationStatus()` (lines 487-540)
**Trigger**: Scheduled job or manual refresh
**Frequency**: Every minute (via `ServerCheckJob`)

**Status Aggregation Logic**:
```php
// Tracks multiple status flags
$hasRunning = false;
$hasRestarting = false;
$hasUnhealthy = false;
$hasUnknown = false;  // ⚠️ CRITICAL: Must track unknown
$hasExited = false;
// ... more states

// Priority: restarting > degraded > running (unhealthy > unknown > healthy)
if ($hasRunning) {
    if ($hasUnhealthy) return 'running (unhealthy)';
    elseif ($hasUnknown) return 'running (unknown)';
    else return 'running (healthy)';
}
```

#### **2. Sentinel-Based Status Updates (Real-time)**
**File**: [app/Jobs/PushServerUpdateJob.php](mdc:app/Jobs/PushServerUpdateJob.php)
**Method**: `aggregateMultiContainerStatuses()` (lines 269-298)
**Trigger**: Sentinel push updates from remote servers
**Frequency**: Every ~30 seconds (real-time)

**Status Aggregation Logic**:
```php
// ⚠️ MUST match GetContainersStatus logic
$hasRunning = false;
$hasUnhealthy = false;
$hasUnknown = false;  // ⚠️ CRITICAL: Added to fix bug

foreach ($relevantStatuses as $status) {
    if (str($status)->contains('running')) {
        $hasRunning = true;
        if (str($status)->contains('unhealthy')) $hasUnhealthy = true;
        if (str($status)->contains('unknown')) $hasUnknown = true;  // ⚠️ CRITICAL
    }
}

// Priority: unhealthy > unknown > healthy
if ($hasRunning) {
    if ($hasUnhealthy) $aggregatedStatus = 'running (unhealthy)';
    elseif ($hasUnknown) $aggregatedStatus = 'running (unknown)';
    else $aggregatedStatus = 'running (healthy)';
}
```

#### **3. Multi-Server Status Aggregation**
**File**: [app/Actions/Shared/ComplexStatusCheck.php](mdc:app/Actions/Shared/ComplexStatusCheck.php)
**Method**: `resource()` (lines 48-210)
**Purpose**: Aggregates status across multiple servers for applications
**Used by**: Applications with multiple destinations

**Key Features**:
- Aggregates statuses from main + additional servers
- Handles excluded containers (`:excluded` suffix)
- Calculates overall application health from all containers

**Status Format with Excluded Containers**:
```php
// When all containers excluded from health checks:
return 'running:unhealthy:excluded';  // Container running but unhealthy, monitoring disabled
return 'running:unknown:excluded';     // Container running, health unknown, monitoring disabled
return 'running:healthy:excluded';     // Container running and healthy, monitoring disabled
return 'degraded:excluded';            // Some containers down, monitoring disabled
return 'exited:excluded';              // All containers stopped, monitoring disabled
```

#### **4. Service-Level Status Aggregation**
**File**: [app/Models/Service.php](mdc:app/Models/Service.php)
**Method**: `complexStatus()` (lines 176-288)
**Purpose**: Aggregates status for multi-container services
**Used by**: Docker Compose services

**Status Calculation**:
```php
// Aggregates status from all service applications and databases
// Handles excluded containers separately
// Returns status with :excluded suffix when all containers excluded
if (!$hasNonExcluded && $complexStatus === null && $complexHealth === null) {
    // All services excluded - calculate from excluded containers
    return "{$excludedStatus}:excluded";
}
```

### **Status Flow Diagram**

```
┌─────────────────────────────────────────────────────────────┐
│                    Container Status Sources                  │
└─────────────────────────────────────────────────────────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
        ▼                    ▼                    ▼
┌───────────────┐   ┌─────────────────┐   ┌──────────────┐
│ SSH-Based     │   │ Sentinel-Based  │   │ Multi-Server │
│ (Scheduled)   │   │ (Real-time)     │   │ Aggregation  │
├───────────────┤   ├─────────────────┤   ├──────────────┤
│ ServerCheck   │   │ PushServerUp-   │   │ ComplexStatus│
│ Job           │   │ dateJob         │   │ Check        │
│               │   │                 │   │              │
│ Every ~1min   │   │ Every ~30sec    │   │ On demand    │
└───────┬───────┘   └────────┬────────┘   └──────┬───────┘
        │                    │                    │
        └────────────────────┼────────────────────┘
                             │
                             ▼
                 ┌───────────────────────┐
                 │ Application/Service   │
                 │ Status Property       │
                 └───────────────────────┘
                             │
                             ▼
                 ┌───────────────────────┐
                 │ UI Display (Livewire) │
                 └───────────────────────┘
```

### **Status Priority System**

All status aggregation locations **MUST** follow the same priority:

**For Running Containers**:
1. **unhealthy** - Container has failing health checks
2. **unknown** - Container health status cannot be determined
3. **healthy** - Container is healthy

**For Non-Running States**:
1. **restarting** → `degraded (unhealthy)`
2. **running + exited** → `degraded (unhealthy)`
3. **dead/removing** → `degraded (unhealthy)`
4. **paused** → `paused`
5. **created/starting** → `starting`
6. **exited** → `exited (unhealthy)`

### **Excluded Containers**

When containers have `exclude_from_hc: true` flag or `restart: no`:

**Behavior**:
- Status is still calculated from container state
- `:excluded` suffix is appended to indicate monitoring disabled
- UI shows "(Monitoring Disabled)" badge
- Action buttons respect the actual container state

**Format**: `{actual-status}:excluded`
**Examples**: `running:unknown:excluded`, `degraded:excluded`, `exited:excluded`

**All-Excluded Scenario**:
When ALL containers are excluded from health checks:
- All three status update paths (PushServerUpdateJob, GetContainersStatus, ComplexStatusCheck) **MUST** calculate status from excluded containers
- Status is returned with `:excluded` suffix (e.g., `running:healthy:excluded`)
- **NEVER** skip status updates - always calculate from excluded containers
- This ensures consistent status regardless of which update mechanism runs
- Shared logic is in `app/Traits/CalculatesExcludedStatus.php`

### **Important Notes for Developers**

✅ **Container Status Aggregation Service**:

The container status aggregation logic is centralized in `App\Services\ContainerStatusAggregator`.

**Status Format Standard**:
- **Backend/Storage**: Colon format (`running:healthy`, `degraded:unhealthy`)
- **UI/Display**: Transform to human format (`Running (Healthy)`, `Degraded (Unhealthy)`)

1. **Using the ContainerStatusAggregator Service**:
   - Import `App\Services\ContainerStatusAggregator` in any class needing status aggregation
   - Two methods available:
     - `aggregateFromStrings(Collection $statusStrings, int $maxRestartCount = 0)` - For pre-formatted status strings
     - `aggregateFromContainers(Collection $containers, int $maxRestartCount = 0)` - For raw Docker container objects
   - Returns colon format: `running:healthy`, `degraded:unhealthy`, etc.
   - Automatically handles crash loop detection via `$maxRestartCount` parameter

2. **State Machine Priority** (handled by service):
   - Restarting → `degraded:unhealthy` (highest priority)
   - Crash loop (exited with restarts) → `degraded:unhealthy`
   - Mixed state (running + exited) → `degraded:unhealthy`
   - Running → `running:unhealthy` / `running:unknown` / `running:healthy`
   - Dead/Removing → `degraded:unhealthy`
   - Paused → `paused:unknown`
   - Starting/Created → `starting:unknown`
   - Exited → `exited:unhealthy` (lowest priority)

3. **Test both update paths**:
   - Run unit tests: `./vendor/bin/pest tests/Unit/ContainerStatusAggregatorTest.php`
   - Run integration tests: `./vendor/bin/pest tests/Unit/`
   - Test SSH updates (manual refresh)
   - Test Sentinel updates (wait 30 seconds)

4. **Handle excluded containers**:
   - All containers excluded (`exclude_from_hc: true`) - Use `CalculatesExcludedStatus` trait
   - Mixed excluded/non-excluded containers - Filter then use `ContainerStatusAggregator`
   - Containers with `restart: no` - Treated same as `exclude_from_hc: true`

5. **Use shared trait for excluded containers**:
   - Import `App\Traits\CalculatesExcludedStatus` in status calculation classes
   - Use `getExcludedContainersFromDockerCompose()` to parse exclusions
   - Use `calculateExcludedStatus()` for full Docker inspect objects (ComplexStatusCheck)
   - Use `calculateExcludedStatusFromStrings()` for status strings (PushServerUpdateJob, GetContainersStatus)

### **Related Tests**

- **[tests/Unit/ContainerStatusAggregatorTest.php](mdc:tests/Unit/ContainerStatusAggregatorTest.php)**: Core state machine logic (42 comprehensive tests)
- **[tests/Unit/ContainerHealthStatusTest.php](mdc:tests/Unit/ContainerHealthStatusTest.php)**: Health status aggregation integration
- **[tests/Unit/PushServerUpdateJobStatusAggregationTest.php](mdc:tests/Unit/PushServerUpdateJobStatusAggregationTest.php)**: Sentinel update logic
- **[tests/Unit/ExcludeFromHealthCheckTest.php](mdc:tests/Unit/ExcludeFromHealthCheckTest.php)**: Excluded container handling

### **Common Bugs to Avoid**

✅ **Prevented by ContainerStatusAggregator Service**:
- ❌ **Old Bug**: Forgetting to track `$hasUnknown` flag → ✅ Now centralized in service
- ❌ **Old Bug**: Inconsistent priority across paths → ✅ Single source of truth
- ❌ **Old Bug**: Forgetting to update all 4 locations → ✅ Only one location to update

**Still Relevant**:

❌ **Bug**: Forgetting to filter excluded containers before aggregation
✅ **Fix**: Always use `CalculatesExcludedStatus` trait to filter before calling `ContainerStatusAggregator`

❌ **Bug**: Not passing `$maxRestartCount` for crash loop detection
✅ **Fix**: Calculate max restart count from containers and pass to `aggregateFromStrings()`/`aggregateFromContainers()`

❌ **Bug**: Not handling excluded containers with `:excluded` suffix
✅ **Fix**: Check for `:excluded` suffix in UI logic and button visibility
