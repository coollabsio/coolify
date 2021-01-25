#!/bin/bash
echo "#### Building base image."
docker build -t coolify-base -f install/Dockerfile-base .
if [ $? -ne 0 ]; then
    echo '#### Ooops something not okay!'
    exit 1
fi

echo "#### Checking configuration."
docker run --rm -w /usr/src/app coolify-base node install/install.js --check
if [ $? -ne 0 ]; then
    echo '#### Missing configuration.'
    exit 1
fi

case "$1" in
    "all")
        echo "#### Rebuild everything."
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type all
    ;;
    "coolify")
        echo "#### Rebuild coolify."
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type coolify
    ;;
    "proxy")
        echo "#### Rebuild proxy."
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type proxy
    ;;
    *)
        echo "Use 'all' to build & deploy proxy+coolify, 'coolify' to build & deploy only coolify, 'proxy' to build & deploy only proxy."
        exit 1
     ;;
esac
