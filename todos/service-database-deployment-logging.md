# Service & Database Deployment Logging - Implementation Plan

**Status:** Planning Complete
**Branch:** `andrasbacsai/service-db-deploy-logs`
**Target:** Add deployment history and logging for Services and Databases (similar to Applications)

---

## Current State Analysis

### Application Deployments (Working Model)

**Model:** `ApplicationDeploymentQueue`
- **Location:** `app/Models/ApplicationDeploymentQueue.php`
- **Table:** `application_deployment_queues`
- **Key Features:**
  - Stores deployment logs as JSON in `logs` column
  - Tracks status: queued, in_progress, finished, failed, cancelled-by-user
  - Stores metadata: deployment_uuid, commit, pull_request_id, server info
  - Has `addLogEntry()` method with sensitive data redaction
  - Relationships: belongsTo Application, server attribute accessor

**Job:** `ApplicationDeploymentJob`
- **Location:** `app/Jobs/ApplicationDeploymentJob.php`
- Handles entire deployment lifecycle
- Uses `addLogEntry()` to stream logs to database
- Updates status throughout deployment

**Helper Function:** `queue_application_deployment()`
- **Location:** `bootstrap/helpers/applications.php`
- Creates deployment queue record
- Dispatches job if ready
- Returns deployment status and UUID

**API Endpoints:**
- `GET /api/deployments` - List all running deployments
- `GET /api/deployments/{uuid}` - Get specific deployment
- `GET /api/deployments/applications/{uuid}` - List app deployment history
- Sensitive data filtering based on permissions

**Migration History:**
- `2023_05_24_083426_create_application_deployment_queues_table.php`
- `2023_06_23_114133_use_application_deployment_queues_as_activity.php` (added logs, current_process_id)
- `2025_01_16_110406_change_commit_message_to_text_in_application_deployment_queues.php`

---

### Services (Current State - No History)

**Model:** `Service`
- **Location:** `app/Models/Service.php`
- Represents Docker Compose services with multiple applications/databases

**Action:** `StartService`
- **Location:** `app/Actions/Service/StartService.php`
- Executes commands via `remote_process()`
- Returns Activity log (Spatie ActivityLog) - ephemeral, not stored
- Fires `ServiceStatusChanged` event on completion

**Current Behavior:**
```php
public function handle(Service $service, bool $pullLatestImages, bool $stopBeforeStart)
{
    $service->parse();
    // ... build commands array
    return remote_process($commands, $service->server,
        type_uuid: $service->uuid,
        callEventOnFinish: 'ServiceStatusChanged');
}
```

**Problem:** No persistent deployment history. Logs disappear after Activity TTL.

---

### Databases (Current State - No History)

**Models:** 9 Standalone Database Types
- `StandalonePostgresql`
- `StandaloneRedis`
- `StandaloneMongodb`
- `StandaloneMysql`
- `StandaloneMariadb`
- `StandaloneKeydb`
- `StandaloneDragonfly`
- `StandaloneClickhouse`
- (All in `app/Models/`)

**Actions:** Type-Specific Start Actions
- `StartPostgresql`, `StartRedis`, `StartMongodb`, etc.
- **Location:** `app/Actions/Database/Start*.php`
- Each builds docker-compose config, writes to disk, starts container
- Uses `remote_process()` with `DatabaseStatusChanged` event

**Dispatcher:** `StartDatabase`
- **Location:** `app/Actions/Database/StartDatabase.php`
- Routes to correct Start action based on database type

**Current Behavior:**
```php
// StartPostgresql example
public function handle(StandalonePostgresql $database)
{
    // ... build commands array
    return remote_process($this->commands, $database->destination->server,
        callEventOnFinish: 'DatabaseStatusChanged');
}
```

**Problem:** No persistent deployment history. Only real-time Activity logs.

---

## Architectural Decisions

### Why Separate Tables?

**Decision:** Create `service_deployment_queues` and `database_deployment_queues` (two separate tables)

**Reasoning:**
1. **Different Attributes:**
   - Services: multiple containers, docker-compose specific, pull_latest_images flag
   - Databases: type-specific configs, SSL settings, init scripts
   - Applications: git commits, pull requests, build cache

2. **Query Performance:**
   - Separate indexes per resource type
   - No polymorphic type checks in every query
   - Easier to optimize per-resource-type

3. **Type Safety:**
   - Explicit relationships and foreign keys (where possible)
   - IDE autocomplete and static analysis benefits

4. **Existing Pattern:**
   - Coolify already uses separate tables: `applications`, `services`, `standalone_*`
   - Consistent with codebase conventions

**Alternative Considered:** Single `resource_deployments` polymorphic table
- **Pros:** DRY, one model to maintain
- **Cons:** Harder to query efficiently, less type-safe, complex indexes
- **Decision:** Rejected in favor of clarity and performance

---

## Implementation Plan

### Phase 1: Database Schema (3 migrations)

#### Migration 1: Create `service_deployment_queues`

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_service_deployment_queues_table.php`

```php
Schema::create('service_deployment_queues', function (Blueprint $table) {
    $table->id();
    $table->foreignId('service_id')->constrained()->onDelete('cascade');
    $table->string('deployment_uuid')->unique();
    $table->string('status')->default('queued'); // queued, in_progress, finished, failed, cancelled-by-user
    $table->text('logs')->nullable(); // JSON array like ApplicationDeploymentQueue
    $table->string('current_process_id')->nullable(); // For tracking background processes
    $table->boolean('pull_latest_images')->default(false);
    $table->boolean('stop_before_start')->default(false);
    $table->boolean('is_api')->default(false); // Triggered via API vs UI
    $table->string('server_id'); // Denormalized for performance
    $table->string('server_name'); // Denormalized for display
    $table->string('service_name'); // Denormalized for display
    $table->string('deployment_url')->nullable(); // URL to view deployment
    $table->timestamps();

    // Indexes for common queries
    $table->index(['service_id', 'status']);
    $table->index('deployment_uuid');
    $table->index('created_at');
});
```

**Key Design Choices:**
- `logs` as TEXT (JSON) - Same pattern as ApplicationDeploymentQueue
- Denormalized server/service names for API responses without joins
- `deployment_url` for direct link generation
- Composite indexes for filtering by service + status

---

#### Migration 2: Create `database_deployment_queues`

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_database_deployment_queues_table.php`

