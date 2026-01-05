<?php

const REDACTED = '<REDACTED>';
const DATABASE_TYPES = ['postgresql', 'redis', 'mongodb', 'mysql', 'mariadb', 'keydb', 'dragonfly', 'clickhouse'];
const VALID_CRON_STRINGS = [
    'every_minute' => '* * * * *',
    'hourly' => '0 * * * *',
    'daily' => '0 0 * * *',
    'weekly' => '0 0 * * 0',
    'monthly' => '0 0 1 * *',
    'yearly' => '0 0 1 1 *',
    '@hourly' => '0 * * * *',
    '@daily' => '0 0 * * *',
    '@weekly' => '0 0 * * 0',
    '@monthly' => '0 0 1 * *',
    '@yearly' => '0 0 1 1 *',
];
const RESTART_MODE = 'unless-stopped';

const DATABASE_DOCKER_IMAGES = [
    'bitnami/mariadb',
    'bitnami/mongodb',
    'bitnami/redis',
    'bitnamilegacy/mariadb',
    'bitnamilegacy/mongodb',
    'bitnamilegacy/redis',
    'bitnamisecure/mariadb',
    'bitnamisecure/mongodb',
    'bitnamisecure/redis',
    'mysql',
    'bitnami/mysql',
    'bitnamilegacy/mysql',
    'bitnamisecure/mysql',
    'mysql/mysql-server',
    'mariadb',
    'postgis/postgis',
    'postgres',
    'bitnami/postgresql',
    'bitnamilegacy/postgresql',
    'bitnamisecure/postgresql',
    'supabase/postgres',
    'elestio/postgres',
    'mongo',
    'redis',
    'memcached',
    'couchdb',
    'neo4j',
    'influxdb',
    'clickhouse/clickhouse-server',
    'timescaledb/timescaledb',
    'timescaledb',  // Matches timescale/timescaledb
    'timescaledb-ha',  // Matches timescale/timescaledb-ha
    'pgvector/pgvector',
];
const SPECIFIC_SERVICES = [
    'quay.io/minio/minio',
    'minio/minio',
    'ghcr.io/coollabsio/minio',
    'coollabsio/minio',
    'svhd/logto',
    'dxflrs/garage',
];

// Based on /etc/os-release
const SUPPORTED_OS = [
    'ubuntu debian raspbian pop',
    'centos fedora rhel ol rocky amzn almalinux',
    'sles opensuse-leap opensuse-tumbleweed',
    'arch',
    'alpine',
];

const NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK = [
    'pgadmin',
    'postgresus',
    'redis-insight',
];
const NEEDS_TO_DISABLE_GZIP = [
    'beszel' => ['beszel'],
];
const NEEDS_TO_DISABLE_STRIPPREFIX = [
    'appwrite' => ['appwrite', 'appwrite-console', 'appwrite-realtime'],
];
const SHARED_VARIABLE_TYPES = ['team', 'project', 'environment'];
