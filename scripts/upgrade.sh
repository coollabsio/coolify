#!/bin/bash
## Do not modify this file. You will lose the ability to autoupdate!

# shellcheck disable=SC2034
VERSION="1.0.5"
CDN="https://cdn.coollabs.io/coolify"

# Check if.env file exists, if exists get COOLIFY_ROOT_PATH, if not defaults to data/coolify
if [ -f ./.env ]; then
    COOLIFY_ROOT_PATH=$(grep -w "COOLIFY_ROOT_PATH" ./.env | cut -d "=" -f 2 | tr -d '"') || true
fi

COOLIFY_ROOT_PATH=${COOLIFY_ROOT_PATH:-"/data/coolify"}

curl -fsSL $CDN/docker-compose.yml -o "$COOLIFY_ROOT_PATH"/source/docker-compose.yml
curl -fsSL $CDN/docker-compose.prod.yml -o "$COOLIFY_ROOT_PATH"/source/docker-compose.prod.yml
curl -fsSL $CDN/.env.production -o "$COOLIFY_ROOT_PATH"/source/.env.production

# Merge .env and .env.production. New values will be added to .env
sort -u -t '=' -k 1,1 "$COOLIFY_ROOT_PATH"/source/.env "$COOLIFY_ROOT_PATH"/source/.env.production | sed '/^$/d' >"$COOLIFY_ROOT_PATH"/source/.env.temp && mv "$COOLIFY_ROOT_PATH"/source/.env.temp "$COOLIFY_ROOT_PATH"/source/.env

# Check if PUSHER_APP_ID or PUSHER_APP_KEY or PUSHER_APP_SECRET is empty in "$COOLIFY_ROOT_PATH"/source/.env
if grep -q "PUSHER_APP_ID=$" "$COOLIFY_ROOT_PATH"/source/.env; then
    sed -i "s|PUSHER_APP_ID=.*|PUSHER_APP_ID=$(openssl rand -hex 32)|g" "$COOLIFY_ROOT_PATH"/source/.env
fi

if grep -q "PUSHER_APP_KEY=$" "$COOLIFY_ROOT_PATH"/source/.env; then
    sed -i "s|PUSHER_APP_KEY=.*|PUSHER_APP_KEY=$(openssl rand -hex 32)|g" "$COOLIFY_ROOT_PATH"/source/.env
fi

if grep -q "PUSHER_APP_SECRET=$" "$COOLIFY_ROOT_PATH"/source/.env; then
    sed -i "s|PUSHER_APP_SECRET=.*|PUSHER_APP_SECRET=$(openssl rand -hex 32)|g" "$COOLIFY_ROOT_PATH"/source/.env
fi

# Make sure coolify network exists
docker network create --attachable coolify 2>/dev/null
# docker network create --attachable --driver=overlay coolify-overlay 2>/dev/null

if [ -f "$COOLIFY_ROOT_PATH"/source/docker-compose.custom.yml ]; then
    echo "docker-compose.custom.yml detected."
    docker run -v "$COOLIFY_ROOT_PATH"/source:"$COOLIFY_ROOT_PATH"/source -v /var/run/docker.sock:/var/run/docker.sock --rm ghcr.io/coollabsio/coolify-helper bash -c "LATEST_IMAGE=${1:-} docker compose --env-file $COOLIFY_ROOT_PATH/source/.env -f $COOLIFY_ROOT_PATH/source/docker-compose.yml -f $COOLIFY_ROOT_PATH/source/docker-compose.prod.yml -f $COOLIFY_ROOT_PATH/source/docker-compose.custom.yml up -d --remove-orphans --force-recreate"
else
    docker run -v "$COOLIFY_ROOT_PATH"/source:"$COOLIFY_ROOT_PATH"/source -v /var/run/docker.sock:/var/run/docker.sock --rm ghcr.io/coollabsio/coolify-helper bash -c "LATEST_IMAGE=${1:-} docker compose --env-file $COOLIFY_ROOT_PATH/source/.env -f $COOLIFY_ROOT_PATH/source/docker-compose.yml -f $COOLIFY_ROOT_PATH/source/docker-compose.prod.yml up -d --remove-orphans --force-recreate"
fi