```php
Schema::create('database_deployment_queues', function (Blueprint $table) {
    $table->id();
    $table->string('database_id'); // String to support polymorphic relationship
    $table->string('database_type'); // StandalonePostgresql, StandaloneRedis, etc.
    $table->string('deployment_uuid')->unique();
    $table->string('status')->default('queued');
    $table->text('logs')->nullable();
    $table->string('current_process_id')->nullable();
    $table->boolean('is_api')->default(false);
    $table->string('server_id');
    $table->string('server_name');
    $table->string('database_name');
    $table->string('deployment_url')->nullable();
    $table->timestamps();

    // Indexes for polymorphic relationship and queries
    $table->index(['database_id', 'database_type']);
    $table->index(['database_id', 'database_type', 'status']);
    $table->index('deployment_uuid');
    $table->index('created_at');
});
```

**Key Design Choices:**
- Polymorphic relationship using `database_id` + `database_type`
- Can't use foreignId constraint due to multiple target tables
- Composite index on polymorphic keys for efficient queries

---

#### Migration 3: Add Performance Indexes

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_deployment_queue_indexes.php`

```php
Schema::table('service_deployment_queues', function (Blueprint $table) {
    $table->index(['server_id', 'status', 'created_at'], 'service_deployments_server_status_time');
});

Schema::table('database_deployment_queues', function (Blueprint $table) {
    $table->index(['server_id', 'status', 'created_at'], 'database_deployments_server_status_time');
});
```

**Purpose:** Optimize queries like "all in-progress deployments on this server, newest first"

---

### Phase 2: Eloquent Models (2 new models)

#### Model 1: ServiceDeploymentQueue

**File:** `app/Models/ServiceDeploymentQueue.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Service Deployment Queue model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'service_id' => ['type' => 'integer'],
        'deployment_uuid' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'pull_latest_images' => ['type' => 'boolean'],
        'stop_before_start' => ['type' => 'boolean'],
        'is_api' => ['type' => 'boolean'],
        'logs' => ['type' => 'string'],
        'current_process_id' => ['type' => 'string'],
        'server_id' => ['type' => 'string'],
        'server_name' => ['type' => 'string'],
        'service_name' => ['type' => 'string'],
        'deployment_url' => ['type' => 'string'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ],
)]
class ServiceDeploymentQueue extends Model
{
    protected $guarded = [];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function server(): Attribute
    {
        return Attribute::make(
            get: fn () => Server::find($this->server_id),
        );
    }

    public function setStatus(string $status)
    {
        $this->update(['status' => $status]);
    }

    public function getOutput($name)
    {
        if (!$this->logs) {
            return null;
        }
        return collect(json_decode($this->logs))->where('name', $name)->first()?->output ?? null;
    }

    private function redactSensitiveInfo($text)
    {
        $text = remove_iip($text); // Remove internal IPs

        $service = $this->service;
        if (!$service) {
            return $text;
        }

        // Redact environment variables marked as sensitive
        $lockedVars = collect([]);
        if ($service->environment_variables) {
            $lockedVars = $service->environment_variables
                ->where('is_shown_once', true)
                ->pluck('real_value', 'key')
                ->filter();
        }

        foreach ($lockedVars as $key => $value) {
            $escapedValue = preg_quote($value, '/');
            $text = preg_replace('/' . $escapedValue . '/', REDACTED, $text);
        }

        return $text;
    }

    public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false)
    {
        if ($type === 'error') {
            $type = 'stderr';
        }

        $message = str($message)->trim();
        if ($message->startsWith('╔')) {
            $message = "\n" . $message;
        }

        $newLogEntry = [
            'command' => null,
            'output' => $this->redactSensitiveInfo($message),
            'type' => $type,
            'timestamp' => Carbon::now('UTC'),
            'hidden' => $hidden,
            'batch' => 1,
        ];

        // Use transaction for atomicity
        DB::transaction(function () use ($newLogEntry) {
            $this->refresh();

            if ($this->logs) {
                $previousLogs = json_decode($this->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                $newLogEntry['order'] = count($previousLogs) + 1;
                $previousLogs[] = $newLogEntry;
                $this->logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR);
            } else {
                $this->logs = json_encode([$newLogEntry], flags: JSON_THROW_ON_ERROR);
            }

            $this->saveQuietly();
        });
    }
}
```

**Key Features:**
- Exact same log structure as ApplicationDeploymentQueue
- `addLogEntry()` with sensitive data redaction
- Atomic log appends using DB transactions
- OpenAPI schema for API documentation

---

#### Model 2: DatabaseDeploymentQueue

**File:** `app/Models/DatabaseDeploymentQueue.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Database Deployment Queue model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'database_id' => ['type' => 'string'],
        'database_type' => ['type' => 'string'],
        'deployment_uuid' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'is_api' => ['type' => 'boolean'],
        'logs' => ['type' => 'string'],
        'current_process_id' => ['type' => 'string'],
        'server_id' => ['type' => 'string'],
        'server_name' => ['type' => 'string'],
        'database_name' => ['type' => 'string'],
        'deployment_url' => ['type' => 'string'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ],
)]
class DatabaseDeploymentQueue extends Model
{
    protected $guarded = [];

    public function database()
    {
        return $this->morphTo('database', 'database_type', 'database_id');
    }

    public function server(): Attribute
    {
        return Attribute::make(
            get: fn () => Server::find($this->server_id),
        );
    }

    public function setStatus(string $status)
    {
        $this->update(['status' => $status]);
    }

    public function getOutput($name)
    {
        if (!$this->logs) {
            return null;
        }
        return collect(json_decode($this->logs))->where('name', $name)->first()?->output ?? null;
    }

    private function redactSensitiveInfo($text)
    {
        $text = remove_iip($text);

        $database = $this->database;
        if (!$database) {
            return $text;
        }

        // Redact database-specific credentials
        $sensitivePatterns = collect([]);

        // Common database credential patterns
        if (method_exists($database, 'getConnectionString')) {
            $sensitivePatterns->push($database->getConnectionString());
        }

        // Postgres/MySQL passwords
        $passwordFields = ['postgres_password', 'mysql_password', 'mariadb_password', 'mongo_password'];
        foreach ($passwordFields as $field) {
            if (isset($database->$field)) {
                $sensitivePatterns->push($database->$field);
            }
        }

        // Redact environment variables
        if ($database->environment_variables) {
            $lockedVars = $database->environment_variables
                ->where('is_shown_once', true)
                ->pluck('real_value')
                ->filter();
            $sensitivePatterns = $sensitivePatterns->merge($lockedVars);
        }

        foreach ($sensitivePatterns as $value) {
            if (empty($value)) continue;
            $escapedValue = preg_quote($value, '/');
            $text = preg_replace('/' . $escapedValue . '/', REDACTED, $text);
        }

        return $text;
    }

