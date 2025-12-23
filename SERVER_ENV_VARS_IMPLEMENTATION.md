# Server-Level Environment Variables Implementation

## Overview
This implementation introduces server-level environment variables to Coolify, allowing applications deployed to different servers to receive server-specific environment variables and automatic server identity information.

## Files Created/Modified

### Database & Models
- `database/migrations/2025_12_23_120000_add_server_environment_variables.php` - Database migration
- `app/Models/ServerEnvironmentVariable.php` - New model for server environment variables
- `app/Models/Server.php` - Added environment_variables relationship

### Deployment Logic
- `app/Jobs/ApplicationDeploymentJob.php` - Modified to merge server-level env vars and add automatic server identity variables

### API & Routes
- `app/Http/Controllers/Api/ServersController.php` - Added CRUD endpoints for server environment variables
- `routes/api.php` - Added API routes for server environment variables
- `routes/web.php` - Added web route for environment variables page

### UI Components
- `app/Livewire/Server/EnvironmentVariables.php` - Livewire component for managing server environment variables
- `resources/views/livewire/server/environment-variables.blade.php` - Blade template for the UI
- `resources/views/server/environment-variables.blade.php` - Page layout
- `resources/views/components/server/sidebar.blade.php` - Added environment variables menu item

## Key Features

### 1. Server-Level Environment Variables
- Stored encrypted at rest (same as application env vars)
- Support for literal, multiline, build-time, and runtime variables
- CRUD operations via API and UI

### 2. Automatic Server Identity Variables
The following variables are automatically injected into all containers:
- `COOLIFY_SERVER_ID` - Server ID
- `COOLIFY_SERVER_NAME` - Server name
- `COOLIFY_SERVER_HOSTNAME` - Server IP/hostname
- `COOLIFY_SERVER_IP` - Server IP address

These are read-only and cannot be overridden by user-defined variables.

### 3. Environment Variable Precedence
During deployment, environment variables are merged in this order:
1. System environment variables
2. Server-level environment variables
3. Application-level environment variables (highest priority)

### 4. Multi-Server Support
Applications deployed to multiple servers now receive different environment variable sets based on the target server.

## API Endpoints

- `GET /api/v1/servers/{uuid}/environment-variables` - List server environment variables
- `POST /api/v1/servers/{uuid}/environment-variables` - Create server environment variable
- `PUT /api/v1/servers/{uuid}/environment-variables/{envUuid}` - Update server environment variable
- `DELETE /api/v1/servers/{uuid}/environment-variables/{envUuid}` - Delete server environment variable

## Testing & Validation

### 1. Database Migration
```bash
php artisan migrate
```

### 2. Test Server Environment Variables
1. Navigate to Server → Environment Variables in the UI
2. Add a test environment variable (e.g., `SERVER_TEST=test_value`)
3. Deploy an application to that server
4. Check the container environment: `docker exec <container> env | grep SERVER_TEST`

### 3. Test Automatic Server Identity Variables
1. Deploy any application to a server
2. Check the container environment:
```bash
docker exec <container> env | grep COOLIFY_SERVER
```
Expected output:
```
COOLIFY_SERVER_ID=1
COOLIFY_SERVER_NAME=my-server
COOLIFY_SERVER_HOSTNAME=192.168.1.100
COOLIFY_SERVER_IP=192.168.1.100
```

### 4. Test Multi-Server Deployment
1. Set up two servers with different server-level environment variables
2. Deploy the same application to both servers
3. Verify that each server's container receives different environment variables

### 5. Test Precedence
1. Add a server-level environment variable (e.g., `TEST_VAR=server_value`)
2. Add the same variable at application level (e.g., `TEST_VAR=app_value`)
3. Deploy the application
4. Verify the application value overrides the server value

## Backward Compatibility
- Existing applications without server environment variables work exactly as before
- No breaking changes to existing deployment flows
- All existing environment variable functionality preserved

## Security Considerations
- Server environment variables are encrypted at rest
- Only users with appropriate permissions can manage server environment variables
- Automatic server identity variables are read-only and cannot be overridden

## Performance Impact
- Minimal performance impact during deployment
- Server environment variables are loaded once per deployment
- No additional runtime overhead for running containers
