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
        echo '
#################################
#### Rebuilding everything. #####
#################################'
        bash -x scripts/install.sh
#       preTasks
#       docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type all
    ;;
    "upgrade-phase-1")
        echo '
################################
#### Upgrading Coolify P1. #####
################################'
        # bash -x scripts/upgrade-p1.sh
        preTasks
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type upgrade
    ;;
    "upgrade-phase-2")
        echo '
################################
#### Upgrading Coolify P2. #####
################################'
        # bash -x scripts/upgrade-p2.sh
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/update.js --type upgrade
    ;;
    *)
        exit 1
     ;;
esac