    public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false)
    {
        if ($type === 'error') {
            $type = 'stderr';
        }

        $message = str($message)->trim();
        if ($message->startsWith('╔')) {
            $message = "\n" . $message;
        }

        $newLogEntry = [
            'command' => null,
            'output' => $this->redactSensitiveInfo($message),
            'type' => $type,
            'timestamp' => Carbon::now('UTC'),
            'hidden' => $hidden,
            'batch' => 1,
        ];

        DB::transaction(function () use ($newLogEntry) {
            $this->refresh();

            if ($this->logs) {
                $previousLogs = json_decode($this->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                $newLogEntry['order'] = count($previousLogs) + 1;
                $previousLogs[] = $newLogEntry;
                $this->logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR);
            } else {
                $this->logs = json_encode([$newLogEntry], flags: JSON_THROW_ON_ERROR);
            }

            $this->saveQuietly();
        });
    }
}
```

**Key Differences from ServiceDeploymentQueue:**
- Polymorphic `database()` relationship
- More extensive sensitive data redaction (database passwords, connection strings)
- Handles all 9 database types

---

### Phase 3: Enums (2 new enums)

#### Enum 1: ServiceDeploymentStatus

**File:** `app/Enums/ServiceDeploymentStatus.php`

```php
<?php

namespace App\Enums;

enum ServiceDeploymentStatus: string
{
    case QUEUED = 'queued';
    case IN_PROGRESS = 'in_progress';
    case FINISHED = 'finished';
    case FAILED = 'failed';
    case CANCELLED_BY_USER = 'cancelled-by-user';
}
```

#### Enum 2: DatabaseDeploymentStatus

**File:** `app/Enums/DatabaseDeploymentStatus.php`

```php
<?php

namespace App\Enums;

enum DatabaseDeploymentStatus: string
{
    case QUEUED = 'queued';
    case IN_PROGRESS = 'in_progress';
    case FINISHED = 'finished';
    case FAILED = 'failed';
    case CANCELLED_BY_USER = 'cancelled-by-user';
}
```

**Note:** Identical to ApplicationDeploymentStatus for consistency

---

### Phase 4: Helper Functions (2 new functions)

#### Helper 1: queue_service_deployment()

**File:** `bootstrap/helpers/services.php` (add to existing file)

```php
use App\Models\ServiceDeploymentQueue;
use App\Enums\ServiceDeploymentStatus;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

function queue_service_deployment(
    Service $service,
    string $deployment_uuid,
    bool $pullLatestImages = false,
    bool $stopBeforeStart = false,
    bool $is_api = false
): array {
    $service_id = $service->id;
    $server = $service->destination->server;
    $server_id = $server->id;
    $server_name = $server->name;

    // Generate deployment URL
    $deployment_link = Url::fromString($service->link() . "/deployment/{$deployment_uuid}");
    $deployment_url = $deployment_link->getPath();

    // Create deployment record
    $deployment = ServiceDeploymentQueue::create([
        'service_id' => $service_id,
        'service_name' => $service->name,
        'server_id' => $server_id,
        'server_name' => $server_name,
        'deployment_uuid' => $deployment_uuid,
        'deployment_url' => $deployment_url,
        'pull_latest_images' => $pullLatestImages,
        'stop_before_start' => $stopBeforeStart,
        'is_api' => $is_api,
        'status' => ServiceDeploymentStatus::IN_PROGRESS->value,
    ]);

    return [
        'status' => 'started',
        'message' => 'Service deployment started.',
        'deployment_uuid' => $deployment_uuid,
        'deployment' => $deployment,
    ];
}
```

**Purpose:** Create deployment queue record when service starts. Returns deployment object for passing to actions.

---

#### Helper 2: queue_database_deployment()

**File:** `bootstrap/helpers/databases.php` (add to existing file)

```php
use App\Models\DatabaseDeploymentQueue;
use App\Enums\DatabaseDeploymentStatus;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

function queue_database_deployment(
    StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database,
    string $deployment_uuid,
    bool $is_api = false
): array {
    $database_id = $database->id;
    $database_type = $database->getMorphClass();
    $server = $database->destination->server;
    $server_id = $server->id;
    $server_name = $server->name;

    // Generate deployment URL
    $deployment_link = Url::fromString($database->link() . "/deployment/{$deployment_uuid}");
    $deployment_url = $deployment_link->getPath();

    // Create deployment record
    $deployment = DatabaseDeploymentQueue::create([
        'database_id' => $database_id,
        'database_type' => $database_type,
        'database_name' => $database->name,
        'server_id' => $server_id,
        'server_name' => $server_name,
        'deployment_uuid' => $deployment_uuid,
        'deployment_url' => $deployment_url,
        'is_api' => $is_api,
        'status' => DatabaseDeploymentStatus::IN_PROGRESS->value,
    ]);

    return [
        'status' => 'started',
        'message' => 'Database deployment started.',
        'deployment_uuid' => $deployment_uuid,
        'deployment' => $deployment,
    ];
}
```

---

### Phase 5: Refactor Actions (11 files to update)

#### Action 1: StartService (CRITICAL)

**File:** `app/Actions/Service/StartService.php`

**Before:**
```php
public function handle(Service $service, bool $pullLatestImages = false, bool $stopBeforeStart = false)
{
    $service->parse();
    // ... build commands
    return remote_process($commands, $service->server, type_uuid: $service->uuid, callEventOnFinish: 'ServiceStatusChanged');
}
```

**After:**
```php
use App\Models\ServiceDeploymentQueue;
use Visus\Cuid2\Cuid2;

public function handle(Service $service, bool $pullLatestImages = false, bool $stopBeforeStart = false)
{
    // Create deployment queue record
    $deployment_uuid = (string) new Cuid2();
    $result = queue_service_deployment(
        service: $service,
        deployment_uuid: $deployment_uuid,
        pullLatestImages: $pullLatestImages,
        stopBeforeStart: $stopBeforeStart,
        is_api: false
    );
    $deployment = $result['deployment'];

    // Existing logic
    $service->parse();
    if ($stopBeforeStart) {
        StopService::run(service: $service, dockerCleanup: false);
    }
    $service->saveComposeConfigs();
    $service->isConfigurationChanged(save: true);

    $commands[] = 'cd ' . $service->workdir();
    $commands[] = "echo 'Saved configuration files to {$service->workdir()}.'";
    // ... rest of command building

    // Pass deployment to remote_process for log streaming
    return remote_process(
        $commands,
        $service->server,
        type_uuid: $service->uuid,
        model: $deployment, // NEW - link to deployment queue
        callEventOnFinish: 'ServiceStatusChanged'
    );
}
```

**Key Changes:**
1. Generate deployment UUID at start
2. Call `queue_service_deployment()` helper
3. Pass `$deployment` as `model` parameter to `remote_process()`
4. Return value unchanged (Activity object)

---

#### Actions 2-10: Database Start Actions (9 files)

**Files to Update:**
- `app/Actions/Database/StartPostgresql.php`
- `app/Actions/Database/StartRedis.php`
- `app/Actions/Database/StartMongodb.php`
- `app/Actions/Database/StartMysql.php`
- `app/Actions/Database/StartMariadb.php`
- `app/Actions/Database/StartKeydb.php`
- `app/Actions/Database/StartDragonfly.php`
- `app/Actions/Database/StartClickhouse.php`

**Pattern (using StartPostgresql as example):**

**Before:**
```php
public function handle(StandalonePostgresql $database)
{
    $this->database = $database;
    // ... build docker-compose and commands
    return remote_process($this->commands, $database->destination->server, callEventOnFinish: 'DatabaseStatusChanged');
}
```

**After:**
```php
use App\Models\DatabaseDeploymentQueue;
use Visus\Cuid2\Cuid2;

