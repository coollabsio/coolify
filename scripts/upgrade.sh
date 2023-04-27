#!/bin/bash
## Do not modify this file. You will lost the ability to autoupdate!

export APP_TAG=$1
docker compose --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml up -d --pull always --remove-orphans --force-recreate
