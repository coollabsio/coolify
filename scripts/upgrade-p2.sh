set -x 
WHO=$(whoami)
if [ $WHO != 'root' ]; then
    echo 'You are not root. Ooops!'
    exit
fi

docker service rm coollabs-coolify_coolify
set -a && source .env && set +a && envsubst < /usr/src/app/scripts/coolify-template.yml | docker stack deploy -c - coollabs-coolify