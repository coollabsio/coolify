#!/bin/bash
## Do not modify this file. You will lost the ability to installation and autoupdate!
VERSION="0.1.0"

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

echo "Downloading required files from GitHub..."
curl -fsSL https://raw.githubusercontent.com/coollabsio/coolify/${COOLIFY_VERSION_BRANCH}/docker-compose.yml -o /data/coolify/source/docker-compose.yml
curl -fsSL https://raw.githubusercontent.com/coollabsio/coolify/${COOLIFY_VERSION_BRANCH}/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml
curl -fsSL https://raw.githubusercontent.com/coollabsio/coolify/${COOLIFY_VERSION_BRANCH}/.env.production -o /data/coolify/source/.env.production
curl -fsSL https://raw.githubusercontent.com/coollabsio/coolify/v4/scripts/upgrade.sh -o /data/coolify/source/upgrade.sh

# Copy .env.example if .env does not exist
if [ ! -f /data/coolify/source/.env ]; then
    cp /data/coolify/source/.env.production /data/coolify/source/.env
    sed -i "s|APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|g" /data/coolify/source/.env
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -base64 32)|g" /data/coolify/source/.env
fi

# Generate an ssh key (ed25519) at /data/coolify/ssh-keys/id.root@host.docker.internal
if [ ! -f /data/coolify/ssh-keys/id.root@host.docker.internal ]; then
    # Add date to the end of the key to prevent collisions
    ssh-keygen -t ed25519 -f /data/coolify/ssh-keys/id.root@host.docker.internal -q -N "" -C root@coolify
    chown 9999 /data/coolify/ssh-keys/id.root@host.docker.internal
fi

addSshKey() {
    cat /data/coolify/ssh-keys/id.root@host.docker.internal.pub >>~/.ssh/authorized_keys
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

bash /data/coolify/source/upgrade.sh
