#!/bin/bash
## Do not modify this file. You will lose the ability to autoupdate!

VERSION="1.0.5"
CDN="https://cdn.coollabs.io/coolify"

curl -fsSL $CDN/docker-compose.yml -o /data/coolify/source/docker-compose.yml
curl -fsSL $CDN/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml
curl -fsSL $CDN/.env.production -o /data/coolify/source/.env.production

# Merge .env and .env.production. New values will be added to .env
sort -u -t '=' -k 1,1 /data/coolify/source/.env /data/coolify/source/.env.production | sed '/^$/d' >/data/coolify/source/.env.temp && mv /data/coolify/source/.env.temp /data/coolify/source/.env

# Check if PUSHER_APP_ID or PUSHER_APP_KEY or PUSHER_APP_SECRET is empty in /data/coolify/source/.env
if grep -q "PUSHER_APP_ID=$" /data/coolify/source/.env; then
    sed -i "s|PUSHER_APP_ID=.*|PUSHER_APP_ID=$(openssl rand -hex 32)|g" /data/coolify/source/.env
fi

if grep -q "PUSHER_APP_KEY=$" /data/coolify/source/.env; then
    sed -i "s|PUSHER_APP_KEY=.*|PUSHER_APP_KEY=$(openssl rand -hex 32)|g" /data/coolify/source/.env
fi

if grep -q "PUSHER_APP_SECRET=$" /data/coolify/source/.env; then
    sed -i "s|PUSHER_APP_SECRET=.*|PUSHER_APP_SECRET=$(openssl rand -hex 32)|g" /data/coolify/source/.env
fi

# Make sure coolify network exists
docker network create --attachable coolify 2>/dev/null
# docker network create --attachable --driver=overlay coolify-overlay 2>/dev/null

if [ -f /data/coolify/source/docker-compose.custom.yml ]; then
    echo "docker-compose.custom.yml detected."
    docker run -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock --rm ghcr.io/coollabsio/coolify-helper bash -c "LATEST_IMAGE=${1:-} docker compose --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml -f /data/coolify/source/docker-compose.custom.yml up -d --remove-orphans --force-recreate"
else
    docker run -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock --rm ghcr.io/coollabsio/coolify-helper bash -c "LATEST_IMAGE=${1:-} docker compose --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml up -d --remove-orphans --force-recreate"
fi