public function handle(StandalonePostgresql $database)
{
    $this->database = $database;

    // Create deployment queue record
    $deployment_uuid = (string) new Cuid2();
    $result = queue_database_deployment(
        database: $database,
        deployment_uuid: $deployment_uuid,
        is_api: false
    );
    $deployment = $result['deployment'];

    // Existing logic (unchanged)
    $container_name = $this->database->uuid;
    $this->configuration_dir = database_configuration_dir() . '/' . $container_name;
    // ... rest of setup

    // Pass deployment to remote_process
    return remote_process(
        $this->commands,
        $database->destination->server,
        model: $deployment, // NEW
        callEventOnFinish: 'DatabaseStatusChanged'
    );
}
```

**Apply Same Pattern to All 9 Database Start Actions**

---

#### Action 11: StartDatabase (Dispatcher)

**File:** `app/Actions/Database/StartDatabase.php`

**Before:**
```php
public function handle(/* all database types */)
{
    switch ($database->getMorphClass()) {
        case \App\Models\StandalonePostgresql::class:
            $activity = StartPostgresql::run($database);
            break;
        // ... other cases
    }
    return $activity;
}
```

**After:** No changes needed - already returns Activity from Start* actions

---

### Phase 6: Update Remote Process Handler (CRITICAL)

**File:** `app/Actions/CoolifyTask/PrepareCoolifyTask.php`

**Current Behavior:**
- Accepts `$model` parameter (currently only used for ApplicationDeploymentQueue)
- Streams logs to Activity (Spatie ActivityLog)
- Calls event on finish

**Required Changes:**
1. Check if `$model` is `ServiceDeploymentQueue` or `DatabaseDeploymentQueue`
2. Call `addLogEntry()` on deployment model alongside Activity logs
3. Update deployment status on completion/failure

**Pseudocode for Changes:**
```php
// In log streaming section
if ($model instanceof ApplicationDeploymentQueue ||
    $model instanceof ServiceDeploymentQueue ||
    $model instanceof DatabaseDeploymentQueue) {
    $model->addLogEntry($logMessage, $logType);
}

// On completion
if ($model instanceof ServiceDeploymentQueue ||
    $model instanceof DatabaseDeploymentQueue) {
    if ($exitCode === 0) {
        $model->setStatus('finished');
    } else {
        $model->setStatus('failed');
    }
}
```

**Note:** Exact implementation depends on PrepareCoolifyTask structure. Need to review file in detail during implementation.

---

### Phase 7: API Endpoints (4 new endpoints + 2 updates)

**File:** `app/Http/Controllers/Api/DeployController.php`

#### Endpoint 1: List Service Deployments

```php
#[OA\Get(
    summary: 'List service deployments',
    description: 'List deployment history for a specific service',
    path: '/deployments/services/{uuid}',
    operationId: 'list-deployments-by-service-uuid',
    security: [['bearerAuth' => []]],
    tags: ['Deployments'],
    parameters: [
        new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Service UUID', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'skip', in: 'query', description: 'Number of records to skip', schema: new OA\Schema(type: 'integer', minimum: 0, default: 0)),
        new OA\Parameter(name: 'take', in: 'query', description: 'Number of records to take', schema: new OA\Schema(type: 'integer', minimum: 1, default: 10)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of service deployments'),
        new OA\Response(response: 401, ref: '#/components/responses/401'),
        new OA\Response(response: 404, ref: '#/components/responses/404'),
    ]
)]
public function get_service_deployments(Request $request)
{
    $request->validate([
        'skip' => ['nullable', 'integer', 'min:0'],
        'take' => ['nullable', 'integer', 'min:1'],
    ]);

    $service_uuid = $request->route('uuid', null);
    $skip = $request->get('skip', 0);
    $take = $request->get('take', 10);

    $teamId = getTeamIdFromToken();
    if (is_null($teamId)) {
        return invalidTokenResponse();
    }

    $service = Service::where('uuid', $service_uuid)
        ->whereHas('environment.project.team', function($query) use ($teamId) {
            $query->where('id', $teamId);
        })
        ->first();

    if (is_null($service)) {
        return response()->json(['message' => 'Service not found'], 404);
    }

    $this->authorize('view', $service);

    $deployments = $service->deployments($skip, $take);

    return response()->json(serializeApiResponse($deployments));
}
```

#### Endpoint 2: Get Service Deployment by UUID

```php
#[OA\Get(
    summary: 'Get service deployment',
    description: 'Get a specific service deployment by deployment UUID',
    path: '/deployments/services/deployment/{uuid}',
    operationId: 'get-service-deployment-by-uuid',
    security: [['bearerAuth' => []]],
    tags: ['Deployments'],
    parameters: [
        new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Deployment UUID', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Service deployment details'),
        new OA\Response(response: 401, ref: '#/components/responses/401'),
        new OA\Response(response: 404, ref: '#/components/responses/404'),
    ]
)]
public function service_deployment_by_uuid(Request $request)
{
    $teamId = getTeamIdFromToken();
    if (is_null($teamId)) {
        return invalidTokenResponse();
    }

    $uuid = $request->route('uuid');
    if (!$uuid) {
        return response()->json(['message' => 'UUID is required.'], 400);
    }

    $deployment = ServiceDeploymentQueue::where('deployment_uuid', $uuid)->first();
    if (!$deployment) {
        return response()->json(['message' => 'Deployment not found.'], 404);
    }

    // Authorization check via service
    $service = $deployment->service;
    if (!$service) {
        return response()->json(['message' => 'Service not found.'], 404);
    }

    $this->authorize('view', $service);

    return response()->json($this->removeSensitiveData($deployment));
}
```

#### Endpoint 3: List Database Deployments

```php
#[OA\Get(
    summary: 'List database deployments',
    description: 'List deployment history for a specific database',
    path: '/deployments/databases/{uuid}',
    operationId: 'list-deployments-by-database-uuid',
    security: [['bearerAuth' => []]],
    tags: ['Deployments'],
    parameters: [
        new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Database UUID', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'skip', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 0, default: 0)),
        new OA\Parameter(name: 'take', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 10)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of database deployments'),
        new OA\Response(response: 401, ref: '#/components/responses/401'),
        new OA\Response(response: 404, ref: '#/components/responses/404'),
    ]
)]
public function get_database_deployments(Request $request)
{
    $request->validate([
        'skip' => ['nullable', 'integer', 'min:0'],
        'take' => ['nullable', 'integer', 'min:1'],
    ]);

    $database_uuid = $request->route('uuid', null);
    $skip = $request->get('skip', 0);
    $take = $request->get('take', 10);

    $teamId = getTeamIdFromToken();
    if (is_null($teamId)) {
        return invalidTokenResponse();
    }

    // Find database across all types
    $database = getResourceByUuid($database_uuid, $teamId);

    if (!$database || !method_exists($database, 'deployments')) {
        return response()->json(['message' => 'Database not found'], 404);
    }

    $this->authorize('view', $database);

    $deployments = $database->deployments($skip, $take);

    return response()->json(serializeApiResponse($deployments));
}
```

#### Endpoint 4: Get Database Deployment by UUID

```php
#[OA\Get(
    summary: 'Get database deployment',
    description: 'Get a specific database deployment by deployment UUID',
    path: '/deployments/databases/deployment/{uuid}',
    operationId: 'get-database-deployment-by-uuid',
    security: [['bearerAuth' => []]],
    tags: ['Deployments'],
    parameters: [
        new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Deployment UUID', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Database deployment details'),
        new OA\Response(response: 401, ref: '#/components/responses/401'),
        new OA\Response(response: 404, ref: '#/components/responses/404'),
    ]
)]
public function database_deployment_by_uuid(Request $request)
{
    $teamId = getTeamIdFromToken();
    if (is_null($teamId)) {
        return invalidTokenResponse();
    }

    $uuid = $request->route('uuid');
    if (!$uuid) {
        return response()->json(['message' => 'UUID is required.'], 400);
    }

    $deployment = DatabaseDeploymentQueue::where('deployment_uuid', $uuid)->first();
    if (!$deployment) {
        return response()->json(['message' => 'Deployment not found.'], 404);
    }

    // Authorization check via database
    $database = $deployment->database;
    if (!$database) {
        return response()->json(['message' => 'Database not found.'], 404);
    }

    $this->authorize('view', $database);

    return response()->json($this->removeSensitiveData($deployment));
}
```

#### Update: removeSensitiveData() method

```php
private function removeSensitiveData($deployment)
{
    if (request()->attributes->get('can_read_sensitive', false) === false) {
        $deployment->makeHidden(['logs']);
    }
    return serializeApiResponse($deployment);
}
```

**Note:** Already works for ServiceDeploymentQueue and DatabaseDeploymentQueue due to duck typing

#### Update: deploy_resource() method

**Before:**
```php
case Service::class:
    StartService::run($resource);
    $message = "Service {$resource->name} started. It could take a while, be patient.";
    break;

