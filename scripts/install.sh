#!/bin/bash
if [ "$EUID" -ne 0 ]; then
    echo "Please run as root"
    exit
fi
COOLIFY_VERSION_BRANCH="v4"
OS=$(cat /etc/os-release | grep -w "ID" | cut -d "=" -f 2 | tr -d '"')
VERSION=$(cat /etc/os-release | grep -w "VERSION_ID" | cut -d "=" -f 2 | tr -d '"')

if ! [ -x "$(command -v docker)" ]; then
    echo "Docker is not installed. Installing Docker..."
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    rm get-docker.sh
    echo "Docker installed successfully"
fi

mkdir -p /data/coolify/deployments
mkdir -p /data/coolify/ssh-keys
mkdir -p /data/coolify/proxy
mkdir -p /data/coolify/source

chown -R 9999:root /data
chmod -R 700 /data

if [ ! -z "$(ls -A /data/coolify/source/.gitignore)" ]; then
    git -C /data/coolify/source fetch --all
    git -C /data/coolify/source reset --hard origin/${COOLIFY_VERSION_BRANCH}
else
    git -C /data/coolify/source clone --branch ${COOLIFY_VERSION_BRANCH} https://github.com/coollabsio/coolify .
fi

# Copy .env.example if .env does not exist
if [ ! -f /data/coolify/source/.env ]; then
    cp /data/coolify/source/.env.example /data/coolify/source/.env
    sed -i 's/APP_ENV=.*/APP_ENV=production/g' /data/coolify/source/.env
    sed -i 's/APP_DEBUG=.*/APP_DEBUG=false/g' /data/coolify/source/.env
    sed -i "s|APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|g" /data/coolify/source/.env
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -base64 32)|g" /data/coolify/source/.env
fi

# Generate an ssh key (ed25519) at /data/coolify/ssh-keys/id.root@host.docker.internal
if [ ! -f /data/coolify/ssh-keys/id.root@host.docker.internal ]; then
    ssh-keygen -t ed25519 -f /data/coolify/ssh-keys/id.root@host.docker.internal -q -N "" -C root@coolify
    chown 9999 /data/coolify/ssh-keys/id.root@host.docker.internal
fi

addSshKey() {
    cat /data/coolify/ssh-keys/id.root@host.docker.internal.pub >>~/.ssh/authorized_keys
    chmod 600 ~/.ssh/authorized_keys
}

if [ ! -d ~/.ssh ]; then
    mkdir -p ~/.ssh
    chmod 700 ~/.ssh
    touch ~/.ssh/authorized_keys
    addSshKey
fi
if [ ! -f ~/.ssh/authorized_keys ]; then
    touch ~/.ssh/authorized_keys
    addSshKey
fi
if [ -z "$(grep -w "root@coolify" ~/.ssh/authorized_keys)" ]; then
    addSshKey
fi

docker compose --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml up --pull always
