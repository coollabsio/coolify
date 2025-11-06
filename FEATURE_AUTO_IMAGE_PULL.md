# Automatic Image Pull and Restart Feature

## Overview

This feature adds automatic image update detection and restart capabilities to Coolify services, allowing services to stay up-to-date with the latest Docker images automatically.

## Features

### 1. **Manual Pull and Restart**
- Already existed: "Pull Latest Images & Restart" button in Advanced dropdown
- Now accessible via UI and API

### 2. **Check for Updates**
- Manual button to check for available image updates
- Tracks last check timestamp
- Available via UI and API

### 3. **Automatic Updates (NEW)**
- Enable/disable automatic image pull and restart
- Schedule options:
  - **Hourly**: Every hour
  - **Daily**: Every day at 2:00 AM
  - **Weekly**: Every Sunday at 2:00 AM
- Automatic restart when new images are detected

## Database Changes

### Migration: `2025_11_06_140000_add_auto_image_pull_to_services.php`

Added three new columns to `services` table:
- `auto_image_pull_enabled` (boolean): Enable/disable automatic updates
- `auto_image_pull_schedule` (string): Schedule frequency (hourly/daily/weekly)
- `last_image_pull_check` (timestamp): Last check timestamp

## API Endpoints

### Check for Image Updates
```bash
POST /api/v1/services/{uuid}/check-updates
```

**Response:**
```json
{
  "message": "Image update check completed.",
  "last_check": "2025-11-06T14:00:00Z"
}
```

### Configure Auto Pull
```bash
PATCH /api/v1/services/{uuid}/auto-pull
```

**Request Body:**
```json
{
  "enabled": true,
  "schedule": "daily"
}
```

**Response:**
```json
{
  "message": "Auto pull configuration updated.",
  "enabled": true,
  "schedule": "daily"
}
```

### Restart with Latest Images (Enhanced)
```bash
GET|POST /api/v1/services/{uuid}/restart?latest=true
```

## UI Components

### 1. Configuration Page
Added "Automatic Image Updates" section in service configuration page with:
- Toggle for enabling/disabling automatic updates
- Schedule dropdown (hourly/daily/weekly)
- "Check for Updates Now" button
- Last check timestamp display

### 2. Advanced Dropdown (Existing)
- "Pull Latest Images & Restart" button (already existed)
- Now properly integrated with the new auto-pull system

## Scheduled Tasks

### Console Command: `services:auto-pull`

**Usage:**
```bash
php artisan services:auto-pull [schedule]
```

**Arguments:**
- `schedule` (optional): Filter by schedule type (hourly/daily/weekly)

**Behavior:**
1. Finds all services with `auto_image_pull_enabled = true`
2. Filters by schedule if specified
3. Checks if service is running
4. Pulls latest images
5. Restarts service
6. Updates `last_image_pull_check` timestamp

### Scheduled in Kernel
- **Hourly**: Runs every hour for services with schedule = "hourly"
- **Daily**: Runs at 2:00 AM for services with schedule = "daily"
- **Weekly**: Runs Sunday at 2:00 AM for services with schedule = "weekly"

## Files Changed

### Backend
1. `database/migrations/2025_11_06_140000_add_auto_image_pull_to_services.php` - Database migration
2. `app/Models/Service.php` - Added casts for new fields
3. `app/Http/Controllers/Api/ServicesController.php` - Added API endpoints
4. `routes/api.php` - Added API routes
5. `app/Console/Commands/AutoPullImages.php` - Scheduled command
6. `app/Console/Kernel.php` - Scheduled tasks
7. `app/Livewire/Project/Service/Heading.php` - Added auto-pull methods
8. `app/Livewire/Project/Service/AutoPull.php` - New Livewire component

### Frontend
1. `resources/views/livewire/project/service/auto-pull.blade.php` - New UI component
2. `resources/views/livewire/project/service/configuration.blade.php` - Integrated auto-pull UI

## Usage Examples

### Enable Auto Pull via API
```bash
curl -X PATCH https://your-coolify-instance/api/v1/services/abc123/auto-pull \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enabled": true, "schedule": "daily"}'
```

### Check for Updates via API
```bash
curl -X POST https://your-coolify-instance/api/v1/services/abc123/check-updates \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

### Manual Restart with Latest Images via API
```bash
curl -X POST https://your-coolify-instance/api/v1/services/abc123/restart?latest=true \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

## Testing

### Manual Testing
1. Navigate to service configuration page
2. Enable "Automatic Image Updates"
3. Select a schedule
4. Click "Check for Updates Now"
5. Verify service restarts with latest images

### API Testing
```bash
# Get service details
curl https://your-coolify-instance/api/v1/services/{uuid} \
  -H "Authorization: Bearer YOUR_TOKEN"

# Enable auto pull
curl -X PATCH https://your-coolify-instance/api/v1/services/{uuid}/auto-pull \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enabled": true, "schedule": "daily"}'

# Check for updates
curl -X POST https://your-coolify-instance/api/v1/services/{uuid}/check-updates \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Command Testing
```bash
# Test hourly schedule
php artisan services:auto-pull hourly

# Test daily schedule  
php artisan services:auto-pull daily

# Test all schedules
php artisan services:auto-pull
```

## Benefits

1. **Always Up-to-Date**: Services automatically receive latest security patches and features
2. **Flexible Scheduling**: Choose update frequency that suits your needs
3. **Visibility**: Track when updates were last checked
4. **API Support**: Fully automated via API for CI/CD pipelines
5. **Safe**: Only restarts running services, respects deployment locks
6. **User Control**: Easy toggle to enable/disable per service

## Future Enhancements

Potential improvements:
- Notification when updates are applied
- Rollback capability if update fails
- Image digest comparison for accurate update detection
- Custom cron schedule support
- Update history log
- Dry-run mode to check without updating
