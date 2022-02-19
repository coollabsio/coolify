#!/usr/bin/env bash
clear
ARG1=$1
WHO=$(whoami)
APP_ID=$(cat /proc/sys/kernel/random/uuid)
RANDOM_SECRET=$(echo $(($(date +%s%N) / 1000000)) | sha256sum | base64 | head -c 32)
SENTRY_DSN="https://9e7a74326f29422584d2d0bebdc8b7d3@o1082494.ingest.sentry.io/6091062"
DOCKER_MAJOR=20
DOCKER_MINOR=10
DOCKER_VERSION_OK="nok"

set -eou pipefail

if [ $ARG1 ] && [ $ARG1 == "-d" ]; then
    set -x
fi

function errorchecker() {
    exitCode=$?
    if [ $exitCode -ne "0" ]; then
        echo "$0 exited unexpectedly with status: $exitCode"
        exit $exitCode
    fi
}
trap 'errorchecker' EXIT

echo -e "Welcome to Coolify installer! \n"
echo "This script will install all the required packages and services to run Coolify."
echo -e "If you want to install Coolify on a different OS, please open an issue on Github to get supported version.\n\n"

echo -e "To see what I'm doing, please check:"
echo -e "https://github.com/coollabsio/get.coollabs.io/blob/main/static/coolify/install_v2.sh\n\n"

if [ $WHO != 'root' ]; then
    echo 'Run as root please: sudo sh -c "$(curl -fsSL https://get.coollabs.io/coolify/install.sh)"'
    exit 1
fi

if [ ! -x "$(command -v docker)" ]; then
    while true; do
        read -p "Docker Engine not found, should I install it automatically? [Yy/Nn] " yn
        case $yn in
        [Yy]*)
            sh -c "$(curl -fsSL https://get.docker.com)"
            break
            ;;
        [Nn]*)
            echo "Please install docker manually and update it to the latest, but at least to $DOCKER_MAJOR.$DOCKER_MINOR"
            exit 0
            ;;
        *) echo "Please answer Y or N." ;;
        esac
    done
fi

SERVER_VERSION=$(docker version -f "{{.Server.Version}}")
SERVER_VERSION_MAJOR=$(echo "$SERVER_VERSION" | cut -d'.' -f 1)
SERVER_VERSION_MINOR=$(echo "$SERVER_VERSION" | cut -d'.' -f 2)

if [ "$SERVER_VERSION_MAJOR" -ge "$DOCKER_MAJOR" ] &&
    [ "$SERVER_VERSION_MINOR" -ge "$DOCKER_MINOR" ]; then
    DOCKER_VERSION_OK="ok"
fi

if [ $DOCKER_VERSION_OK == 'nok' ]; then
    echo "Docker version less than $DOCKER_MAJOR.$DOCKER_MINOR, please update it to at least to $DOCKER_MAJOR.$DOCKER_MINOR"
    exit 1
fi

# Adding docker daemon configuration
cat <<EOF >/etc/docker/daemon.json
{
    "log-driver": "json-file",
    "log-opts": {
      "max-size": "100m",
      "max-file": "5"
    },
    "features": {
        "buildkit": true
    },
    "live-restore": true
}
EOF

# Restarting docker daemon
sh -c "systemctl daemon-reload && systemctl restart docker"

# Downloading docker-compose cli plugin
mkdir -p ~/.docker/cli-plugins/
curl -SL https://github.com/docker/compose/releases/download/v2.2.2/docker-compose-linux-x86_64 -o ~/.docker/cli-plugins/docker-compose
chmod +x ~/.docker/cli-plugins/docker-compose

# Making base directory for coolify
if [ ! -d coolify ]; then
    mkdir coolify
fi

if [ -f coolify/.env ]; then
    echo -e "Coolify is already installed, using some of the existing settings."
else
    echo "COOLIFY_APP_ID=$APP_ID
COOLIFY_SECRET_KEY=$RANDOM_SECRET
COOLIFY_DATABASE_URL=file:../db/prod.db
COOLIFY_SENTRY_DSN=$SENTRY_DSN
COOLIFY_HOSTED_ON=docker" > coolify/.env
fi

cd coolify && docker run -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db-sqlite coollabsio/coolify:latest /bin/sh -c "env | grep COOLIFY > .env && docker-compose up -d --force-recreate"

echo -e "Congratulations! Your coolify is ready to use.\n"
echo "Please visit http://<Your Public IP Address>:3000/ to get started."
echo "It will take a few minutes to start up, don't worry."