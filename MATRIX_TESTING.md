# Testing Matrix Notifications in Coolify

This guide explains how to test the new Matrix notification functionality using the Docker image with Matrix support.

## Docker Image

The Matrix notification feature is available in this Docker image:
```
keithah/coolify:matrix-notifications
```

## Quick Start Testing

### 1. Prepare Testing Environment

```bash
# Create testing directory
mkdir coolify-matrix-test
cd coolify-matrix-test

# Create database directory and file for SQLite
mkdir -p database
touch database/database.sqlite

# Pull the test image
docker pull keithah/coolify:matrix-notifications
```

### 2. Setup Environment

Create a `.env` file based on Coolify's requirements. The key environment variables you'll need:

```env
# Basic Coolify settings
APP_NAME="Coolify"
APP_ENV=local
APP_URL=http://localhost:8000
APP_ID=local

# Database (SQLite for quick testing)
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite

# Required for Laravel
APP_KEY=base64:YOUR_APP_KEY_HERE

# Optional: enable detailed logs
LOG_LEVEL=debug
```

**Important:** Create the SQLite database file before starting the container:

```bash
# Create database directory and file
mkdir -p database
touch database/database.sqlite
```

### 3. Run with Docker

```bash
# Simple docker run command
docker run -d \
  --name coolify-matrix-test \
  -p 8000:80 \
  -v $(pwd)/database:/var/www/html/database \
  -v $(pwd)/.env:/var/www/html/.env \
  keithah/coolify:matrix-notifications

# Or use docker-compose (create docker-compose.yml first)
docker-compose up -d
```

### 4. Access Coolify

1. Open your browser to `http://localhost:8000`
2. Complete the initial setup
3. Navigate to **Settings** → **Notifications** → **Matrix**

## Matrix Configuration

### Prerequisites

You'll need:
1. **Matrix Account**: Create a dedicated bot account (recommended)
2. **Matrix Room**: Create or use an existing room
3. **Access Token**: Get an access token for your bot account

### Getting Matrix Access Token

```bash
# Replace with your homeserver URL and credentials
# Note: Use the full Matrix user ID (@username:homeserver.com)
curl -XPOST -H "Content-Type: application/json" -d '{
  "type": "m.login.password",
  "identifier": {"user": "@your_bot_username:your-homeserver.com", "type": "m.id.user"},
  "password": "your_bot_password"
}' "https://your-homeserver.com/_matrix/client/v3/login"
```

This returns JSON with an `access_token` field. Example response:
```json
{
  "access_token": "syt_...",
  "user_id": "@your_bot_username:your-homeserver.com"
}
```

### Getting Room ID

1. In your Matrix client, go to Room Settings
2. Click "Advanced"
3. Copy the "Internal room ID" (format: `!xyz:homeserver.com`)

### Configuration in Coolify

1. **Homeserver URL**: Your Matrix homeserver (e.g., `https://matrix.org`)
2. **Room ID**: The internal room ID from above
3. **Access Token**: The access token from the login response
4. **Friendly Name**: Optional descriptive name

## Test Scenarios

### 1. Basic Connection Test

1. Configure Matrix settings in Coolify
2. Click "Send Test Notification"
3. Check your Matrix room for the test message

### 2. Application Deployment Test

1. Deploy a simple application in Coolify
2. Monitor Matrix room for deployment notifications
3. Test both success and failure scenarios

### 3. Server Monitoring Test

1. Add a server to Coolify
2. Test server unreachable notifications by temporarily blocking connectivity
3. Verify Matrix notifications are received

### 4. Backup Notifications Test

1. Configure a database backup
2. Run the backup job
3. Check Matrix room for backup status notifications

## Notification Types Supported

✅ **Deployment Success** - When applications deploy successfully
✅ **Deployment Failure** - When deployments fail
✅ **Container Status Changes** - When containers stop/restart
✅ **Backup Success** - When backups complete successfully
✅ **Backup Failure** - When backups fail
✅ **Scheduled Task Success** - When scheduled tasks complete
✅ **Scheduled Task Failure** - When scheduled tasks fail
✅ **Docker Cleanup** - When cleanup operations run
✅ **Server Disk Usage** - When disk usage is high
✅ **Server Reachable** - When servers come back online
✅ **Server Unreachable** - When servers go offline
✅ **Server Patching** - When server updates are available

## Testing with Docker Compose

### Sample docker-compose.yml

```yaml
version: '3.8'
services:
  coolify:
    image: keithah/coolify:matrix-notifications
    container_name: coolify-matrix-test
    ports:
      - "8000:8080"
    volumes:
      - ./data:/var/www/html/storage
      - ./.env:/var/www/html/.env
    environment:
      - APP_ENV=local
      - APP_DEBUG=true
      - PHP_MEMORY_LIMIT=512M
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/health"]
      interval: 30s
      timeout: 10s
      retries: 3

  database:
    image: postgres:15
    container_name: coolify-matrix-db
    environment:
      POSTGRES_DB: coolify
      POSTGRES_USER: coolify
      POSTGRES_PASSWORD: password
    volumes:
      - db_data:/var/lib/postgresql/data
    ports:
      - "5432:5432"

  redis:
    image: redis:7-alpine
    container_name: coolify-matrix-redis
    ports:
      - "6379:6379"

volumes:
  db_data:
```

### Environment File (.env)

```env
APP_NAME="Coolify Matrix Test"
APP_ENV=local
APP_KEY=base64:GENERATE_32_CHAR_KEY_HERE
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=database
DB_PORT=5432
DB_DATABASE=coolify
DB_USERNAME=coolify
DB_PASSWORD=password

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

LOG_CHANNEL=stack
LOG_LEVEL=debug
```

## Troubleshooting

### Matrix Messages Not Sending

1. **Check Access Token**: Ensure the token is valid and has proper permissions
2. **Verify Room ID**: Confirm the room ID format is correct (`!xyz:homeserver.com`)
3. **Bot Permissions**: Make sure your bot user is invited to the room
4. **Homeserver URL**: Verify the URL includes the correct protocol and port

### Docker Issues

1. **Port Conflicts**: Change port mapping if 8000 is in use
2. **Memory Issues**: Increase Docker memory limits for large applications
3. **Permission Issues**: Check file permissions in mounted volumes

### Common Error Messages

- **"Room not found"**: Bot user needs to be invited to the room
- **"Invalid access token"**: Token expired or incorrect
- **"Connection refused"**: Homeserver URL incorrect or unreachable

## Matrix API Details

The implementation uses:
- **Matrix Client-Server API v1.0**
- **PUT requests** with transaction IDs for message deduplication
- **HTML formatted messages** with plain text fallback
- **Proper authentication** via Bearer tokens

## Security Notes

- ✅ All sensitive data (homeserver URL, room ID, access token) is **encrypted at rest**
- ✅ Uses dedicated bot accounts (recommended)
- ✅ Follows Matrix API best practices
- ✅ Implements proper authorization checks

## Support

If you encounter issues:

1. Check Docker container logs: `docker logs coolify-matrix-test`
2. Verify Matrix room permissions and bot setup
3. Test Matrix API connectivity manually with curl
4. Check the [PR discussion](https://github.com/coollabsio/coolify/pull/6717) for updates

## Production Deployment

Once testing is complete, this feature will be available in the main Coolify release. The Docker image `keithah/coolify:matrix-notifications` is for testing purposes only.

---

**Built with Matrix ❤️ for the Coolify community**