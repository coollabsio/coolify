#!/bin/bash
## Do not modify this file. You will lost the ability to installation and autoupdate!

###########
## Always run "php artisan app:sync-to-bunny-cdn --env=secrets" or "scripts/run sync-bunny" if you update this file.
###########

VERSION="1.0.0"
DOCKER_VERSION="23.0"

CDN="https://coolify-cdn.b-cdn.net/files"
OS_TYPE=$(cat /etc/os-release | grep -w "ID" | cut -d "=" -f 2 | tr -d '"')
OS_VERSION=$(cat /etc/os-release | grep -w "VERSION_ID" | cut -d "=" -f 2 | tr -d '"')
LATEST_VERSION=$(curl --silent https://coolify-cdn.b-cdn.net/versions.json | grep -i version | sed -n '2p' | xargs | awk '{print $2}' | tr -d ',')

if [ $EUID != 0 ]; then
    echo "Please run as root"
    exit
fi

if ! [ -x "$(command -v docker)" ]; then
    echo "Docker is not installed. Installing Docker..."
    curl https://releases.rancher.com/install-docker/${DOCKER_VERSION}.sh | sh
    echo "Docker installed successfully"
fi

mkdir -p /data/coolify/deployments
mkdir -p /data/coolify/ssh/keys
mkdir -p /data/coolify/ssh/mux
mkdir -p /data/coolify/source

chown -R 9999:root /data
chmod -R 700 /data

echo "Downloading required files from CDN..."
curl -fsSL $CDN/docker-compose.yml -o /data/coolify/source/docker-compose.yml
curl -fsSL $CDN/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml
curl -fsSL $CDN/.env.production -o /data/coolify/source/.env.production
curl -fsSL $CDN/upgrade.sh -o /data/coolify/source/upgrade.sh

# Copy .env.example if .env does not exist
if [ ! -f /data/coolify/source/.env ]; then
    cp /data/coolify/source/.env.production /data/coolify/source/.env
    sed -i "s|APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|g" /data/coolify/source/.env
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -base64 32)|g" /data/coolify/source/.env
    sed -i "s|REDIS_PASSWORD=.*|REDIS_PASSWORD=$(openssl rand -base64 32)|g" /data/coolify/source/.env
fi

# Generate an ssh key (ed25519) at /data/coolify/ssh/keys/id.root@host.docker.internal
if [ ! -f /data/coolify/ssh/keys/id.root@host.docker.internal ]; then
    ssh-keygen -t ed25519 -f /data/coolify/ssh/keys/id.root@host.docker.internal -q -N "" -C root@coolify
    chown 9999 /data/coolify/ssh/keys/id.root@host.docker.internal
fi

addSshKey() {
    cat /data/coolify/ssh/keys/id.root@host.docker.internal.pub >> ~/.ssh/authorized_keys
    chmod 600 ~/.ssh/authorized_keys
}

if [ ! -f ~/.ssh/authorized_keys ]; then
    mkdir -p ~/.ssh
    chmod 700 ~/.ssh
    touch ~/.ssh/authorized_keys
    addSshKey
fi

if [ -z "$(grep -w "root@coolify" ~/.ssh/authorized_keys)" ]; then
    addSshKey
fi

bash /data/coolify/source/upgrade.sh ${LATEST_VERSION:-latest}
