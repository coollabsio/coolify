<?php

const REDACTED = '<REDACTED>';
const DATABASE_TYPES = ['postgresql', 'redis', 'mongodb', 'mysql', 'mariadb'];
const VALID_CRON_STRINGS = [
    'every_minute' => '* * * * *',
    'hourly' => '0 * * * *',
    'daily' => '0 0 * * *',
    'weekly' => '0 0 * * 0',
    'monthly' => '0 0 1 * *',
    'yearly' => '0 0 1 1 *',
];
const RESTART_MODE = 'unless-stopped';

const DATABASE_DOCKER_IMAGES = [
    'bitnami/mariadb',
    'bitnami/mongodb',
    'bitnami/mysql',
    'bitnami/postgresql',
    'bitnami/redis',
    'mysql',
    'mariadb',
    'postgres',
    'mongo',
    'redis',
    'memcached',
    'couchdb',
    'neo4j',
    'influxdb',
    'clickhouse/clickhouse-server',
    'supabase/postgres'
];
const SPECIFIC_SERVICES = [
    'quay.io/minio/minio',
];

// Based on /etc/os-release
const SUPPORTED_OS = [
    'ubuntu debian raspbian',
    'centos fedora rhel ol rocky',
    'sles opensuse-leap opensuse-tumbleweed'
];

const SHARED_VARIABLE_TYPES = ['team', 'project', 'environment'];
