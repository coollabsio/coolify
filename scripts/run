#!/usr/bin/env bash

# Inspired on https://github.com/adriancooney/Taskfile
#
# Install an alias, to be able to simply execute `run`
# echo 'alias run=./scripts/run' >> ~/.aliases
#
# Define Docker Compose command prefix...
set -e

if [ $? == 0 ]; then
    DOCKER_COMPOSE="docker compose"
else
    DOCKER_COMPOSE="docker-compose"
fi

function help {
    echo "$0 <task> <args>"
    echo "Tasks:"
    compgen -A function | cat -n
}

function logs {
    docker exec -t coolify tail -f storage/logs/laravel.log
}
function test {
    docker exec -t coolify php artisan test --testsuite=Feature -p
}

function sync:bunny {
    php artisan sync:bunny --env=secrets
}

function db:reset {
    bash spin exec -u www-data coolify php artisan migrate:fresh --seed
}
function db:reset-prod {
    bash spin exec -u www-data coolify php artisan migrate:fresh --force --seed --seeder=ProductionSeeder ||
        php artisan migrate:fresh --force --seed --seeder=ProductionSeeder
}
function coolify {
    bash spin exec -u www-data coolify sh
}

function coolify:root {
    bash spin exec coolify sh
}
function coolify:proxy {
    docker exec -ti coolify-proxy sh
}

function redis {
    docker exec -ti coolify-redis redis-cli
}

function vite {
    bash spin exec vite bash
}

function tinker {
    bash spin exec -u www-data coolify php artisan tinker
}

function default {
    help
}

TIMEFORMAT="Task completed in %3lR"
time "${@:-default}"
