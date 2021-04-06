#!/bin/bash

preTasks() {
echo '
##############################
#### Pulling Git Updates #####
##############################'
GIT_SSH_COMMAND="ssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no" git pull

if [ $? -ne 0 ]; then
    echo '
####################################
#### Ooops something not okay! #####
####################################'
    exit 1
fi

echo '
##############################
#### Building Base Image #####
##############################'
docker build --label coolify-reserve=true -t coolify-base -f install/Dockerfile-base .

if [ $? -ne 0 ]; then
    echo '
####################################
#### Ooops something not okay! #####
####################################'
    exit 1
fi

echo '
##################################
#### Checking configuration. #####
##################################'
docker run --rm -w /usr/src/app coolify-base node install/install.js --check
if [ $? -ne 0 ]; then
   echo '
##################################
#### Missing configuration ! #####
##################################'
    exit 1
fi
}
case "$1" in
    "all")
       preTasks()
       echo '
#################################
#### Rebuilding everything. #####
#################################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type all
    ;;
    "coolify")
       preTasks()
       echo '
##############################
#### Rebuilding Coolify. #####
##############################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type coolify
    ;;
    "proxy")
       preTasks()
       echo '
############################
#### Rebuilding Proxy. #####
############################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type proxy
    ;;
    "upgrade-phase-1")
        preTasks()
        echo '
################################
#### Upgrading Coolify P1. #####
################################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type upgrade
    ;;
    "upgrade-phase-2")
        echo '
################################
#### Upgrading Coolify P2. #####
################################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/update.js --type upgrade
    ;;
    *)
        exit 1
     ;;
esac
