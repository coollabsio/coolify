<?php

const DATABASE_TYPES = ['postgresql'];
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
    'mysql',
    'mariadb',
    'postgres',
    'mongo',
    'redis',
    'memcached',
    'couchdb',
    'neo4j',
    'influxdb',
    'clickhouse'
];
