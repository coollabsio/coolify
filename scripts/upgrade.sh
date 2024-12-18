#!/bin/bash
## Do not modify this file. You will lose the ability to autoupdate!

VERSION="13"
CDN="https://cdn.coollabs.io/coolify"
LATEST_IMAGE=${1:-latest}
LATEST_HELPER_VERSION=${2:-latest}
DATE=$(date +%Y-%m-%d-%H-%M-%S)

# Check if.env file exists, if exists get BASE_CONFIG_PATH, if not defaults to data/coolify
if [ -f ./.env ]; then
    BASE_CONFIG_PATH=$(grep -w "BASE_CONFIG_PATH" ./.env | cut -d "=" -f 2 | tr -d '"') || true
fi

BASE_CONFIG_PATH=${BASE_CONFIG_PATH:-"/data/coolify"}

LOGFILE="$BASE_CONFIG_PATH"/source/upgrade-${DATE}.log

curl -fsSL $CDN/docker-compose.yml -o "$BASE_CONFIG_PATH"/source/docker-compose.yml
curl -fsSL $CDN/docker-compose.prod.yml -o "$BASE_CONFIG_PATH"/source/docker-compose.prod.yml
curl -fsSL $CDN/.env.production -o "$BASE_CONFIG_PATH"/source/.env.production

# Merge .env and .env.production. New values will be added to .env
awk -F '=' '!seen[$1]++' "$BASE_CONFIG_PATH"/source/.env "$BASE_CONFIG_PATH"/source/.env.production  > "$BASE_CONFIG_PATH"/source/.env.tmp && mv "$BASE_CONFIG_PATH"/source/.env.tmp "$BASE_CONFIG_PATH"/source/.env
# Check if PUSHER_APP_ID or PUSHER_APP_KEY or PUSHER_APP_SECRET is empty in BASE_CONFIG_PATH/source/.env
if grep -q "PUSHER_APP_ID=$" "$BASE_CONFIG_PATH"/source/.env; then
    sed -i "s|PUSHER_APP_ID=.*|PUSHER_APP_ID=$(openssl rand -hex 32)|g" "$BASE_CONFIG_PATH"/source/.env
fi

if grep -q "PUSHER_APP_KEY=$" "$BASE_CONFIG_PATH"/source/.env; then
    sed -i "s|PUSHER_APP_KEY=.*|PUSHER_APP_KEY=$(openssl rand -hex 32)|g" "$BASE_CONFIG_PATH"/source/.env
fi

if grep -q "PUSHER_APP_SECRET=$" "$BASE_CONFIG_PATH"/source/.env; then
    sed -i "s|PUSHER_APP_SECRET=.*|PUSHER_APP_SECRET=$(openssl rand -hex 32)|g" "$BASE_CONFIG_PATH"/source/.env
fi

# Make sure coolify network exists
# It is created when starting Coolify with docker compose
docker network create --attachable coolify 2>/dev/null
# docker network create --attachable --driver=overlay coolify-overlay 2>/dev/null

if [ -f "$BASE_CONFIG_PATH"/source/docker-compose.custom.yml ]; then
    echo "docker-compose.custom.yml detected." >> $LOGFILE
    docker run -v "$BASE_CONFIG_PATH"/source:"$BASE_CONFIG_PATH"/source -v /var/run/docker.sock:/var/run/docker.sock --rm ghcr.io/coollabsio/coolify-helper:${LATEST_HELPER_VERSION} bash -c "LATEST_IMAGE=${LATEST_IMAGE} docker compose --env-file $BASE_CONFIG_PATH/source/.env -f $BASE_CONFIG_PATH/source/docker-compose.yml -f $BASE_CONFIG_PATH/source/docker-compose.prod.yml -f $BASE_CONFIG_PATH/source/docker-compose.custom.yml up -d --remove-orphans --force-recreate --wait --wait-timeout 60" >> "$LOGFILE" 2>&1
else
    docker run -v "$BASE_CONFIG_PATH"/source:"$BASE_CONFIG_PATH"/source -v /var/run/docker.sock:/var/run/docker.sock --rm ghcr.io/coollabsio/coolify-helper:${LATEST_HELPER_VERSION} bash -c "LATEST_IMAGE=${LATEST_IMAGE} docker compose --env-file $BASE_CONFIG_PATH/source/.env -f $BASE_CONFIG_PATH/source/docker-compose.yml -f $BASE_CONFIG_PATH/source/docker-compose.prod.yml up -d --remove-orphans --force-recreate --wait --wait-timeout 60" >> "$LOGFILE" 2>&1
fi
