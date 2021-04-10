WHO=$(whoami)
if [ $WHO != 'root' ]; then
    echo 'You are not root. Ooops!'
    exit
fi

GIT_SSH_COMMAND="ssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no" git pull
docker build --label coolify-reserve=true -t coolify-base -f scripts/Dockerfile-base .
docker run --rm -w /usr/src/app coolify-base node scripts/check.js

if [ $? -ne 0 ]; then
   echo '
##################################
#### Missing configuration ! #####
##################################'
    exit 1
fi

export $(egrep -v '^#' .env | grep -v 'GITHUB_APP_PRIVATE_KEY'| xargs)
docker network create $DOCKER_NETWORK --driver overlay
docker build -t coolify -f scripts/Dockerfile .
docker stack rm coollabs-coolify
set -a && source .env && set +a && envsubst < scripts/coolify-template.yml | docker stack deploy -c - coollabs-coolify