default: // Database
    StartDatabase::dispatch($resource);
    $message = "Database {$resource->name} started.";
    break;
```

**After:**
```php
case Service::class:
    $this->authorize('deploy', $resource);
    $deployment_uuid = new Cuid2;
    // StartService now handles deployment queue creation internally
    StartService::run($resource);
    $message = "Service {$resource->name} deployment started.";
    break;

default: // Database
    $this->authorize('manage', $resource);
    $deployment_uuid = new Cuid2;
    // Start actions now handle deployment queue creation internally
    StartDatabase::dispatch($resource);
    $message = "Database {$resource->name} deployment started.";
    break;
```

**Note:** deployment_uuid is now created inside actions, so API just returns message. If we want to return UUID to API, actions need to return deployment object.

---

### Phase 8: Model Relationships (2 model updates)

#### Update 1: Service Model

**File:** `app/Models/Service.php`

**Add Method:**
```php
/**
 * Get deployment history for this service
 */
public function deployments(int $skip = 0, int $take = 10)
{
    return ServiceDeploymentQueue::where('service_id', $this->id)
        ->orderBy('created_at', 'desc')
        ->skip($skip)
        ->take($take)
        ->get();
}

/**
 * Get latest deployment
 */
public function latestDeployment()
{
    return ServiceDeploymentQueue::where('service_id', $this->id)
        ->orderBy('created_at', 'desc')
        ->first();
}
```

---

#### Update 2: All Standalone Database Models (9 files)

**Files:**
- `app/Models/StandalonePostgresql.php`
- `app/Models/StandaloneRedis.php`
- `app/Models/StandaloneMongodb.php`
- `app/Models/StandaloneMysql.php`
- `app/Models/StandaloneMariadb.php`
- `app/Models/StandaloneKeydb.php`
- `app/Models/StandaloneDragonfly.php`
- `app/Models/StandaloneClickhouse.php`

**Add Methods to Each:**
```php
/**
 * Get deployment history for this database
 */
public function deployments(int $skip = 0, int $take = 10)
{
    return DatabaseDeploymentQueue::where('database_id', $this->id)
        ->where('database_type', $this->getMorphClass())
        ->orderBy('created_at', 'desc')
        ->skip($skip)
        ->take($take)
        ->get();
}

/**
 * Get latest deployment
 */
public function latestDeployment()
{
    return DatabaseDeploymentQueue::where('database_id', $this->id)
        ->where('database_type', $this->getMorphClass())
        ->orderBy('created_at', 'desc')
        ->first();
}
```

---

### Phase 9: Routes (4 new routes)

**File:** `routes/api.php`

**Add Routes:**
```php
Route::middleware(['auth:sanctum'])->group(function () {
    // Existing routes...

    // Service deployment routes
    Route::get('/deployments/services/{uuid}', [DeployController::class, 'get_service_deployments'])
        ->name('deployments.services.list');
    Route::get('/deployments/services/deployment/{uuid}', [DeployController::class, 'service_deployment_by_uuid'])
        ->name('deployments.services.show');

    // Database deployment routes
    Route::get('/deployments/databases/{uuid}', [DeployController::class, 'get_database_deployments'])
        ->name('deployments.databases.list');
    Route::get('/deployments/databases/deployment/{uuid}', [DeployController::class, 'database_deployment_by_uuid'])
        ->name('deployments.databases.show');
});
```

---

### Phase 10: Policies & Authorization (Optional - If needed)

**Service Policy:** `app/Policies/ServicePolicy.php`
- May need to add `viewDeployment` and `viewDeployments` methods if they don't exist
- Check existing `view` gate - it should cover deployment viewing

**Database Policies:**
- Each StandaloneDatabase type may have its own policy
- Verify `view` gate exists and covers deployment history access

**Action Required:** Review existing policies during implementation. May not need changes if `view` gate is sufficient.

---

## Testing Strategy

### Unit Tests (Run outside Docker: `./vendor/bin/pest tests/Unit`)

#### Test 1: ServiceDeploymentQueue Unit Test

**File:** `tests/Unit/Models/ServiceDeploymentQueueTest.php`

```php
<?php

