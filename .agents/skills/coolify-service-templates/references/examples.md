# Service Template Examples

Real-world examples of Coolify service templates.

## Minimal Single Service

```yaml
# documentation: https://actualbudget.org/docs/install/docker
# slogan: A local-first personal finance app
# category: finance
# tags: budget,finance,money
# logo: svgs/actualbudget.png
# port: 5006

services:
  actual_server:
    image: actualbudget/actual-server:latest
    environment:
      - SERVICE_FQDN_ACTUAL_5006
      - ACTUAL_LOGIN_METHOD=password
    volumes:
      - actual_data:/data
    healthcheck:
      test:
        - CMD-SHELL
        - "bash -c ':> /dev/tcp/127.0.0.1/5006' || exit 1"
      interval: 5s
      timeout: 20s
      retries: 3
```

## Service with Environment Variables

```yaml
# documentation: https://docs.n8n.io/hosting/installation/docker/
# slogan: Workflow automation tool
# category: automation
# tags: automation,workflow,nocode
# logo: svgs/n8n.svg
# port: 5678

services:
  n8n:
    image: n8nio/n8n:latest
    environment:
      - SERVICE_FQDN_N8N
      - N8N_BASIC_AUTH_ACTIVE=true
      - N8N_BASIC_AUTH_USER=${SERVICE_USER_N8N}
      - N8N_BASIC_AUTH_PASSWORD=${SERVICE_PASSWORD_N8N}
      - N8N_HOST=${SERVICE_FQDN_N8N}
      - WEBHOOK_URL=${SERVICE_URL_N8N}
      - GENERIC_TIMEZONE=${TIMEZONE:-UTC}
    volumes:
      - ${COOLIFY_VOLUME_N8N}:/home/node/.n8n
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://127.0.0.1:5678/healthz"]
      interval: 5s
      timeout: 10s
      retries: 3
```

## Service with Database (PostgreSQL)

```yaml
# documentation: https://plausible.io/docs/self-hosting
# slogan: Privacy-friendly web analytics
# category: analytics
# tags: analytics,stats,privacy
# logo: svgs/plausible.svg
# port: 8000

services:
  plausible:
    image: plausible/analytics:latest
    environment:
      - SERVICE_FQDN_PLAUSIBLE
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
      - SECRET_KEY_BASE=${SERVICE_PASSWORD_PLAUSIBLE_64}
      - BASE_URL=${SERVICE_URL_PLAUSIBLE}
      - DISABLE_REGISTRATION=${DISABLE_REGISTRATION:-false}
    depends_on:
      postgres:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://127.0.0.1:8000"]
      interval: 5s
      timeout: 10s
      retries: 3

  postgres:
    image: postgres:16-alpine
    environment:
      - POSTGRES_USER=${SERVICE_USER_POSTGRES}
      - POSTGRES_PASSWORD=${SERVICE_PASSWORD_POSTGRES}
      - POSTGRES_DB=${POSTGRES_DB:-plausible}
    volumes:
      - ${COOLIFY_VOLUME_POSTGRES}:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${SERVICE_USER_POSTGRES}"]
      interval: 5s
      timeout: 10s
      retries: 10
```

## Service with MySQL

```yaml
# documentation: https://docs.wordfence.com/
# slogan: WordPress security plugin
# category: security
# tags: security,wordpress,waf
# logo: svgs/wordfence.svg
# port: 80

services:
  wordfence:
    image: wordfence/daemon:latest
    environment:
      - SERVICE_FQDN_WORDFENCE
      - WF_MYSQL_HOST=mysql
      - WF_MYSQL_PORT=3306
      - WF_MYSQL_DATABASE=${MYSQL_DATABASE:-wordfence}
      - WF_MYSQL_USERNAME=${SERVICE_USER_MYSQL}
      - WF_MYSQL_PASSWORD=${SERVICE_PASSWORD_MYSQL}
    depends_on:
      mysql:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 5s
      timeout: 10s
      retries: 3

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_ROOT_PASSWORD=${SERVICE_PASSWORD_MYSQL_ROOT}
      - MYSQL_DATABASE=${MYSQL_DATABASE:-wordfence}
      - MYSQL_USER=${SERVICE_USER_MYSQL}
      - MYSQL_PASSWORD=${SERVICE_PASSWORD_MYSQL}
    volumes:
      - ${COOLIFY_VOLUME_MYSQL}:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 10s
      retries: 10
```

## Service with Redis

```yaml
# documentation: https://docs.papermerge.io/
# slogan: Open source document management
# category: productivity
# tags: documents,pdf,dms
# logo: svgs/papermerge.svg
# port: 8000

services:
  papermerge:
    image: papermerge/papermerge:latest
    environment:
      - SERVICE_FQDN_PAPERMERGE
      - DATABASE_URL=${COOLIFY_DATABASE_URL}
      - REDIS_URL=${COOLIFY_REDIS_URL}
      - SECRET_KEY=${SERVICE_PASSWORD_PAPERMERGE}
      - SUPERUSER_USERNAME=${ADMIN_USERNAME:-admin}
      - SUPERUSER_EMAIL=${ADMIN_EMAIL}
      - SUPERUSER_PASSWORD=${ADMIN_PASSWORD}
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
    volumes:
      - ${COOLIFY_VOLUME_PAPERMERGE}:/app/media

  postgres:
    image: postgres:16-alpine
    environment:
      - POSTGRES_USER=${SERVICE_USER_POSTGRES}
      - POSTGRES_PASSWORD=${SERVICE_PASSWORD_POSTGRES}
      - POSTGRES_DB=${POSTGRES_DB:-papermerge}
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

## Multi-Service Application

```yaml
# documentation: https://docs.mattermost.com/
# slogan: Open source collaboration platform
# category: chat
# tags: chat,collaboration,team
# logo: svgs/mattermost.svg
# port: 8065

