import { createDirectories, getServiceFromDB, getServiceImage, getServiceMainPort, isDev, makeLabelForServices } from "./common";
import fs from 'fs/promises';
export async function getTemplates() {
    let templates = [{
        "templateVersion": "1.0.0",
        "defaultVersion": "latest",
        "name": "Test-Fake-Service",
        "description": "",
        "services": {
            "$$id": {
                "name": "Test-Fake-Service",
                "depends_on": [
                    "$$id-postgresql",
                    "$$id-redis"
                ],
                "image": "weblate/weblate:$$core_version",
                "volumes": [
                    "$$id-data:/app/data",
                ],
                "environment": [
                    `POSTGRES_SECRET=$$secret_postgres_secret`,
                    `WEBLATE_SITE_DOMAIN=$$config_weblate_site_domain`,
                    `WEBLATE_ADMIN_PASSWORD=$$secret_weblate_admin_password`,
                    `POSTGRES_PASSWORD=$$secret_postgres_password`,
                    `POSTGRES_USER=$$config_postgres_user`,
                    `POSTGRES_DATABASE=$$config_postgres_db`,
                    `POSTGRES_HOST=$$id-postgresql`,
                    `POSTGRES_PORT=5432`,
                    `REDIS_HOST=$$id-redis`,
                ],
                "ports": [
                    "8080"
                ]
            },
            "$$id-postgresql": {
                "name": "PostgreSQL",
                "depends_on": [],
                "image": "postgres:14-alpine",
                "volumes": [
                    "$$id-postgresql-data:/var/lib/postgresql/data",
                ],
                "environment": [
                    "POSTGRES_USER=$$config_postgres_user",
                    "POSTGRES_PASSWORD=$$secret_postgres_password",
                    "POSTGRES_DB=$$config_postgres_db",
                ],
                "ports": []
            },
            "$$id-redis": {
                "name": "Redis",
                "depends_on": [],
                "image": "redis:7-alpine",
                "volumes": [
                    "$$id-redis-data:/data",
                ],
                "environment": [],
                "ports": [],
            }
        },
        "variables": [
            {
                "id": "$$config_weblate_site_domain",
                "name": "WEBLATE_SITE_DOMAIN",
                "label": "Weblate Domain",
                "defaultValue": "$$generate_domain",
                "description": "",
            },
            {
                "id": "$$secret_weblate_admin_password",
                "name": "WEBLATE_ADMIN_PASSWORD",
                "label": "Weblate Admin Password",
                "defaultValue": "$$generate_password",
                "description": "",
                "extras": {
                    "isVisibleOnUI": true,
                }
            },
            {
                "id": "$$secret_weblate_admin_password2",
                "name": "WEBLATE_ADMIN_PASSWORD2",
                "label": "Weblate Admin Password2",
                "defaultValue": "$$generate_password",
                "description": "",
            },
            {
                "id": "$$config_postgres_user",
                "name": "POSTGRES_USER",
                "label": "PostgreSQL User",
                "defaultValue": "$$generate_username",
                "description": "",
            },
            {
                "id": "$$secret_postgres_password",
                "name": "POSTGRES_PASSWORD",
                "label": "PostgreSQL Password",
                "defaultValue": "$$generate_password(32)",
                "description": "",
            },
            {
                "id": "$$secret_postgres_password_hex32",
                "name": "POSTGRES_PASSWORD_hex32",
                "label": "PostgreSQL Password hex32",
                "defaultValue": "$$generate_hex(32)",
                "description": "",
            },
            {
                "id": "$$config_postgres_something_hex32",
                "name": "POSTGRES_SOMETHING_HEX32",
                "label": "PostgreSQL Something hex32",
                "defaultValue": "$$generate_hex(32)",
                "description": "",
            },
            {
                "id": "$$config_postgres_db",
                "name": "POSTGRES_DB",
                "label": "PostgreSQL Database",
                "defaultValue": "weblate",
                "description": "",
            },
            {
                "id": "$$secret_postgres_secret",
                "name": "POSTGRES_SECRET",
                "label": "PostgreSQL Secret",
                "defaultValue": "",
                "description": "",
            },
        ]
    },
    {
        "templateVersion": "1.0.0",
        "defaultVersion": "1.0.3",
        "name": "Appwrite",
        "description": "Secure Backend Server for Web, Mobile & Flutter Developers.",
        "services": {
            "$$id": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_WORKER_PER_CORE=$$config__app_worker_per_core",
                    "_APP_LOCALE=$$config__app_locale",
                    "_APP_CONSOLE_WHITELIST_ROOT=$$config__app_console_whitelist_root",
                    "_APP_CONSOLE_WHITELIST_EMAILS=$$config__app_console_whitelist_emails",
                    "_APP_CONSOLE_WHITELIST_IPS=$$config__app_console_whitelist_ips",
                    "_APP_SYSTEM_EMAIL_NAME=$$config__app_system_email_name",
                    "_APP_SYSTEM_EMAIL_ADDRESS=$$config__app_system_email_address",
                    "_APP_SYSTEM_SECURITY_EMAIL_ADDRESS=$$config__app_system_security_email_address",
                    "_APP_SYSTEM_RESPONSE_FORMAT=$$config__app_system_response_format",
                    "_APP_OPTIONS_ABUSE=$$config__app_options_abuse",
                    "_APP_OPTIONS_FORCE_HTTPS=$$config__app_options_force_https",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_DOMAIN=$$generate_fqdn",
                    "_APP_DOMAIN_TARGET=$$generate_fqdn",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_SMTP_HOST=$$config__app_smtp_host",
                    "_APP_SMTP_PORT=$$config__app_smtp_port",
                    "_APP_SMTP_SECURE=$$config__app_smtp_secure",
                    "_APP_SMTP_USERNAME=$$config__app_smtp_username",
                    "_APP_SMTP_PASSWORD=$$secret__app_smtp_password",
                    "_APP_USAGE_STATS=$$config__app_usage_stats",
                    "_APP_INFLUXDB_HOST=$$config__app_influxdb_host",
                    "_APP_INFLUXDB_PORT=$$config__app_influxdb_port",
                    "_APP_STORAGE_LIMIT=$$config__app_storage_limit",
                    "_APP_STORAGE_PREVIEW_LIMIT=$$config__app_storage_preview_limit",
                    "_APP_STORAGE_ANTIVIRUS=$$config__app_storage_antivirus_enabled",
                    "_APP_STORAGE_ANTIVIRUS_HOST=$$config__app_storage_antivirus_host",
                    "_APP_STORAGE_ANTIVIRUS_PORT=$$config__app_storage_antivirus_port",
                    "_APP_STORAGE_DEVICE=$$config__app_storage_device",
                    "_APP_STORAGE_S3_ACCESS_KEY=$$secret__app_storage_s3_access_key",
                    "_APP_STORAGE_S3_SECRET=$$secret__app_storage_s3_secret",
                    "_APP_STORAGE_S3_REGION=$$config__app_storage_s3_region",
                    "_APP_STORAGE_S3_BUCKET=$$config__app_storage_s3_bucket",
                    "_APP_STORAGE_DO_SPACES_ACCESS_KEY=$$secret__app_storage_do_spaces_access_key",
                    "_APP_STORAGE_DO_SPACES_SECRET=$$secret__app_storage_do_spaces_secret",
                    "_APP_STORAGE_DO_SPACES_REGION=$$config__app_storage_do_spaces_region",
                    "_APP_STORAGE_DO_SPACES_BUCKET=$$config__app_storage_do_spaces_bucket",
                    "_APP_STORAGE_BACKBLAZE_ACCESS_KEY=$$secret__app_storage_backblaze_access_key",
                    "_APP_STORAGE_BACKBLAZE_SECRET=$$secret__app_storage_backblaze_secret",
                    "_APP_STORAGE_BACKBLAZE_REGION=$$config__app_storage_backblaze_region",
                    "_APP_STORAGE_BACKBLAZE_BUCKET=$$config__app_storage_backblaze_bucket",
                    "_APP_STORAGE_LINODE_ACCESS_KEY=$$secret__app_storage_linode_access_key",
                    "_APP_STORAGE_LINODE_SECRET=$$secret__app_storage_linode_secret",
                    "_APP_STORAGE_LINODE_REGION=$$config__app_storage_linode_region",
                    "_APP_STORAGE_LINODE_BUCKET=$$config__app_storage_linode_bucket",
                    "_APP_STORAGE_WASABI_ACCESS_KEY=$$secret__app_storage_wasabi_access_key",
                    "_APP_STORAGE_WASABI_SECRET=$$secret__app_storage_wasabi_secret",
                    "_APP_STORAGE_WASABI_REGION=$$config__app_storage_wasabi_region",
                    "_APP_STORAGE_WASABI_BUCKET=$$config__app_storage_wasabi_bucket",
                    "_APP_FUNCTIONS_SIZE_LIMIT=$$config__app_functions_size_limit",
                    "_APP_FUNCTIONS_TIMEOUT=$$config__app_functions_timeout",
                    "_APP_FUNCTIONS_BUILD_TIMEOUT=$$config__app_functions_build_timeout",
                    "_APP_FUNCTIONS_CONTAINERS=$$config__app_functions_containers",
                    "_APP_FUNCTIONS_CPUS=$$config__app_functions_cpus",
                    "_APP_FUNCTIONS_MEMORY=$$config__app_functions_memory_allocated",
                    "_APP_FUNCTIONS_MEMORY_SWAP=$$config__app_functions_memory_swap",
                    "_APP_FUNCTIONS_RUNTIMES=$$config__app_functions_runtimes",
                    "_APP_EXECUTOR_SECRET=$$secret__app_executor_secret",
                    "_APP_EXECUTOR_HOST=$$config__app_executor_host",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "_APP_STATSD_HOST=$$config__app_statsd_host",
                    "_APP_STATSD_PORT=$$config__app_statsd_port",
                    "_APP_MAINTENANCE_INTERVAL=$$config__app_maintenance_interval",
                    "_APP_MAINTENANCE_RETENTION_EXECUTION=$$config__app_maintenance_retention_execution",
                    "_APP_MAINTENANCE_RETENTION_CACHE=$$config__app_maintenance_retention_cache",
                    "_APP_MAINTENANCE_RETENTION_ABUSE=$$config__app_maintenance_retention_abuse",
                    "_APP_MAINTENANCE_RETENTION_AUDIT=$$config__app_maintenance_retention_audit",
                    "_APP_SMS_PROVIDER=$$config__app_sms_provider",
                    "_APP_SMS_FROM=$$config__app_sms_from",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [
                    "$$id-uploads:/storage/uploads",
                    "$$id-cache:/storage/cache",
                    "$$id-config:/storage/config",
                    "$$id-certificates:/storage/certificates",
                    "$$id-functions:/storage/functions"
                ],
                "ports": [
                    "80"
                ],
                "proxy": [
                    {
                        "domain": "$$config_coolify_fqdn_appwrite",
                        "port": "80"
                    }
                ]
            },
            "$$id-executor": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_VERSION=$$config__app_version",
                    "_APP_FUNCTIONS_TIMEOUT=$$config__app_functions_timeout",
                    "_APP_FUNCTIONS_BUILD_TIMEOUT=$$config__app_functions_build_timeout",
                    "_APP_FUNCTIONS_CONTAINERS=$$config__app_functions_containers",
                    "_APP_FUNCTIONS_RUNTIMES=$$config__app_functions_runtimes",
                    "_APP_FUNCTIONS_CPUS=$$config__app_functions_cpus",
                    "_APP_FUNCTIONS_MEMORY=$$config__app_functions_memory_allocated",
                    "_APP_FUNCTIONS_MEMORY_SWAP=$$config__app_functions_memory_swap",
                    "_APP_FUNCTIONS_INACTIVE_THRESHOLD=$$config__app_functions_inactive_threshold",
                    "_APP_EXECUTOR_SECRET=$$secret__app_executor_secret",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "_APP_STORAGE_DEVICE=$$config__app_storage_device",
                    "_APP_STORAGE_S3_ACCESS_KEY=$$secret__app_storage_s3_access_key",
                    "_APP_STORAGE_S3_SECRET=$$secret__app_storage_s3_secret",
                    "_APP_STORAGE_S3_REGION=$$config__app_storage_s3_region",
                    "_APP_STORAGE_S3_BUCKET=$$config__app_storage_s3_bucket",
                    "_APP_STORAGE_DO_SPACES_ACCESS_KEY=$$secret__app_storage_do_spaces_access_key",
                    "_APP_STORAGE_DO_SPACES_SECRET=$$secret__app_storage_do_spaces_secret",
                    "_APP_STORAGE_DO_SPACES_REGION=$$config__app_storage_do_spaces_region",
                    "_APP_STORAGE_DO_SPACES_BUCKET=$$config__app_storage_do_spaces_bucket",
                    "_APP_STORAGE_BACKBLAZE_ACCESS_KEY=$$secret__app_storage_backblaze_access_key",
                    "_APP_STORAGE_BACKBLAZE_SECRET=$$secret__app_storage_backblaze_secret",
                    "_APP_STORAGE_BACKBLAZE_REGION=$$config__app_storage_backblaze_region",
                    "_APP_STORAGE_BACKBLAZE_BUCKET=$$config__app_storage_backblaze_bucket",
                    "_APP_STORAGE_LINODE_ACCESS_KEY=$$secret__app_storage_linode_access_key",
                    "_APP_STORAGE_LINODE_SECRET=$$secret__app_storage_linode_secret",
                    "_APP_STORAGE_LINODE_REGION=$$config__app_storage_linode_region",
                    "_APP_STORAGE_LINODE_BUCKET=$$config__app_storage_linode_bucket",
                    "_APP_STORAGE_WASABI_ACCESS_KEY=$$secret__app_storage_wasabi_access_key",
                    "_APP_STORAGE_WASABI_SECRET=$$secret__app_storage_wasabi_secret",
                    "_APP_STORAGE_WASABI_REGION=$$config__app_storage_wasabi_region",
                    "_APP_STORAGE_WASABI_BUCKET=$$config__app_storage_wasabi_bucket",
                    "DOCKERHUB_PULL_USERNAME=$$config_dockerhub_pull_username",
                    "DOCKERHUB_PULL_PASSWORD=$$secret_dockerhub_pull_password",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [
                    "$$id-functions:/storage/functions",
                    "$$id-builds:/storage/builds",
                    "/var/run/docker.sock:/var/run/docker.sock"
                ],
                "entrypoint": "executor"
            },
            "$$id-influxdb": {
                "image": "appwrite/influxdb:1.5.0",
                "environment": [],
                "volumes": [
                    "$$id-influxdb:/var/lib/influxdb"
                ]
            },
            "$$id-maintenance": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_DOMAIN=$$generate_fqdn",
                    "_APP_DOMAIN_TARGET=$$generate_fqdn",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_MAINTENANCE_INTERVAL=$$config__app_maintenance_interval",
                    "_APP_MAINTENANCE_RETENTION_EXECUTION=$$config__app_maintenance_retention_execution",
                    "_APP_MAINTENANCE_RETENTION_CACHE=$$config__app_maintenance_retention_cache",
                    "_APP_MAINTENANCE_RETENTION_ABUSE=$$config__app_maintenance_retention_abuse",
                    "_APP_MAINTENANCE_RETENTION_AUDIT=$$config__app_maintenance_retention_audit", "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "maintenance"
            },
            "$$id-mariadb": {
                "image": "mariadb:10.7",
                "command": "--innodb-flush-method fsync",
                "environment": [
                    "MARIADB_ROOT_PASSWORD=$$secret__app_db_root_pass",
                    "MARIADB_DATABASE=$$config__app_db_schema",
                    "MARIADB_USER=$$config__app_db_user",
                    "MARIADB_PASSWORD=$$secret__app_db_pass",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [
                    "$$id-mariadb:/var/lib/mysql"
                ]
            },
            "$$id-realtime": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_WORKER_PER_CORE=$$config__app_worker_per_core",
                    "_APP_OPTIONS_ABUSE=$$config__app_options_abuse",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_USAGE_STATS=$$config__app_usage_stats",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "realtime",
                "proxy": [
                    {
                        "port": "80",
                        "pathPrefix": "/v1/realtime"
                    }
                ]
            },
            "$$id-redis": {
                "image": "redis:7.0.4-alpine",
                "command": "--maxmemory 512mb --maxmemory-policy allkeys-lru --maxmemory-samples 5",
                "environment": [],
                "volumes": [
                    "$$id-redis:/data"
                ]
            },
            "$$id-schedule": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "schedule"
            },
            "$$id-telegraf": {
                "image": "appwrite/telegraf:1.4.0",
                "environment": [
                    "_APP_INFLUXDB_HOST=$$config__app_influxdb_host",
                    "_APP_INFLUXDB_PORT=$$config__app_influxdb_port",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [
                    "$$id-influxdb:/var/lib/influxdb"
                ]
            },
            "$$id-usage-database": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_INFLUXDB_HOST=$$config__app_influxdb_host",
                    "_APP_INFLUXDB_PORT=$$config__app_influxdb_port",
                    "_APP_USAGE_TIMESERIES_INTERVAL=$$config__app_usage_timeseries_interval",
                    "_APP_USAGE_DATABASE_INTERVAL=$$config__app_usage_database_interval",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "usage --type database"
            },
            "$$id-usage-timeseries": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_INFLUXDB_HOST=$$config__app_influxdb_host",
                    "_APP_INFLUXDB_PORT=$$config__app_influxdb_port",
                    "_APP_USAGE_TIMESERIES_INTERVAL=$$config__app_usage_timeseries_interval",
                    "_APP_USAGE_DATABASE_INTERVAL=$$config__app_usage_database_interval",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "usage --type timeseries"
            },
            "$$id-worker-audits": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "worker-audits"
            },
            "$$id-worker-builds": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_EXECUTOR_SECRET=$$secret__app_executor_secret",
                    "_APP_EXECUTOR_HOST=$$config__app_executor_host",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "worker-builds"
            },
            "$$id-worker-certificates": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_DOMAIN=$$generate_fqdn",
                    "_APP_DOMAIN_TARGET=$$generate_fqdn",
                    "_APP_SYSTEM_SECURITY_EMAIL_ADDRESS=$$config__app_system_security_email_address",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [
                    "$$id-config:/storage/config",
                    "$$id-certificates:/storage/certificates"
                ],
                "entrypoint": "worker-certificates"
            },
            "$$id-worker-databases": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "worker-databases"
            },
            "$$id-worker-deletes": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_STORAGE_DEVICE=$$config__app_storage_device",
                    "_APP_STORAGE_S3_ACCESS_KEY=$$secret__app_storage_s3_access_key",
                    "_APP_STORAGE_S3_SECRET=$$secret__app_storage_s3_secret",
                    "_APP_STORAGE_S3_REGION=$$config__app_storage_s3_region",
                    "_APP_STORAGE_S3_BUCKET=$$config__app_storage_s3_bucket",
                    "_APP_STORAGE_DO_SPACES_ACCESS_KEY=$$secret__app_storage_do_spaces_access_key",
                    "_APP_STORAGE_DO_SPACES_SECRET=$$secret__app_storage_do_spaces_secret",
                    "_APP_STORAGE_DO_SPACES_REGION=$$config__app_storage_do_spaces_region",
                    "_APP_STORAGE_DO_SPACES_BUCKET=$$config__app_storage_do_spaces_bucket",
                    "_APP_STORAGE_BACKBLAZE_ACCESS_KEY=$$secret__app_storage_backblaze_access_key",
                    "_APP_STORAGE_BACKBLAZE_SECRET=$$secret__app_storage_backblaze_secret",
                    "_APP_STORAGE_BACKBLAZE_REGION=$$config__app_storage_backblaze_region",
                    "_APP_STORAGE_BACKBLAZE_BUCKET=$$config__app_storage_backblaze_bucket",
                    "_APP_STORAGE_LINODE_ACCESS_KEY=$$secret__app_storage_linode_access_key",
                    "_APP_STORAGE_LINODE_SECRET=$$secret__app_storage_linode_secret",
                    "_APP_STORAGE_LINODE_REGION=$$config__app_storage_linode_region",
                    "_APP_STORAGE_LINODE_BUCKET=$$config__app_storage_linode_bucket",
                    "_APP_STORAGE_WASABI_ACCESS_KEY=$$secret__app_storage_wasabi_access_key",
                    "_APP_STORAGE_WASABI_SECRET=$$secret__app_storage_wasabi_secret",
                    "_APP_STORAGE_WASABI_REGION=$$config__app_storage_wasabi_region",
                    "_APP_STORAGE_WASABI_BUCKET=$$config__app_storage_wasabi_bucket",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "_APP_EXECUTOR_SECRET=$$secret__app_executor_secret",
                    "_APP_EXECUTOR_HOST=$$config__app_executor_host",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [
                    "$$id-uploads:/storage/uploads",
                    "$$id-cache:/storage/cache",
                    "$$id-functions:/storage/functions",
                    "$$id-builds:/storage/builds",
                    "$$id-certificates:/storage/certificates"
                ],
                "entrypoint": "worker-deletes"
            },
            "$$id-worker-functions": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_DB_HOST=$$config__app_db_host",
                    "_APP_DB_PORT=$$config__app_db_port",
                    "_APP_DB_SCHEMA=$$config__app_db_schema",
                    "_APP_DB_USER=$$config__app_db_user",
                    "_APP_DB_PASS=$$secret__app_db_pass",
                    "_APP_FUNCTIONS_TIMEOUT=$$config__app_functions_timeout",
                    "_APP_EXECUTOR_SECRET=$$secret__app_executor_secret",
                    "_APP_EXECUTOR_HOST=$$config__app_executor_host",
                    "_APP_USAGE_STATS=$$config__app_usage_stats",
                    "DOCKERHUB_PULL_USERNAME=$$config_dockerhub_pull_username",
                    "DOCKERHUB_PULL_PASSWORD=$$secret_dockerhub_pull_password", "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "worker-functions"
            },
            "$$id-worker-mails": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_SYSTEM_EMAIL_NAME=$$config__app_system_email_name",
                    "_APP_SYSTEM_EMAIL_ADDRESS=$$config__app_system_email_address",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_SMTP_HOST=$$config__app_smtp_host",
                    "_APP_SMTP_PORT=$$config__app_smtp_port",
                    "_APP_SMTP_SECURE=$$config__app_smtp_secure",
                    "_APP_SMTP_USERNAME=$$config__app_smtp_username",
                    "_APP_SMTP_PASSWORD=$$secret__app_smtp_password",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "worker-mails"
            },
            "$$id-worker-messaging": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_SMS_PROVIDER=$$config__app_sms_provider",
                    "_APP_SMS_FROM=$$config__app_sms_from",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "worker-messaging"
            },
            "$$id-worker-webhooks": {
                "image": "appwrite/appwrite:$$core_version",
                "environment": [
                    "_APP_ENV=$$config__app_env",
                    "_APP_OPENSSL_KEY_V1=$$secret__app_openssl_key_v1",
                    "_APP_SYSTEM_SECURITY_EMAIL_ADDRESS=$$config__app_system_security_email_address",
                    "_APP_REDIS_HOST=$$config__app_redis_host",
                    "_APP_REDIS_PORT=$$config__app_redis_port",
                    "_APP_REDIS_USER=$$config__app_redis_user",
                    "_APP_REDIS_PASS=$$secret__app_redis_pass",
                    "_APP_LOGGING_PROVIDER=$$config__app_logging_provider",
                    "_APP_LOGGING_CONFIG=$$config__app_logging_config",
                    "OPEN_RUNTIMES_NETWORK=$$config_open_runtimes_network",
                ],
                "volumes": [],
                "entrypoint": "worker-webhooks"
            }
        },
        "variables": [
            {
                "id": "$$config_coolify_fqdn_appwrite",
                "name": "COOLIFY_FQDN_APPWRITE",
                "label": "Appwrite specific FQDN",
                "defaultValue": "",
                "description": "Appwrite specific domain"
            },
            {
                "id": "$$secret__app_db_root_pass",
                "name": "MARIADB_ROOT_PASSWORD",
                "label": "MariaDB | _APP_DB_ROOT_PASS",
                "defaultValue": "$$generate_hex(16)",
                "description": "MariaDB server root password."
            },
            {
                "id": "$$config__app_db_schema",
                "name": "MARIADB_DATABASE",
                "label": "MariaDB | _APP_DB_SCHEMA",
                "defaultValue": "appwrite",
                "description": "MariaDB server database schema."
            },
            {
                "id": "$$config__app_db_user",
                "name": "MARIADB_USER",
                "label": "MariaDB | _APP_DB_USER",
                "defaultValue": "user",
                "description": "MariaDB server user name."
            },
            {
                "id": "$$secret__app_db_pass",
                "name": "MARIADB_PASSWORD",
                "label": "MariaDB | _APP_DB_PASS",
                "defaultValue": "$$generate_hex(16)",
                "description": "MariaDB server user password."
            },
            {
                "id": "$$config__app_influxdb_host",
                "name": "_APP_INFLUXDB_HOST",
                "label": "",
                "defaultValue": "$$id-influxdb",
                "description": ""
            },
            {
                "id": "$$config__app_influxdb_port",
                "name": "_APP_INFLUXDB_PORT",
                "label": "InfluxDB | _APP_INFLUXDB_PORT",
                "defaultValue": "8086",
                "description": "InfluxDB server TCP port."
            },
            {
                "id": "$$config__app_env",
                "name": "_APP_ENV",
                "label": "General | _APP_ENV",
                "defaultValue": "production",
                "description": "Set your server running environment."
            },
            {
                "id": "$$config__app_worker_per_core",
                "name": "_APP_WORKER_PER_CORE",
                "label": "General | _APP_WORKER_PER_CORE",
                "defaultValue": "6",
                "description": "Internal Worker per core for the API, Realtime and Executor containers. Can be configured to optimize performance."
            },
            {
                "id": "$$config__app_locale",
                "name": "_APP_LOCALE",
                "label": "General | _APP_LOCALE",
                "defaultValue": "en",
                "description": "Set your Appwrite's locale. By default, the locale is set to 'en'."
            },
            {
                "id": "$$config__app_console_whitelist_root",
                "name": "_APP_CONSOLE_WHITELIST_ROOT",
                "label": "General | _APP_CONSOLE_WHITELIST_ROOT",
                "defaultValue": "enabled",
                "description": "This option allows you to disable the creation of new users on the Appwrite console. When enabled only 1 user will be able to use the registration form. New users can be added by inviting them to your project. By default this option is enabled."
            },
            {
                "id": "$$config__app_console_whitelist_emails",
                "name": "_APP_CONSOLE_WHITELIST_EMAILS",
                "label": "General | _APP_CONSOLE_WHITELIST_EMAILS",
                "defaultValue": "",
                "description": "This option allows you to limit creation of new users on the Appwrite console. This option is very useful for small teams or sole developers. To enable it, pass a list of allowed email addresses separated by a comma."
            },
            {
                "id": "$$config__app_console_whitelist_ips",
                "name": "_APP_CONSOLE_WHITELIST_IPS",
                "label": "General | _APP_CONSOLE_WHITELIST_IPS",
                "defaultValue": "",
                "description": "This last option allows you to limit creation of users in Appwrite console for users sharing the same set of IP addresses. This option is very useful for team working with a VPN service or a company IP.\\n\\nTo enable/activate this option, pass a list of allowed IP addresses separated by a comma."
            },
            {
                "id": "$$config__app_system_email_name",
                "name": "_APP_SYSTEM_EMAIL_NAME",
                "label": "General | _APP_SYSTEM_EMAIL_NAME",
                "defaultValue": "Appwrite",
                "description": "This is the sender name value that will appear on email messages sent to developers from the Appwrite console. You can use url encoded strings for spaces and special chars."
            },
            {
                "id": "$$config__app_system_email_address",
                "name": "_APP_SYSTEM_EMAIL_ADDRESS",
                "label": "General | _APP_SYSTEM_EMAIL_ADDRESS",
                "defaultValue": "team@appwrite.io",
                "description": "This is the sender email address that will appear on email messages sent to developers from the Appwrite console. You should choose an email address that is allowed to be used from your SMTP server to avoid the server email ending in the users' SPAM folders."
            },
            {
                "id": "$$config__app_system_security_email_address",
                "name": "_APP_SYSTEM_SECURITY_EMAIL_ADDRESS",
                "label": "General | _APP_SYSTEM_SECURITY_EMAIL_ADDRESS",
                "defaultValue": "certs@appwrite.io",
                "description": "This is the email address used to issue SSL certificates for custom domains or the user agent in your webhooks payload."
            },
            {
                "id": "$$config__app_system_response_format",
                "name": "_APP_SYSTEM_RESPONSE_FORMAT",
                "label": "General | _APP_SYSTEM_RESPONSE_FORMAT",
                "defaultValue": "",
                "description": "Use this environment variable to set the default Appwrite HTTP response format to support an older version of Appwrite. This option is useful to overcome breaking changes between versions. You can also use the X-Appwrite-Response-Format HTTP request header to overwrite the response for a specific request. This variable accepts any valid Appwrite version. To use the current version format, leave the value of the variable empty."
            },
            {
                "id": "$$config__app_options_abuse",
                "name": "_APP_OPTIONS_ABUSE",
                "label": "General | _APP_OPTIONS_ABUSE",
                "defaultValue": "enabled",
                "description": "Allows you to disable abuse checks and API rate limiting. By default, set to 'enabled'. To cancel the abuse checking, set to 'disabled'. It is not recommended to disable this check-in a production environment."
            },
            {
                "id": "$$config__app_options_force_https",
                "name": "_APP_OPTIONS_FORCE_HTTPS",
                "label": "General | _APP_OPTIONS_FORCE_HTTPS",
                "defaultValue": "disabled",
                "description": "Allows you to force HTTPS connection to your API. This feature redirects any HTTP call to HTTPS and adds the 'Strict-Transport-Security' header to all HTTP responses."
            },
            {
                "id": "$$secret__app_openssl_key_v1",
                "name": "_APP_OPENSSL_KEY_V1",
                "label": "General | _APP_OPENSSL_KEY_V1",
                "defaultValue": "$$generate_hex(256)",
                "description": "This is your server private secret key that is used to encrypt all sensitive data on your server. Appwrite server encrypts all secret data on your server like webhooks, HTTP passwords, user sessions, and storage files. Keep it a secret and have a backup for it."
            },
            {
                "id": "$$generate_fqdn",
                "name": "_APP_DOMAIN",
                "label": "General | _APP_DOMAIN",
                "defaultValue": "localhost",
                "description": "Your Appwrite domain address. When setting a public suffix domain, Appwrite will attempt to issue a valid SSL certificate automatically. When used with a dev domain, Appwrite will assign a self-signed SSL certificate. The default value is 'localhost'."
            },
            {
                "id": "$$generate_fqdn",
                "name": "_APP_DOMAIN_TARGET",
                "label": "General | _APP_DOMAIN_TARGET",
                "defaultValue": "localhost",
                "description": "A DNS A record hostname to serve as a CNAME target for your Appwrite custom domains. You can use the same value as used for the Appwrite '_APP_DOMAIN' variable. The default value is 'localhost'."
            },
            {
                "id": "$$config__app_redis_host",
                "name": "_APP_REDIS_HOST",
                "label": "",
                "defaultValue": "$$id-redis",
                "description": ""
            },
            {
                "id": "$$config__app_redis_port",
                "name": "_APP_REDIS_PORT",
                "label": "Redis | _APP_REDIS_PORT",
                "defaultValue": "6379",
                "description": "Redis server TCP port."
            },
            {
                "id": "$$config__app_redis_user",
                "name": "_APP_REDIS_USER",
                "label": "Redis | _APP_REDIS_USER",
                "defaultValue": "$$generate_username",
                "description": "Redis server user. This is an optional variable. Default value is an empty string."
            },
            {
                "id": "$$secret__app_redis_pass",
                "name": "_APP_REDIS_PASS",
                "label": "Redis | _APP_REDIS_PASS",
                "defaultValue": "",
                "description": "Redis server password. This is an optional variable. Default value is an empty string."
            },
            {
                "id": "$$config__app_db_host",
                "name": "_APP_DB_HOST",
                "label": "",
                "defaultValue": "$$id-mariadb",
                "description": ""
            },
            {
                "id": "$$config__app_db_port",
                "name": "_APP_DB_PORT",
                "label": "MariaDB | _APP_DB_PORT",
                "defaultValue": "3306",
                "description": "MariaDB server TCP port."
            },
            {
                "id": "$$config__app_db_schema",
                "name": "_APP_DB_SCHEMA",
                "label": "MariaDB | _APP_DB_SCHEMA",
                "defaultValue": "appwrite",
                "description": "MariaDB server database schema."
            },
            {
                "id": "$$config__app_db_user",
                "name": "_APP_DB_USER",
                "label": "MariaDB | _APP_DB_USER",
                "defaultValue": "user",
                "description": "MariaDB server user name."
            },
            {
                "id": "$$secret__app_db_pass",
                "name": "_APP_DB_PASS",
                "label": "MariaDB | _APP_DB_PASS",
                "defaultValue": "$$generate_hex(16)",
                "description": "MariaDB server user password."
            },
            {
                "id": "$$config__app_smtp_host",
                "name": "_APP_SMTP_HOST",
                "label": "SMTP | _APP_SMTP_HOST",
                "defaultValue": "",
                "description": "SMTP server host name address. Use an empty string to disable all mail sending from the server. The default value for this variable is an empty string."
            },
            {
                "id": "$$config__app_smtp_port",
                "name": "_APP_SMTP_PORT",
                "label": "SMTP | _APP_SMTP_PORT",
                "defaultValue": "",
                "description": "SMTP server TCP port. Empty by default."
            },
            {
                "id": "$$config__app_smtp_secure",
                "name": "_APP_SMTP_SECURE",
                "label": "SMTP | _APP_SMTP_SECURE",
                "defaultValue": "",
                "description": "SMTP secure connection protocol. Empty by default, change to 'tls' if running on a secure connection."
            },
            {
                "id": "$$config__app_smtp_username",
                "name": "_APP_SMTP_USERNAME",
                "label": "SMTP | _APP_SMTP_USERNAME",
                "defaultValue": "",
                "description": "SMTP server user name. Empty by default."
            },
            {
                "id": "$$secret__app_smtp_password",
                "name": "_APP_SMTP_PASSWORD",
                "label": "SMTP | _APP_SMTP_PASSWORD",
                "defaultValue": "",
                "description": "SMTP server user password. Empty by default."
            },
            {
                "id": "$$config__app_usage_stats",
                "name": "_APP_USAGE_STATS",
                "label": "General | _APP_USAGE_STATS",
                "defaultValue": "enabled",
                "description": "This variable allows you to disable the collection and displaying of usage stats. This value is set to 'enabled' by default, to disable the usage stats set the value to 'disabled'. When disabled, it's recommended to turn off the Worker Usage, Influxdb and Telegraf containers for better resource usage."
            },
            {
                "id": "$$config__app_storage_limit",
                "name": "_APP_STORAGE_LIMIT",
                "label": "Storage | _APP_STORAGE_LIMIT",
                "defaultValue": "30000000",
                "description": "Maximum file size allowed for file upload. The default value is 30MB. You should pass your size limit value in bytes."
            },
            {
                "id": "$$config__app_storage_preview_limit",
                "name": "_APP_STORAGE_PREVIEW_LIMIT",
                "label": "Storage | _APP_STORAGE_PREVIEW_LIMIT",
                "defaultValue": "20000000",
                "description": "Maximum file size allowed for file image preview. The default value is 20MB. You should pass your size limit value in bytes."
            },
            {
                "id": "$$config__app_storage_antivirus_enabled",
                "name": "_APP_STORAGE_ANTIVIRUS",
                "label": "Storage | _APP_STORAGE_ANTIVIRUS",
                "defaultValue": "disabled",
                "description": "This variable allows you to disable the internal anti-virus scans. This value is set to 'disabled' by default, to enable the scans set the value to 'enabled'. Before enabling, you must add the ClamAV service and depend on it on main Appwrite service."
            },
            {
                "id": "$$config__app_storage_antivirus_host",
                "name": "_APP_STORAGE_ANTIVIRUS_HOST",
                "label": "Storage | _APP_STORAGE_ANTIVIRUS_HOST",
                "defaultValue": "clamav",
                "description": "ClamAV server host name address."
            },
            {
                "id": "$$config__app_storage_antivirus_port",
                "name": "_APP_STORAGE_ANTIVIRUS_PORT",
                "label": "Storage | _APP_STORAGE_ANTIVIRUS_PORT",
                "defaultValue": "3310",
                "description": "ClamAV server TCP port."
            },
            {
                "id": "$$config__app_storage_device",
                "name": "_APP_STORAGE_DEVICE",
                "label": "Storage | _APP_STORAGE_DEVICE",
                "defaultValue": "Local",
                "description": "Select default storage device. The default value is 'Local'. List of supported adapters are 'Local', 'S3', 'DOSpaces', 'Backblaze', 'Linode' and 'Wasabi'."
            },
            {
                "id": "$$secret__app_storage_s3_access_key",
                "name": "_APP_STORAGE_S3_ACCESS_KEY",
                "label": "Storage | _APP_STORAGE_S3_ACCESS_KEY",
                "defaultValue": "",
                "description": "AWS S3 storage access key. Required when the storage adapter is set to S3. You can get your access key from your AWS console."
            },
            {
                "id": "$$secret__app_storage_s3_secret",
                "name": "_APP_STORAGE_S3_SECRET",
                "label": "Storage | _APP_STORAGE_S3_SECRET",
                "defaultValue": "",
                "description": "AWS S3 storage secret key. Required when the storage adapter is set to S3. You can get your secret key from your AWS console."
            },
            {
                "id": "$$config__app_storage_s3_region",
                "name": "_APP_STORAGE_S3_REGION",
                "label": "Storage | _APP_STORAGE_S3_REGION",
                "defaultValue": "us-east-1",
                "description": "AWS S3 storage region. Required when storage adapter is set to S3. You can find your region info for your bucket from AWS console."
            },
            {
                "id": "$$config__app_storage_s3_bucket",
                "name": "_APP_STORAGE_S3_BUCKET",
                "label": "Storage | _APP_STORAGE_S3_BUCKET",
                "defaultValue": "",
                "description": "AWS S3 storage bucket. Required when storage adapter is set to S3. You can create buckets in your AWS console."
            },
            {
                "id": "$$secret__app_storage_do_spaces_access_key",
                "name": "_APP_STORAGE_DO_SPACES_ACCESS_KEY",
                "label": "Storage | _APP_STORAGE_DO_SPACES_ACCESS_KEY",
                "defaultValue": "",
                "description": "DigitalOcean spaces access key. Required when the storage adapter is set to DOSpaces. You can get your access key from your DigitalOcean console."
            },
            {
                "id": "$$secret__app_storage_do_spaces_secret",
                "name": "_APP_STORAGE_DO_SPACES_SECRET",
                "label": "Storage | _APP_STORAGE_DO_SPACES_SECRET",
                "defaultValue": "",
                "description": "DigitalOcean spaces secret key. Required when the storage adapter is set to DOSpaces. You can get your secret key from your DigitalOcean console."
            },
            {
                "id": "$$config__app_storage_do_spaces_region",
                "name": "_APP_STORAGE_DO_SPACES_REGION",
                "label": "Storage | _APP_STORAGE_DO_SPACES_REGION",
                "defaultValue": "us-east-1",
                "description": "DigitalOcean spaces region. Required when storage adapter is set to DOSpaces. You can find your region info for your space from DigitalOcean console."
            },
            {
                "id": "$$config__app_storage_do_spaces_bucket",
                "name": "_APP_STORAGE_DO_SPACES_BUCKET",
                "label": "Storage | _APP_STORAGE_DO_SPACES_BUCKET",
                "defaultValue": "",
                "description": "DigitalOcean spaces bucket. Required when storage adapter is set to DOSpaces. You can create spaces in your DigitalOcean console."
            },
            {
                "id": "$$secret__app_storage_backblaze_access_key",
                "name": "_APP_STORAGE_BACKBLAZE_ACCESS_KEY",
                "label": "Storage | _APP_STORAGE_BACKBLAZE_ACCESS_KEY",
                "defaultValue": "",
                "description": "Backblaze access key. Required when the storage adapter is set to Backblaze. Your Backblaze keyID will be your access key. You can get your keyID from your Backblaze console."
            },
            {
                "id": "$$secret__app_storage_backblaze_secret",
                "name": "_APP_STORAGE_BACKBLAZE_SECRET",
                "label": "Storage | _APP_STORAGE_BACKBLAZE_SECRET",
                "defaultValue": "",
                "description": "Backblaze secret key. Required when the storage adapter is set to Backblaze. Your Backblaze applicationKey will be your secret key. You can get your applicationKey from your Backblaze console."
            },
            {
                "id": "$$config__app_storage_backblaze_region",
                "name": "_APP_STORAGE_BACKBLAZE_REGION",
                "label": "Storage | _APP_STORAGE_BACKBLAZE_REGION",
                "defaultValue": "us-west-004",
                "description": "Backblaze region. Required when storage adapter is set to Backblaze. You can find your region info from your Backblaze console."
            },
            {
                "id": "$$config__app_storage_backblaze_bucket",
                "name": "_APP_STORAGE_BACKBLAZE_BUCKET",
                "label": "Storage | _APP_STORAGE_BACKBLAZE_BUCKET",
                "defaultValue": "",
                "description": "Backblaze bucket. Required when storage adapter is set to Backblaze. You can create your bucket from your Backblaze console."
            },
            {
                "id": "$$secret__app_storage_linode_access_key",
                "name": "_APP_STORAGE_LINODE_ACCESS_KEY",
                "label": "Storage | _APP_STORAGE_LINODE_ACCESS_KEY",
                "defaultValue": "",
                "description": "Linode object storage access key. Required when the storage adapter is set to Linode. You can get your access key from your Linode console."
            },
            {
                "id": "$$secret__app_storage_linode_secret",
                "name": "_APP_STORAGE_LINODE_SECRET",
                "label": "Storage | _APP_STORAGE_LINODE_SECRET",
                "defaultValue": "",
                "description": "Linode object storage secret key. Required when the storage adapter is set to Linode. You can get your secret key from your Linode console."
            },
            {
                "id": "$$config__app_storage_linode_region",
                "name": "_APP_STORAGE_LINODE_REGION",
                "label": "Storage | _APP_STORAGE_LINODE_REGION",
                "defaultValue": "eu-central-1",
                "description": "Linode object storage region. Required when storage adapter is set to Linode. You can find your region info from your Linode console."
            },
            {
                "id": "$$config__app_storage_linode_bucket",
                "name": "_APP_STORAGE_LINODE_BUCKET",
                "label": "Storage | _APP_STORAGE_LINODE_BUCKET",
                "defaultValue": "",
                "description": "Linode object storage bucket. Required when storage adapter is set to Linode. You can create buckets in your Linode console."
            },
            {
                "id": "$$secret__app_storage_wasabi_access_key",
                "name": "_APP_STORAGE_WASABI_ACCESS_KEY",
                "label": "Storage | _APP_STORAGE_WASABI_ACCESS_KEY",
                "defaultValue": "",
                "description": "Wasabi access key. Required when the storage adapter is set to Wasabi. You can get your access key from your Wasabi console."
            },
            {
                "id": "$$secret__app_storage_wasabi_secret",
                "name": "_APP_STORAGE_WASABI_SECRET",
                "label": "Storage | _APP_STORAGE_WASABI_SECRET",
                "defaultValue": "",
                "description": "Wasabi secret key. Required when the storage adapter is set to Wasabi. You can get your secret key from your Wasabi console."
            },
            {
                "id": "$$config__app_storage_wasabi_region",
                "name": "_APP_STORAGE_WASABI_REGION",
                "label": "Storage | _APP_STORAGE_WASABI_REGION",
                "defaultValue": "eu-central-1",
                "description": "Wasabi region. Required when storage adapter is set to Wasabi. You can find your region info from your Wasabi console."
            },
            {
                "id": "$$config__app_storage_wasabi_bucket",
                "name": "_APP_STORAGE_WASABI_BUCKET",
                "label": "Storage | _APP_STORAGE_WASABI_BUCKET",
                "defaultValue": "",
                "description": "Wasabi bucket. Required when storage adapter is set to Wasabi. You can create buckets in your Wasabi console."
            },
            {
                "id": "$$config__app_functions_size_limit",
                "name": "_APP_FUNCTIONS_SIZE_LIMIT",
                "label": "Functions | _APP_FUNCTIONS_SIZE_LIMIT",
                "defaultValue": "30000000",
                "description": "The maximum size deployment in bytes. The default value is 30MB."
            },
            {
                "id": "$$config__app_functions_timeout",
                "name": "_APP_FUNCTIONS_TIMEOUT",
                "label": "Functions | _APP_FUNCTIONS_TIMEOUT",
                "defaultValue": "900",
                "description": "The maximum number of seconds allowed as a timeout value when creating a new function. The default value is 900 seconds."
            },
            {
                "id": "$$config__app_functions_build_timeout",
                "name": "_APP_FUNCTIONS_BUILD_TIMEOUT",
                "label": "Functions | _APP_FUNCTIONS_BUILD_TIMEOUT",
                "defaultValue": "900",
                "description": "The maximum number of seconds allowed as a timeout value when building a new function. The default value is 900 seconds."
            },
            {
                "id": "$$config__app_functions_containers",
                "name": "_APP_FUNCTIONS_CONTAINERS",
                "label": "Functions | _APP_FUNCTIONS_CONTAINERS",
                "defaultValue": "10",
                "description": "The maximum number of containers Appwrite is allowed to keep alive in the background for function environments. Running containers allow faster execution time as there is no need to recreate each container every time a function gets executed. The default value is 10."
            },
            {
                "id": "$$config__app_functions_cpus",
                "name": "_APP_FUNCTIONS_CPUS",
                "label": "Functions | _APP_FUNCTIONS_CPUS",
                "defaultValue": "",
                "description": "The maximum number of CPU core a single cloud function is allowed to use. Please note that setting a value higher than available cores will result in a function error, which might result in an error. The default value is empty. When it's empty, CPU limit will be disabled."
            },
            {
                "id": "$$config__app_functions_memory_allocated",
                "name": "_APP_FUNCTIONS_MEMORY",
                "label": "Functions | _APP_FUNCTIONS_MEMORY",
                "defaultValue": "",
                "description": "The maximum amount of memory a single cloud function is allowed to use in megabytes. The default value is empty. When it's empty, memory limit will be disabled."
            },
            {
                "id": "$$config__app_functions_memory_swap",
                "name": "_APP_FUNCTIONS_MEMORY_SWAP",
                "label": "Functions | _APP_FUNCTIONS_MEMORY_SWAP",
                "defaultValue": "",
                "description": "The maximum amount of swap memory a single cloud function is allowed to use in megabytes. The default value is empty. When it's empty, swap memory limit will be disabled."
            },
            {
                "id": "$$config__app_functions_runtimes",
                "name": "_APP_FUNCTIONS_RUNTIMES",
                "label": "Functions | _APP_FUNCTIONS_RUNTIMES",
                "defaultValue": "node-18.0",
                "description": "This option allows you to limit the available environments for cloud functions. This option is very useful for low-cost servers to safe disk space.\nTo enable/activate this option, pass a list of allowed environments separated by a comma.\nCurrently, supported environments are: node-14.5, node-16.0, node-18.0, php-8.0, php-8.1, ruby-3.0, ruby-3.1, python-3.8, python-3.9, python-3.10, deno-1.21, deno-1.24, dart-2.15, dart-2.16, dart-2.17, dotnet-3.1, dotnet-6.0, java-8.0, java-11.0, java-17.0, java-18.0, swift-5.5, kotlin-1.6, cpp-17.0"
            },
            {
                "id": "$$secret__app_executor_secret",
                "name": "_APP_EXECUTOR_SECRET",
                "label": "Functions | _APP_EXECUTOR_SECRET",
                "defaultValue": "$$generate_hex(16)",
                "description": "The secret key used by Appwrite to communicate with the function executor."
            },
            {
                "id": "$$config__app_executor_host",
                "name": "_APP_EXECUTOR_HOST",
                "label": "",
                "defaultValue": "http://$$id-executor/v1",
                "description": ""
            },
            {
                "id": "$$config__app_logging_provider",
                "name": "_APP_LOGGING_PROVIDER",
                "label": "General | _APP_LOGGING_PROVIDER",
                "defaultValue": "",
                "description": "This variable allows you to enable logging errors to 3rd party providers. This value is empty by default, to enable the logger set the value to one of 'sentry', 'raygun', 'appsignal', 'logowl'"
            },
            {
                "id": "$$config__app_logging_config",
                "name": "_APP_LOGGING_CONFIG",
                "label": "General | _APP_LOGGING_CONFIG",
                "defaultValue": "",
                "description": "This variable configures authentication to 3rd party error logging providers. If using Sentry, this should be 'SENTRY_API_KEY;SENTRY_APP_ID'. If using Raygun, this should be Raygun API key. If using AppSignal, this should be AppSignal API key. If using LogOwl, this should be LogOwl Service Ticket."
            },
            {
                "id": "$$config__app_statsd_host",
                "name": "_APP_STATSD_HOST",
                "label": "",
                "defaultValue": "$$id-telegraf",
                "description": ""
            },
            {
                "id": "$$config__app_statsd_port",
                "name": "_APP_STATSD_PORT",
                "label": "StatsD | _APP_STATSD_PORT",
                "defaultValue": "8125",
                "description": "StatsD server TCP port."
            },
            {
                "id": "$$config__app_maintenance_interval",
                "name": "_APP_MAINTENANCE_INTERVAL",
                "label": "Functions | _APP_MAINTENANCE_INTERVAL",
                "defaultValue": "86400",
                "description": "Interval value containing the number of seconds that the Appwrite maintenance process should wait before executing system cleanups and optimizations. The default value is 86400 seconds (1 day)."
            },
            {
                "id": "$$config__app_maintenance_retention_execution",
                "name": "_APP_MAINTENANCE_RETENTION_EXECUTION",
                "label": "Functions | _APP_MAINTENANCE_RETENTION_EXECUTION",
                "defaultValue": "1209600",
                "description": "The maximum duration (in seconds) upto which to retain execution logs. The default value is 1209600 seconds (14 days)."
            },
            {
                "id": "$$config__app_maintenance_retention_cache",
                "name": "_APP_MAINTENANCE_RETENTION_CACHE",
                "label": "Functions | _APP_MAINTENANCE_RETENTION_CACHE",
                "defaultValue": "2592000",
                "description": "The maximum duration (in seconds) upto which to retain cached files. The default value is 2592000 seconds (30 days)."
            },
            {
                "id": "$$config__app_maintenance_retention_abuse",
                "name": "_APP_MAINTENANCE_RETENTION_ABUSE",
                "label": "Functions | _APP_MAINTENANCE_RETENTION_ABUSE",
                "defaultValue": "86400",
                "description": "The maximum duration (in seconds) upto which to retain abuse logs. The default value is 86400 seconds (1 day)."
            },
            {
                "id": "$$config__app_maintenance_retention_audit",
                "name": "_APP_MAINTENANCE_RETENTION_AUDIT",
                "label": "Functions | _APP_MAINTENANCE_RETENTION_AUDIT",
                "defaultValue": "1209600",
                "description": "The maximum duration (in seconds) upto which to retain audit logs. The default value is 1209600 seconds (14 days)."
            },
            {
                "id": "$$config__app_sms_provider",
                "name": "_APP_SMS_PROVIDER",
                "label": "Phone | _APP_SMS_PROVIDER",
                "defaultValue": "",
                "description": "Provider used for delivering SMS for Phone authentication. Use the following format: 'sms://[USER]:[SECRET]@[PROVIDER]'. Available providers are twilio, text-magic, telesign, msg91, and vonage."
            },
            {
                "id": "$$config__app_sms_from",
                "name": "_APP_SMS_FROM",
                "label": "Phone | _APP_SMS_FROM",
                "defaultValue": "",
                "description": "Phone number used for sending out messages. Must start with a leading '+' and maximum of 15 digits without spaces (+123456789)."
            },
            {
                "id": "$$config__app_version",
                "name": "_APP_VERSION",
                "label": "Version Tag",
                "defaultValue": "1.0.3",
                "description": "Check out their valid tags at https://hub.docker.com/r/appwrite/appwrite/tags"
            },
            {
                "id": "$$config__app_functions_inactive_threshold",
                "name": "_APP_FUNCTIONS_INACTIVE_THRESHOLD",
                "label": "Functions | _APP_FUNCTIONS_INACTIVE_THRESHOLD",
                "defaultValue": "60",
                "description": "The minimum time a function can be inactive before it's container is shutdown and put to sleep. The default value is 60 seconds"
            },
            {
                "id": "$$config_open_runtimes_network",
                "name": "OPEN_RUNTIMES_NETWORK",
                "label": "",
                "defaultValue": "$$generate_network",
                "description": ""
            },
            {
                "id": "$$config_dockerhub_pull_username",
                "name": "DOCKERHUB_PULL_USERNAME",
                "label": "Functions | DOCKERHUB_PULL_USERNAME",
                "defaultValue": "",
                "description": "The username for hub.docker.com. This variable is used to pull images from hub.docker.com."
            },
            {
                "id": "$$secret_dockerhub_pull_password",
                "name": "DOCKERHUB_PULL_PASSWORD",
                "label": "Functions | DOCKERHUB_PULL_PASSWORD",
                "defaultValue": "",
                "description": "The password for hub.docker.com. This variable is used to pull images from hub.docker.com."
            },
            {
                "id": "$$config__app_usage_timeseries_interval",
                "name": "_APP_USAGE_TIMESERIES_INTERVAL",
                "label": "General | _APP_USAGE_TIMESERIES_INTERVAL",
                "defaultValue": "30",
                "description": "Interval value containing the number of seconds that the Appwrite usage process should wait before aggregating stats and syncing it to mariadb from InfluxDB. The default value is 30 seconds."
            },
            {
                "id": "$$config__app_usage_database_interval",
                "name": "_APP_USAGE_DATABASE_INTERVAL",
                "label": "General | _APP_USAGE_DATABASE_INTERVAL",
                "defaultValue": "900",
                "description": "Interval value containing the number of seconds that the Appwrite usage process should wait before aggregating stats from data in Appwrite Database. The default value is 15 minutes."
            }
        ],
        "documentation": "https://appwrite.io/docs"
    }
    ];
    // if (isDev) {
    //     templates = JSON.parse((await fs.readFile('./template.json')).toString())
    // }

    return templates
}
export async function defaultServiceConfigurations({ id, teamId }) {
    const service = await getServiceFromDB({ id, teamId });
    const { destinationDockerId, destinationDocker, type, serviceSecret } = service;

    const network = destinationDockerId && destinationDocker.network;
    const port = getServiceMainPort(type);

    const { workdir } = await createDirectories({ repository: type, buildId: id });

    const image = getServiceImage(type);
    let secrets = [];
    if (serviceSecret.length > 0) {
        serviceSecret.forEach((secret) => {
            secrets.push(`${secret.name}=${secret.value}`);
        });
    }
    return { ...service, network, port, workdir, image, secrets }
}