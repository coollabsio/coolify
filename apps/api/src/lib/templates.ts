export default [
    {
        "templateVersion": "1.0.0",
        "serviceDefaultVersion": "0.198.1",
        "name": "n8n",
        "displayName": "n8n.io",
        "description": "n8n is a free and open node based Workflow Automation Tool.",
        "services": {
            "$$id": {
                "name": "N8n",
                "documentation": "Taken from https://hub.docker.com/r/n8nio/n8n",
                "depends_on": [],
                "image": "n8nio/n8n:$$core_version",
                "volumes": [
                    "$$id-data:/root/.n8n",
                    "$$id-data-write:/files",
                    "/var/run/docker.sock:/var/run/docker.sock"
                ],
                "environment": [
                    "WEBHOOK_URL=$$config_webhook_url"
                ],
                "ports": [
                    "5678"
                ]
            }
        },
        "variables": [
            {
                "id": "$$config_webhook_url",
                "name": "WEBHOOK_URL",
                "label": "Webhook URL",
                "defaultValue": "$$generate_fqdn",
                "description": "",
            }]
    },
    {
        "templateVersion": "1.0.0",
        "serviceDefaultVersion": "stable",
        "name": "plausibleanalytics",
        "displayName": "PlausibleAnalytics",
        "description": "Plausible is a lightweight and open-source website analytics tool.",
        "services": {
            "$$id": {
                "name": "Plausible Analytics",
                "documentation": "Taken from https://plausible.io/",
                "command": 'sh -c "sleep 10 && /entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh db init-admin && /entrypoint.sh run"',
                "depends_on": [
                    "$$id-postgresql",
                    "$$id-clickhouse"
                ],
                "image": "plausible/analytics:$$core_version",
                "environment": [
                    "ADMIN_USER_EMAIL=$$config_admin_user_email",
                    "ADMIN_USER_NAME=$$config_admin_user_name",
                    "ADMIN_USER_PWD=$$secret_admin_user_pwd",
                    "BASE_URL=$$config_base_url",
                    "SECRET_KEY_BASE=$$secret_secret_key_base",
                    "DISABLE_AUTH=$$config_disable_auth",
                    "DISABLE_REGISTRATION=$$config_disable_registration",
                    "DATABASE_URL=$$secret_database_url",
                    "CLICKHOUSE_DATABASE_URL=$$secret_clickhouse_database_url",
                ],
                "ports": [
                    "8000"
                ],
            },
            "$$id-postgresql": {
                "name": "PostgreSQL",
                "documentation": "Taken from https://plausible.io/",
                "image": "bitnami/postgresql:13.2.0",
                "volumes": [
                    '$$id-postgresql-data:/bitnami/postgresql/',
                ],
                "environment": [
                    "POSTGRESQL_PASSWORD=$$secret_postgresql_password",
                    "POSTGRESQL_USERNAME=$$config_postgresql_username",
                    "POSTGRESQL_DATABASE=$$config_postgresql_database",
                ],

            },
            "$$id-clickhouse": {
                "name": "Clickhouse",
                "documentation": "Taken from https://plausible.io/",
                "build": {
                    context: "$$workdir",
                    dockerfile: "Dockerfile.$$id-clickhouse"
                },
                "volumes": [
                    '$$id-clickhouse-data:/var/lib/clickhouse',
                ],
                "image": "yandex/clickhouse-server:21.3.2.5",
                "ulimits": {
                    "nofile": {
                        "soft": 262144,
                        "hard": 262144
                    }
                },
                "extras": {
                    "files": [
                        {
                            source: "$$workdir/clickhouse-config.xml",
                            destination: '/etc/clickhouse-server/users.d/logging.xml',
                            content: '<yandex><logger><level>warning</level><console>true</console></logger><query_thread_log remove="remove"/><query_log remove="remove"/><text_log remove="remove"/><trace_log remove="remove"/><metric_log remove="remove"/><asynchronous_metric_log remove="remove"/><session_log remove="remove"/><part_log remove="remove"/></yandex>'
                        },
                        {
                            source: "$$workdir/clickhouse-user-config.xml",
                            destination: '/etc/clickhouse-server/config.d/logging.xml',
                            content: '<yandex><profiles><default><log_queries>0</log_queries><log_query_threads>0</log_query_threads></default></profiles></yandex>'
                        },
                        {
                            source: "$$workdir/init.query",
                            destination: '/docker-entrypoint-initdb.d/init.query',
                            content: 'CREATE DATABASE IF NOT EXISTS plausible;'
                        },
                        {
                            source: "$$workdir/init-db.sh",
                            destination: '/docker-entrypoint-initdb.d/init-db.sh',
                            content: 'clickhouse client --queries-file /docker-entrypoint-initdb.d/init.query'
                        }
                    ]
                }
            },

        },
        "variables": [
            {
                "id": "$$config_base_url",
                "name": "BASE_URL",
                "label": "Base URL",
                "defaultValue": "$$generate_fqdn",
                "description": "You must set this to the FQDN of the Plausible Analytics instance. This is used to generate the links to the Plausible Analytics instance.",
            },
            {
                "id": "$$secret_database_url",
                "name": "DATABASE_URL",
                "label": "Database URL for PostgreSQL",
                "defaultValue": "postgresql://$$config_postgresql_username:$$secret_postgresql_password@$$id-postgresql:5432/$$config_postgresql_database",
                "description": "",
            },
            {
                "id": "$$secret_clickhouse_database_url",
                "name": "CLICKHOUSE_DATABASE_URL",
                "label": "Database URL for Clickhouse",
                "defaultValue": "http://$$id-clickhouse:8123/plausible",
                "description": "",
            },
            {
                "id": "$$config_admin_user_email",
                "name": "ADMIN_USER_EMAIL",
                "label": "Admin Email Address",
                "defaultValue": "admin@example.com",
                "description": "This is the admin email. Please change it.",
            },
            {
                "id": "$$config_admin_user_name",
                "name": "ADMIN_USER_NAME",
                "label": "Admin User Name",
                "defaultValue": "$$generate_username",
                "description": "This is the admin username. Please change it.",
            },
            {
                "id": "$$secret_admin_user_pwd",
                "name": "ADMIN_USER_PWD",
                "label": "Admin User Password",
                "defaultValue": "$$generate_password",
                "description": "This is the admin password. Please change it.",
                "extras": {
                    "isVisibleOnUI": true
                }
            },
            {
                "id": "$$secret_secret_key_base",
                "name": "SECRET_KEY_BASE",
                "label": "Secret Key Base",
                "defaultValue": "$$generate_passphrase",
                "description": "",
                "extras": {
                    "length": 64
                }
            },
            {
                "id": "$$config_disable_auth",
                "name": "DISABLE_AUTH",
                "label": "Disable Authentication",
                "defaultValue": "false",
                "description": "",
            },
            {
                "id": "$$config_disable_registration",
                "name": "DISABLE_REGISTRATION",
                "label": "Disable Registration",
                "defaultValue": "true",
                "description": "",
            },
            {
                "id": "$$config_postgresql_username",
                "name": "POSTGRESQL_USERNAME",
                "label": "PostgreSQL Username",
                "defaultValue": "postgresql",
                "description": "",
            },
            {
                "id": "$$secret_postgresql_password",
                "name": "POSTGRESQL_PASSWORD",
                "label": "PostgreSQL Password",
                "defaultValue": "$$generate_password",
                "description": "",
            }
            ,
            {
                "id": "$$config_postgresql_database",
                "name": "POSTGRESQL_DATABASE",
                "label": "PostgreSQL Database",
                "defaultValue": "plausible",
                "description": "",
            },
            {
                "id": "$$config_scriptName",
                "name": "SCRIPT_NAME",
                "label": "Custom Script Name",
                "defaultValue": "plausible.js",
                "description": "This is the default script name.",
            },
        ]
    }
]
