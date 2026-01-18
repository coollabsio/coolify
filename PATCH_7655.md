# Fix for Issue #7655: Environment Variable Leakage

## Problem
All environment variables were being injected into every container in a Docker Compose service, regardless of which container they were defined for. This caused sensitive data (API keys, passwords, secrets) to leak across container boundaries.

## Root Cause
The `saveComposeConfigs()` method in `app/Models/Service.php` was collecting ALL environment variables from the Service model using `$this->environment_variables()->get()`, which includes:
- Service-level variables (intended to be shared)
- ServiceApplication-specific variables (should only be in that app container)
- ServiceDatabase-specific variables (should only be in that database container)

All these variables were being written to a shared `.env` file that Docker Compose loads for ALL containers.

## Solution
Modify the `saveComposeConfigs()` method to ONLY include Service-level environment variables in the shared `.env` file. Container-specific variables are already properly injected into their respective containers through the docker-compose.yml file during the parsing phase.

## Code Changes

In `app/Models/Service.php`, replace lines 1529-1531:

### BEFORE (lines 1529-1531):
```php
        $envs_from_coolify = $this->environment_variables()->get();
        $sorted = $envs_from_coolify->sortBy(function ($env) {
```

### AFTER:
```php
        // FIX for #7655: Only include Service-level environment variables in the shared .env file
        // Container-specific variables (ServiceApplication/ServiceDatabase) should NOT be here
        // as they are already injected into their respective containers via docker-compose.yml
        
        // Get IDs of all container-specific environment variables to exclude them
        $containerSpecificEnvIds = collect([]);
        
        // Collect env var IDs from all ServiceApplications
        foreach ($this->applications as $app) {
            $containerSpecificEnvIds = $containerSpecificEnvIds->merge(
                $app->environment_variables()->pluck('id')
            );
        }
        
        // Collect env var IDs from all ServiceDatabases
        foreach ($this->databases as $db) {
            $containerSpecificEnvIds = $containerSpecificEnvIds->merge(
                $db->environment_variables()->pluck('id')
            );
        }

        // Only get Service-level environment variables (exclude container-specific ones)
        $envs_from_coolify = $this->environment_variables()
            ->whereNotIn('id', $containerSpecificEnvIds->toArray())
            ->get();
            
        $sorted = $envs_from_coolify->sortBy(function ($env) {
```

## Testing
1. Create a service with multiple containers (e.g., app + database)
2. Add environment variables at different levels:
   - Service level: `SHARED_VAR=shared_value`
   - App container level: `APP_SECRET=app_secret_123`
   - Database container level: `DB_PASSWORD=db_pass_456`
3. Deploy the service
4. Verify:
   - All containers can see `SHARED_VAR`
   - Only the app container can see `APP_SECRET`
   - Only the database container can see `DB_PASSWORD`
   - The `.env` file in the service workdir only contains `SHARED_VAR` and `SERVICE_NAME_*` variables

## Security Impact
- **HIGH**: Prevents sensitive credentials from leaking to unintended containers
- **Scope**: Affects all Docker Compose services with multiple containers
- **Backward Compatibility**: Maintains existing functionality - Service-level variables still work as shared variables

## Related Files
- `app/Models/Service.php` - Main fix location
- `app/Models/ServiceApplication.php` - Has `environment_variables()` relationship
- `app/Models/ServiceDatabase.php` - Has `environment_variables()` relationship