services:
  mattermost:
    image: mattermost/mattermost-team-edition:latest
    environment:
      - SERVICE_FQDN_MATTERMOST
      - MM_SQLSETTINGS_DRIVERNAME=postgres
      - MM_SQLSETTINGS_DATASOURCE=${COOLIFY_DATABASE_URL}
      - MM_SERVICESETTINGS_SITEURL=${SERVICE_URL_MATTERMOST}
      - MM_FILESETTINGS_DIRECTORY=/mattermost/data
    volumes:
      - ${COOLIFY_VOLUME_MATTERMOST}:/mattermost/data
      - ${COOLIFY_VOLUME_MATTERMOST_CONFIG}:/mattermost/config
      - ${COOLIFY_VOLUME_MATTERMOST_LOGS}:/mattermost/logs
      - ${COOLIFY_VOLUME_MATTERMOST_PLUGINS}:/mattermost/plugins
    depends_on:
      postgres:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://127.0.0.1:8065/api/v4/system/ping"]
      interval: 10s
      timeout: 10s
      retries: 3

  postgres:
    image: postgres:16-alpine
    environment:
      - POSTGRES_USER=${SERVICE_USER_POSTGRES}
      - POSTGRES_PASSWORD=${SERVICE_PASSWORD_POSTGRES}
      - POSTGRES_DB=${POSTGRES_DB:-mattermost}
    volumes:
      - ${COOLIFY_VOLUME_POSTGRES}:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${SERVICE_USER_POSTGRES}"]
      interval: 5s
      timeout: 10s
      retries: 10
```

## Service with Required Variables

```yaml
# documentation: https://docs.github.com/en/actions/hosting-your-own-runners
# slogan: Self-hosted GitHub Actions runner
# category: development
# tags: ci,cd,github,automation
# logo: svgs/github-runner.svg
# port: 8080

services:
  github-runner:
    image: myoung34/github-runner:latest
    environment:
      - SERVICE_FQDN_GITHUB_RUNNER
      - REPO_URL=${REPO_URL:?}
      - RUNNER_NAME=${RUNNER_NAME:-coolify-runner}
      - RUNNER_TOKEN=${RUNNER_TOKEN:?}
      - LABELS=${LABELS:-self-hosted,coolify}
      - DISABLE_AUTO_UPDATE=${DISABLE_AUTO_UPDATE:-true}
    volumes:
      - ${COOLIFY_VOLUME_GITHUB_RUNNER}:/home/runner
      - /var/run/docker.sock:/var/run/docker.sock
```

## Service with Multiple Volumes

```yaml
# documentation: https://docs.jellyfin.org/
# slogan: Free software media system
# category: media
# tags: media,streaming,video
# logo: svgs/jellyfin.svg
# port: 8096

services:
  jellyfin:
    image: jellyfin/jellyfin:latest
    environment:
      - SERVICE_FQDN_JELLYFIN
      - PUID=${PUID:-1000}
      - PGID=${PGID:-1000}
      - TZ=${TZ:-UTC}
    volumes:
      - ${COOLIFY_VOLUME_JELLYFIN_CONFIG}:/config
      - ${COOLIFY_VOLUME_JELLYFIN_CACHE}:/cache
      - ${MEDIA_PATH:?}:/media:ro
    healthcheck:
      test: ["CMD", "curl", "-f", "http://127.0.0.1:8096/health"]
      interval: 10s
      timeout: 10s
      retries: 3
```

## Service with Complex Configuration

```yaml
# documentation: https://docs.nextcloud.com/
# slogan: Self-hosted cloud storage
# category: storage
# tags: cloud,storage,files
# logo: svgs/nextcloud.svg
# port: 80

services:
  nextcloud:
    image: nextcloud:latest
    environment:
      - SERVICE_FQDN_NEXTCLOUD
      - POSTGRES_HOST=postgres
      - POSTGRES_DB=${POSTGRES_DB:-nextcloud}
      - POSTGRES_USER=${SERVICE_USER_POSTGRES}
      - POSTGRES_PASSWORD=${SERVICE_PASSWORD_POSTGRES}
      - NEXTCLOUD_ADMIN_USER=${ADMIN_USER:-admin}
      - NEXTCLOUD_ADMIN_PASSWORD=${ADMIN_PASSWORD:?}
      - NEXTCLOUD_TRUSTED_DOMAINS=${SERVICE_FQDN_NEXTCLOUD}
      - OVERWRITEPROTOCOL=https
      - OVERWRITEHOST=${SERVICE_FQDN_NEXTCLOUD}
      - OVERWRITEWEBROOT=/
      - REDIS_HOST=redis
    volumes:
      - ${COOLIFY_VOLUME_NEXTCLOUD}:/var/www/html
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

  postgres:
    image: postgres:16-alpine
    environment:
      - POSTGRES_USER=${SERVICE_USER_POSTGRES}
      - POSTGRES_PASSWORD=${SERVICE_PASSWORD_POSTGRES}
      - POSTGRES_DB=${POSTGRES_DB:-nextcloud}
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

  cron:
    image: nextcloud:latest
    command: /cron.sh
    volumes:
      - ${COOLIFY_VOLUME_NEXTCLOUD}:/var/www/html
    depends_on:
      - nextcloud
```

## Notes

1. Always use health checks for services that support them
2. Use `depends_on` with `condition: service_healthy` when database is required
3. Include `port` in metadata for proxy configuration
4. Use Coolify magic variables for dynamic configuration
5. Test templates before submitting
