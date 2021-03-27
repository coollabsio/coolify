#!/bin/bash


case "$1" in
    "upgrade-p1")
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
        docker build --label coolify-reserve=true -t coolify-base -f scripts/Dockerfile-base .

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
        docker run --rm -w /usr/src/app coolify-base node scripts/install.js --check
        if [ $? -ne 0 ]; then
        echo '
#################################
#### Missing configuration! #####
#################################'
            exit 1
        fi
        echo '
################################
#### Upgrading Coolify P1. #####
################################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node scripts/upgrade.js --type upgrade-p1
    ;;
    "upgrade-p2")
        echo '
################################
#### Upgrading Coolify P2. #####
################################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node scripts/upgrade.js --type upgrade-p2
    ;;
    *)
        exit 1
     ;;
esac
