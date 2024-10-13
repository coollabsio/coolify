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
    'mysql',
    'bitnami/mysql',
    'mysql/mysql-server',
    'mariadb',
    'postgis/postgis',
    'postgres',
    'bitnami/postgresql',
    'supabase/postgres',
    'elestio/postgres',
    'mongo',
    'redis',
    'memcached',
    'couchdb',
    'neo4j',
    'influxdb',
    'clickhouse/clickhouse-server',
];
const SPECIFIC_SERVICES = [
    'quay.io/minio/minio',
    'minio/minio',
    'svhd/logto',
];

// Based on /etc/os-release
const SUPPORTED_OS = [
    'ubuntu debian raspbian',
    'centos fedora rhel ol rocky amzn almalinux',
    'sles opensuse-leap opensuse-tumbleweed',
    'arch',
    'alpine',
];

const SHARED_VARIABLE_TYPES = ['team', 'project', 'environment'];