use App\Models\Service;
use App\Models\ServiceDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can add log entries with proper structure', function () {
    $deployment = ServiceDeploymentQueue::factory()->create([
        'logs' => null,
    ]);

    $deployment->addLogEntry('Test message', 'stdout', false);

    expect($deployment->fresh()->logs)->not->toBeNull();

    $logs = json_decode($deployment->fresh()->logs, true);
    expect($logs)->toHaveCount(1);
    expect($logs[0])->toHaveKeys(['command', 'output', 'type', 'timestamp', 'hidden', 'batch', 'order']);
    expect($logs[0]['output'])->toBe('Test message');
    expect($logs[0]['type'])->toBe('stdout');
});

it('redacts sensitive environment variables in logs', function () {
    $service = Mockery::mock(Service::class);
    $envVar = new \StdClass();
    $envVar->is_shown_once = true;
    $envVar->key = 'SECRET_KEY';
    $envVar->real_value = 'super-secret-value';

    $service->shouldReceive('getAttribute')
        ->with('environment_variables')
        ->andReturn(collect([$envVar]));

    $deployment = ServiceDeploymentQueue::factory()->create();
    $deployment->setRelation('service', $service);

    $deployment->addLogEntry('Deploying with super-secret-value in logs', 'stdout');

    $logs = json_decode($deployment->fresh()->logs, true);
    expect($logs[0]['output'])->toContain(REDACTED);
    expect($logs[0]['output'])->not->toContain('super-secret-value');
});

it('sets status correctly', function () {
    $deployment = ServiceDeploymentQueue::factory()->create(['status' => 'queued']);

    $deployment->setStatus('in_progress');
    expect($deployment->fresh()->status)->toBe('in_progress');

    $deployment->setStatus('finished');
    expect($deployment->fresh()->status)->toBe('finished');
});
```

#### Test 2: DatabaseDeploymentQueue Unit Test

**File:** `tests/Unit/Models/DatabaseDeploymentQueueTest.php`

```php
<?php

use App\Models\DatabaseDeploymentQueue;
use App\Models\StandalonePostgresql;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can add log entries with proper structure', function () {
    $deployment = DatabaseDeploymentQueue::factory()->create([
        'logs' => null,
    ]);

    $deployment->addLogEntry('Starting database', 'stdout', false);

    $logs = json_decode($deployment->fresh()->logs, true);
    expect($logs)->toHaveCount(1);
    expect($logs[0]['output'])->toBe('Starting database');
});

it('redacts database credentials in logs', function () {
    $database = Mockery::mock(StandalonePostgresql::class);
    $database->shouldReceive('getAttribute')
        ->with('postgres_password')
        ->andReturn('db-password-123');
    $database->shouldReceive('getAttribute')
        ->with('environment_variables')
        ->andReturn(collect([]));
    $database->shouldReceive('getMorphClass')
        ->andReturn(StandalonePostgresql::class);

    $deployment = DatabaseDeploymentQueue::factory()->create([
        'database_type' => StandalonePostgresql::class,
    ]);
    $deployment->setRelation('database', $database);

    $deployment->addLogEntry('Connecting with password db-password-123', 'stdout');

    $logs = json_decode($deployment->fresh()->logs, true);
    expect($logs[0]['output'])->toContain(REDACTED);
    expect($logs[0]['output'])->not->toContain('db-password-123');
});
```

---

### Feature Tests (Run inside Docker: `docker exec coolify php artisan test`)

#### Test 3: Service Deployment Integration Test

**File:** `tests/Feature/ServiceDeploymentTest.php`

```php
<?php

use App\Models\Service;
use App\Models\ServiceDeploymentQueue;
use App\Actions\Service\StartService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates deployment queue when starting service', function () {
    $service = Service::factory()->create();

    // Mock remote_process to prevent actual SSH
    // (Implementation depends on existing test patterns)

    StartService::run($service);

    $deployment = ServiceDeploymentQueue::where('service_id', $service->id)->first();
    expect($deployment)->not->toBeNull();
    expect($deployment->service_name)->toBe($service->name);
    expect($deployment->status)->toBe('in_progress');
});

it('tracks multiple deployments for same service', function () {
    $service = Service::factory()->create();

    StartService::run($service);
    StartService::run($service);

    $deployments = ServiceDeploymentQueue::where('service_id', $service->id)->get();
    expect($deployments)->toHaveCount(2);
});
```

#### Test 4: Database Deployment Integration Test

**File:** `tests/Feature/DatabaseDeploymentTest.php`

```php
<?php

use App\Models\StandalonePostgresql;
use App\Models\DatabaseDeploymentQueue;
use App\Actions\Database\StartPostgresql;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates deployment queue when starting postgres database', function () {
    $database = StandalonePostgresql::factory()->create();

    // Mock remote_process

    StartPostgresql::run($database);

    $deployment = DatabaseDeploymentQueue::where('database_id', $database->id)
        ->where('database_type', StandalonePostgresql::class)
        ->first();

    expect($deployment)->not->toBeNull();
    expect($deployment->database_name)->toBe($database->name);
});

// Repeat for other database types...
```

#### Test 5: API Endpoint Tests

**File:** `tests/Feature/Api/DeploymentApiTest.php`

```php
<?php

use App\Models\Service;
use App\Models\ServiceDeploymentQueue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists service deployments via API', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create([
        'environment_id' => /* setup team/project/env */
    ]);

    ServiceDeploymentQueue::factory()->count(3)->create([
        'service_id' => $service->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/deployments/services/{$service->uuid}");

    $response->assertSuccessful();
    $response->assertJsonCount(3);
});

it('requires authentication for service deployments', function () {
    $service = Service::factory()->create();

    $response = $this->getJson("/api/deployments/services/{$service->uuid}");

    $response->assertUnauthorized();
});

