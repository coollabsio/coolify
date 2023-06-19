#!/bin/bash
## Do not modify this file. You will lost the ability to autoupdate!

###########
## Always run "php artisan app:sync-to-bunny-cdn --env=secrets" if you update this file.
###########

VERSION="1.0.0"
CDN="https://cdn.coollabs.io/coolify"

curl -fsSL $CDN/docker-compose.yml -o /data/coolify/source/docker-compose.yml
curl -fsSL $CDN/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml
curl -fsSL $CDN/.env.production -o /data/coolify/source/.env.production

# Merge .env and .env.production. New values will be added to .env
sort -u -t '=' -k 1,1 /data/coolify/source/.env /data/coolify/source/.env.production | sed '/^$/d' > /data/coolify/source/.env.temp && mv /data/coolify/source/.env.temp /data/coolify/source/.env

docker run --pull always -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock --rm ghcr.io/coollabsio/coolify-builder bash -c "LATEST_IMAGE=${1:-} docker compose --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml up -d --pull always --force-recreate"