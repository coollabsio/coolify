#!/bin/bash
case "$1" in
    "all")
        echo '
#################################
#### Rebuilding everything. #####
#################################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root coolify-base bash -x /usr/src/app/scripts/install.sh
    #   preTasks
    #   docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type all
    ;;
    "upgrade-phase-1")
        echo '
################################
#### Upgrading Coolify P1. #####
################################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root coolify-base bash -x /usr/src/app/scripts/upgrade-p1.sh
        # preTasks
        # docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/install.js --type upgrade
    ;;
    "upgrade-phase-2")
        echo '
################################
#### Upgrading Coolify P2. #####
################################'
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root coolify-base bash -x /usr/src/app/scripts/upgrade-p2.sh
        # docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify:/data/coolify -u root -w /usr/src/app coolify-base node install/update.js --type upgrade
    ;;
    *)
        exit 1
     ;;
esac