// Repeat for database endpoints...
```

---

## Rollout Plan

### Phase Order (Safest to Riskiest)

| Phase | Risk | Can Break Production? | Rollback Strategy |
|-------|------|----------------------|-------------------|
| 1. Schema | Low | No (new tables) | Drop tables |
| 2. Models | Low | No (unused code) | Remove files |
| 3. Enums | Low | No (unused code) | Remove files |
| 4. Helpers | Low | No (unused code) | Remove functions |
| 5. Actions | **HIGH** | **YES** | Revert to old actions |
| 6. Remote Process | **CRITICAL** | **YES** | Revert changes |
| 7. API | Medium | No (new endpoints) | Remove routes |
| 8. Relationships | Low | No (new methods) | Remove methods |
| 9. UI | Low | No (optional) | Remove components |
| 10. Policies | Low | Maybe (if breaking existing) | Revert gates |

### Recommended Rollout Strategy

**Week 1: Foundation (No Risk)**
- Complete Phases 1-4
- Write and run all unit tests
- Verify migrations work in dev/staging

**Week 2: Critical Changes (High Risk)**
- Complete Phase 5 (Actions) for **Services only**
- Complete Phase 6 (Remote Process handler) for Services
- Test extensively in staging
- Monitor for errors

**Week 3: Database Support**
- Extend Phase 5 to all 9 database types
- Update Phase 6 for database support
- Test each database type individually

**Week 4: API & Polish**
- Complete Phases 7-10
- Feature tests
- API documentation
- User-facing features (if any)

### Testing Checkpoints

**After Phase 4:**
- ✅ Migrations apply cleanly
- ✅ Models instantiate without errors
- ✅ Unit tests pass

**After Phase 5 (Services):**
- ✅ Service start creates deployment queue
- ✅ Service logs stream to deployment queue
- ✅ Service deployments appear in database
- ✅ No disruption to existing service starts

**After Phase 5 (Databases):**
- ✅ Each database type creates deployment queue
- ✅ Database logs stream correctly
- ✅ No errors on database start

**After Phase 7:**
- ✅ API endpoints return correct data
- ✅ Authorization works correctly
- ✅ Sensitive data is redacted

---

## Known Risks & Mitigation

### Risk 1: Breaking Existing Deployments
**Probability:** Medium
**Impact:** Critical

**Mitigation:**
- Test exhaustively in staging before production
- Deploy during low-traffic window
- Have rollback plan ready (git revert + migration rollback)
- Monitor error logs closely after deploy

### Risk 2: Database Performance Impact
**Probability:** Low
**Impact:** Medium

**Details:** Each deployment now writes logs to DB multiple times (via `addLogEntry()`)

**Mitigation:**
- Use `saveQuietly()` to avoid triggering events
- JSON column is indexed for fast retrieval
- Logs are text (compressed well by Postgres)
- Add monitoring for slow queries

### Risk 3: Disk Space Growth
**Probability:** Medium (long-term)
**Impact:** Low

**Details:** Deployment logs accumulate over time

**Mitigation:**
- Implement log retention policy (delete deployments older than X days/months)
- Add background job to prune old deployment records
- Monitor disk usage trends

### Risk 4: Polymorphic Relationship Complexity
**Probability:** Low
**Impact:** Low

**Details:** DatabaseDeploymentQueue uses polymorphic relationship (9 database types)

**Mitigation:**
- Thorough testing of each database type
- Composite indexes on (database_id, database_type)
- Clear documentation of relationship structure

### Risk 5: Remote Process Integration
**Probability:** High
**Impact:** Critical

**Details:** `PrepareCoolifyTask` is core to all deployments. Changes here affect everything.

**Mitigation:**
- Review `PrepareCoolifyTask` code in detail before changes
- Add type checks (`instanceof`) to avoid breaking existing logic
- Extensive testing of application deployments after changes
- Keep changes minimal and focused

---

## Migration Strategy for Existing Data

**Q: What about existing services/databases that have been deployed before?**

**A:** No migration needed. This is a **new feature**, not a data migration.

- Services/databases deployed before this change won't have history
- New deployments (after feature is live) will be tracked
- This is acceptable - deployment history starts "now"

**Alternative (if history is critical):**
- Could create fake deployment records for currently running resources
- Not recommended - logs don't exist, would be misleading

---

## Performance Considerations

### Database Writes During Deployment

**Current:** ~1 write per deployment (Activity log, TTL-based)

**New:** ~1 write per deployment + N writes for log entries
- Application deployments: ~50-200 log entries
- Service deployments: ~10-30 log entries
- Database deployments: ~5-15 log entries

**Impact:** Minimal
- Writes are async (queued)
- Postgres handles small JSON updates efficiently
- `saveQuietly()` skips event dispatching overhead

### Query Performance

**Critical Queries:**
- "Get deployment history for service/database" - indexed on (resource_id, status, created_at)
- "Get deployment by UUID" - unique index on deployment_uuid
- "Get all in-progress deployments" - composite index on (server_id, status, created_at)

**Expected Performance:**
- < 10ms for single deployment lookup
- < 50ms for paginated history (10 records)
- < 100ms for server-wide deployment status

---

## Storage Estimates

**Per Deployment:**
- Metadata: ~500 bytes
- Logs (avg): ~50KB (application), ~10KB (service), ~5KB (database)

**1000 deployments/day:**
- Services: ~10MB/day = ~300MB/month
- Databases: ~5MB/day = ~150MB/month
- Total: ~450MB/month (highly compressible)

**Retention Policy Recommendation:**
- Keep all deployments for 30 days
- Keep successful deployments for 90 days
- Keep failed deployments for 180 days (for debugging)

---

## Alternative Approaches Considered

### Option 1: Unified Resource Deployments Table

**Schema:**
```sql
CREATE TABLE resource_deployments (
    id BIGINT PRIMARY KEY,
    deployable_id INT,
    deployable_type VARCHAR(255), -- App\Models\Service, App\Models\StandalonePostgresql, etc.
    deployment_uuid VARCHAR(255) UNIQUE,
    -- ... rest of fields
    INDEX(deployable_id, deployable_type)
);
```

**Pros:**
- Single model to maintain
- DRY (Don't Repeat Yourself)
- Easier to query "all deployments across all resources"

**Cons:**
- Polymorphic queries are slower
- No foreign key constraints
- Different resources have different deployment attributes
- Harder to optimize indexes per resource type
- More complex to reason about

**Decision:** Rejected - Separate tables provide better type safety and performance

---

### Option 2: Reuse Activity Log (Spatie)

**Approach:** Don't create deployment queue tables. Use existing Activity log with longer TTL.

**Pros:**
- Zero new code
- Activity log already stores logs

**Cons:**
- Activity log is ephemeral (not designed for permanent history)
- No structured deployment metadata (status, UUIDs, etc.)
- Would need to change Activity TTL globally (affects all activities)
- Mixing concerns (Activity = audit log, Deployment = business logic)

**Decision:** Rejected - Activity log and deployment history serve different purposes

---

### Option 3: External Logging Service

**Approach:** Stream logs to external service (S3, CloudWatch, etc.)

**Pros:**
- Offload storage from main database
- Better for very large log volumes

**Cons:**
- Additional infrastructure complexity
- Requires external dependencies
- Harder to query deployment history
- Not consistent with application deployment pattern

**Decision:** Rejected - Keep it simple, follow existing patterns

---

## Future Enhancements (Out of Scope)

### 1. Deployment Queue System
- Like application deployments, queue service/database starts
- Respect server concurrent limits
- **Complexity:** High
- **Value:** Medium (services/databases deploy fast, queueing less critical)

### 2. UI for Deployment History
- Livewire components to view past deployments
- Similar to application deployment history page
- **Complexity:** Medium
- **Value:** High (nice-to-have, not critical for first release)

### 3. Deployment Comparison
- Diff between two deployments (config changes)
- **Complexity:** High
- **Value:** Low

### 4. Deployment Rollback
- Roll back service/database to previous deployment
- **Complexity:** Very High (databases especially risky)
- **Value:** Medium

### 5. Deployment Notifications
- Notify on service/database deployment success/failure
- **Complexity:** Low
- **Value:** Medium

---

## Success Criteria

### Minimum Viable Product (MVP)

✅ Service deployments create deployment queue records
✅ Database deployments (all 9 types) create deployment queue records
✅ Logs stream to deployment queue during deployment
✅ Deployment status updates (in_progress → finished/failed)
✅ API endpoints to retrieve deployment history
✅ Sensitive data redaction in logs
✅ No disruption to existing application deployments
✅ All unit and feature tests pass

### Nice-to-Have (Post-MVP)

⚪ UI components for viewing deployment history
⚪ Deployment notifications
⚪ Log retention policy job
⚪ Deployment statistics/analytics

---

## Questions to Resolve Before Implementation

1. **Should we queue service/database starts (like applications)?**
   - Current: Services/databases start immediately
   - With queue: Respect server concurrent limits, better for cloud instance
   - **Recommendation:** Start without queue, add later if needed

2. **Should API deploy endpoints return deployment_uuid for services/databases?**
   - Current: Application deploys return deployment_uuid
   - Proposed: Services/databases should too
   - **Recommendation:** Yes, for consistency. Requires actions to return deployment object.

3. **What's the log retention policy?**
   - **Recommendation:** 90 days for all, with background job to prune

4. **Do we need UI in first release?**
   - **Recommendation:** No, API is sufficient. Add UI iteratively.

5. **Should we implement deployment cancellation?**
   - Applications support cancellation
   - **Recommendation:** Not in MVP, add later if requested

---

## Implementation Checklist

### Pre-Implementation
- [ ] Review this plan with team
- [ ] Get approval on architectural decisions
- [ ] Resolve open questions
- [ ] Set up staging environment for testing

### Phase 1: Schema
- [ ] Create `create_service_deployment_queues_table` migration
- [ ] Create `create_database_deployment_queues_table` migration
- [ ] Create index optimization migration
- [ ] Test migrations in dev
- [ ] Run migrations in staging

### Phase 2: Models
- [ ] Create `ServiceDeploymentQueue` model
- [ ] Create `DatabaseDeploymentQueue` model
- [ ] Add `$fillable`, `$guarded` properties
- [ ] Implement `addLogEntry()`, `setStatus()`, `getOutput()` methods
- [ ] Implement `redactSensitiveInfo()` methods
- [ ] Add OpenAPI schemas

### Phase 3: Enums
- [ ] Create `ServiceDeploymentStatus` enum
- [ ] Create `DatabaseDeploymentStatus` enum

### Phase 4: Helpers
- [ ] Add `queue_service_deployment()` to `bootstrap/helpers/services.php`
- [ ] Add `queue_database_deployment()` to `bootstrap/helpers/databases.php`
- [ ] Test helpers in Tinker

### Phase 5: Actions
- [ ] Update `StartService` action
- [ ] Update `StartPostgresql` action
- [ ] Update `StartRedis` action
- [ ] Update `StartMongodb` action
- [ ] Update `StartMysql` action
- [ ] Update `StartMariadb` action
- [ ] Update `StartKeydb` action
- [ ] Update `StartDragonfly` action
- [ ] Update `StartClickhouse` action
- [ ] Test each action in staging

### Phase 6: Remote Process
- [ ] Review `PrepareCoolifyTask` code
- [ ] Add type checks for ServiceDeploymentQueue
- [ ] Add type checks for DatabaseDeploymentQueue
- [ ] Add `addLogEntry()` calls
- [ ] Add status update logic
- [ ] Test with application deployments (ensure no regression)
- [ ] Test with service deployments
- [ ] Test with database deployments

### Phase 7: API
- [ ] Add `get_service_deployments()` endpoint
- [ ] Add `service_deployment_by_uuid()` endpoint
- [ ] Add `get_database_deployments()` endpoint
- [ ] Add `database_deployment_by_uuid()` endpoint
- [ ] Update `deploy_resource()` to return deployment_uuid
- [ ] Update `removeSensitiveData()` if needed
- [ ] Add routes to `api.php`
- [ ] Test endpoints with Postman/curl

### Phase 8: Relationships
- [ ] Add `deployments()` method to `Service` model
- [ ] Add `latestDeployment()` method to `Service` model
- [ ] Add `deployments()` method to all 9 Standalone database models
- [ ] Add `latestDeployment()` method to all 9 Standalone database models

### Phase 9: Tests
- [ ] Write `ServiceDeploymentQueueTest` (unit)
- [ ] Write `DatabaseDeploymentQueueTest` (unit)
- [ ] Write `ServiceDeploymentTest` (feature)
- [ ] Write `DatabaseDeploymentTest` (feature)
- [ ] Write `DeploymentApiTest` (feature)
- [ ] Run all tests, ensure passing
- [ ] Run full test suite, ensure no regressions

### Phase 10: Documentation
- [ ] Update API documentation
- [ ] Update CLAUDE.md if needed
- [ ] Add code comments for complex sections

### Deployment
- [ ] Create PR with all changes
- [ ] Code review
- [ ] Test in staging (full regression suite)
- [ ] Deploy to production during low-traffic window
- [ ] Monitor error logs for 24 hours
- [ ] Verify deployments are being tracked

### Post-Deployment
- [ ] Monitor disk usage trends
- [ ] Monitor query performance
- [ ] Gather user feedback
- [ ] Plan UI implementation (if needed)
- [ ] Plan log retention job

---

## Contact & Support

**Implementation Lead:** [Your Name]
**Reviewer:** [Reviewer Name]
**Questions:** Reference this document or ask in #dev channel

---

**Last Updated:** 2025-10-30
**Status:** Planning Complete, Ready for Implementation
**Next Step:** Review plan with team, get approval, begin Phase 1
