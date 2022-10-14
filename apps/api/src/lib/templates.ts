export default [
    {
        "templateVersion": "1.0.0",
        "serviceDefaultVersion": "0.198.1",
        "name": "n8n",
        "displayName": "n8n.io",
        "isOfficial": true,
        "description": "n8n is a free and open node based Workflow Automation Tool.",
        "services": {
            "$$id": {
                "documentation": "Taken from https://hub.docker.com/r/n8nio/n8n",
                "depends_on": [],
                "image": "n8nio/n8n:$$core_version",
                "volumes": [
                    "$$id-data:/root/.n8n",
                    "$$id-data-write:/files",
                    "/var/run/docker.sock:/var/run/docker.sock"
                ],
                "environment": [
                    "WEBHOOK_URL=$$fqdn"
                ],
                "ports": [
                    "5678"
                ]
            }
        },
        "variables": []
    },
    {
        "templateVersion": "1.0.0",
        "serviceDefaultVersion": "stable",
        "name": "plausibleanalytics",
        "displayName": "PlausibleAnalytics",
        "isOfficial": true,
        "description": "Plausible is a lightweight and open-source website analytics tool.",
        "services": {
            "$$id": {
                "documentation": "Taken from https://plausible.io/",
                "command": ['sh -c "sleep 10 && /entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh db init-admin && /entrypoint.sh run"'],
                "depends_on": [
                    "$$id-postgresql",
                    "$$id-clickhouse"
                ],
                "image": "plausible/analytics:$$core_version",
                "environment": [
                    "ADMIN_USER_EMAIL=$$secret_email",
                    "ADMIN_USER_NAME=$$secret_name",
                    "ADMIN_USER_PASSWORD=$$secret_password",
                    "BASE_URL=$$fqdn",
                    "SECRET_KEY_BASE=$$secret_key_base",
                    "DISABLE_AUTH=$$secret_disable_auth",
                    "DISABLE_REGISTRATION=$$secret_disable_registration",
                    "DATABASE_URL=postgresql://$$secret_postgresql_username:$$secret_postgresql_password@$$id-postgresql:5432/$$secret_postgresql_database",
                    "CLICKHOUSE_DATABASE_URL=http://$$id-clickhouse:8123/plausible",
                ],
                "ports": [
                    "8000"
                ],
            },
            "$$id-postgresql": {
                "documentation": "Taken from https://plausible.io/",
                "image": "bitnami/postgresql:13.2.0",
                "environment": [
                    "POSTGRESQL_PASSWORD=$$secret_postgresql_password",
                    "POSTGRESQL_USERNAME=$$secret_postgresql_username",
                    "POSTGRESQL_DATABASE=$$secret_postgresql_database",
                ],

            },
            "$$id-clickhouse": {
                "documentation": "Taken from https://plausible.io/",
                "build": "$$workdir",
                "image": "yandex/clickhouse-server:21.3.2.5",
                "ulimits": {
                    "nofile": {
                        "soft": 262144,
                        "hard": 262144
                    }
                },
                "extras": {
                    "files:": [
                        {
                            location: '$$workdir/clickhouse-config.xml',
                            content: '<yandex><logger><level>warning</level><console>true</console></logger><query_thread_log remove="remove"/><query_log remove="remove"/><text_log remove="remove"/><trace_log remove="remove"/><metric_log remove="remove"/><asynchronous_metric_log remove="remove"/><session_log remove="remove"/><part_log remove="remove"/></yandex>'
                        },
                        {
                            location: '$$workdir/clickhouse-user-config.xml',
                            content: '<yandex><profiles><default><log_queries>0</log_queries><log_query_threads>0</log_query_threads></default></profiles></yandex>'
                        },
                        {
                            location: '$$workdir/init.query',
                            content: 'CREATE DATABASE IF NOT EXISTS plausible;'
                        },
                        {
                            location: '$$workdir/init-db.sh',
                            content: 'clickhouse client --queries-file /docker-entrypoint-initdb.d/init.query'
                        }
                    ]
                }
            },

        },
        "variables": [
            {
                "id": "$$secret_email",
                "label": "Admin Email",
                "defaultValue": "admin@example.com",
                "description": "This is the admin email. Please change it.",
                "validRegex": /^([^\s^\/])+$/
            },
            {
                "id": "$$secret_name",
                "label": "Admin Name",
                "defaultValue": "$$generate_username",
                "description": "This is the admin username. Please change it.",
                "validRegex": /^([^\s^\/])+$/
            },
            {
                "id": "$$secret_password",
                "label": "Admin Password",
                "defaultValue":"$$generate_password",
                "description": "This is the admin password. Please change it.",
                "validRegex": /^([^\s^\/])+$/
            },
            {
                "id": "$$secret_secret_key_base",
                "label": "Secret Key Base",
                "defaultValue":"$$generate_passphrase",
                "description": "",
                "validRegex": /^([^\s^\/])+$/
            },
            {
                "id": "$$secret_disable_auth",
                "label": "Disable Auth",
                "defaultValue": "false",
                "description": "",
                "validRegex": /^([^\s^\/])+$/
            },
            {
                "id": "$$secret_disable_registration",
                "label": "Disable Registration",
                "defaultValue": "true",
                "description": "",
                "validRegex": /^([^\s^\/])+$/
            },
            {
                "id": "$$secret_postgresql_username",
                "label": "PostgreSQL Username",
                "defaultValue": "postgresql",
                "description": "",
                "validRegex": /^([^\s^\/])+$/
            },
            {
                "id": "$$secret_postgresql_password",
                "label": "PostgreSQL Password",
                "defaultValue": "$$generate_password",
                "description": "",
                "validRegex": /^([^\s^\/])+$/
            }
            ,
            {
                "id": "$$secret_postgresql_database",
                "label": "PostgreSQL Database",
                "defaultValue": "plausible",
                "description": "",
                "validRegex": /^([^\s^\/])+$/
            }
        ]
    }
]
