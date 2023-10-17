#!/bin/bash
## Do not modify this file. You will lose the ability to install and auto-update!

###########
## Always run "php artisan app:sync-to-bunny-cdn --env=secrets" or "scripts/run sync-bunny" if you update this file.
###########

VERSION="1.0.0"
DOCKER_VERSION="24.0"

CDN="https://cdn.coollabs.io/coolify"
OS_TYPE=$(cat /etc/os-release | grep -w "ID" | cut -d "=" -f 2 | tr -d '"')
OS_VERSION=$(cat /etc/os-release | grep -w "VERSION_ID" | cut -d "=" -f 2 | tr -d '"')
LATEST_VERSION=$(curl --silent $CDN/versions.json | grep -i version | sed -n '2p' | xargs | awk '{print $2}' | tr -d ',')
DATE=$(date +"%Y%m%d-%H%M%S")

if [ $EUID != 0 ]; then
    echo "Please run as root"
    exit
fi
if [ $OS_TYPE != "ubuntu" ] && [ $OS_TYPE != "debian" ] && [ $OS_TYPE != "raspbian" ]; then
    echo "This script only supports Ubuntu and Debian for now."
    exit
fi

# Ovewrite LATEST_VERSION if user pass a version number
if [ "$1" != "" ]; then
    LATEST_VERSION=$1
fi

echo -e "-------------"
echo -e "Welcome to Coolify v4 beta installer!"
echo -e "This script will install everything for you."
echo -e "(Source code: https://github.com/coollabsio/coolify/blob/main/scripts/install.sh)\n"
echo -e "-------------"

echo "OS: $OS_TYPE $OS_VERSION"
echo "Coolify version: $LATEST_VERSION"

echo -e "-------------"
echo "Installing required packages..."

apt update -y >/dev/null 2>&1
apt install -y curl wget git jq jc >/dev/null 2>&1

if ! [ -x "$(command -v docker)" ]; then
    echo "Docker is not installed. Installing Docker..."
    curl https://releases.rancher.com/install-docker/${DOCKER_VERSION}.sh | sh
    echo "Docker installed successfully"
fi
echo -e "-------------"
echo -e "Check Docker Configuration..."
mkdir -p /etc/docker

test -s /etc/docker/daemon.json && cp /etc/docker/daemon.json /etc/docker/daemon.json.original-$DATE || cat >/etc/docker/daemon.json <<EOL
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
EOL
cat >/etc/docker/daemon.json.coolify <<EOL
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
EOL
cat <<<$(jq . /etc/docker/daemon.json.coolify) >/etc/docker/daemon.json.coolify
cat <<<$(jq -s '.[0] * .[1]' /etc/docker/daemon.json /etc/docker/daemon.json.coolify) >/etc/docker/daemon.json

if [ -s /etc/docker/daemon.json.original-$DATE ]; then
    DIFF=$(diff <(jq --sort-keys . /etc/docker/daemon.json) <(jq --sort-keys . /etc/docker/daemon.json.original-$DATE))
    if [ "$DIFF" != "" ]; then
        echo "Docker configuration updated, restart docker daemon..."
        systemctl restart docker
    else
        echo "Docker configuration is up to date."
    fi
else
    echo "Docker configuration updated, restart docker daemon..."
    systemctl restart docker
fi


echo -e "-------------"

mkdir -p /data/coolify/ssh/keys
mkdir -p /data/coolify/ssh/mux
mkdir -p /data/coolify/source
mkdir -p /data/coolify/proxy/dynamic

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
    sed -i "s|APP_ID=.*|APP_ID=$(openssl rand -hex 16)|g" /data/coolify/source/.env
    sed -i "s|APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|g" /data/coolify/source/.env
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -base64 32)|g" /data/coolify/source/.env
    sed -i "s|REDIS_PASSWORD=.*|REDIS_PASSWORD=$(openssl rand -base64 32)|g" /data/coolify/source/.env
fi

# Merge .env and .env.production. New values will be added to .env
sort -u -t '=' -k 1,1 /data/coolify/source/.env /data/coolify/source/.env.production | sed '/^$/d' >/data/coolify/source/.env.temp && mv /data/coolify/source/.env.temp /data/coolify/source/.env

# Generate an ssh key (ed25519) at /data/coolify/ssh/keys/id.root@host.docker.internal
if [ ! -f /data/coolify/ssh/keys/id.root@host.docker.internal ]; then
    ssh-keygen -t ed25519 -a 100 -f /data/coolify/ssh/keys/id.root@host.docker.internal -q -N "" -C root@coolify
    chown 9999 /data/coolify/ssh/keys/id.root@host.docker.internal
fi

addSshKey() {
    cat /data/coolify/ssh/keys/id.root@host.docker.internal.pub >>~/.ssh/authorized_keys
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

echo -e "\nCongratulations! Your Coolify instance is ready to use.\n"
echo "Please visit http://$(curl -4s https://ifconfig.io):8000 to get started."
