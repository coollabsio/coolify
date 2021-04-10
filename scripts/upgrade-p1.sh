WHO=$(whoami)
if [ $WHO != 'root' ]; then
    echo 'You are not root. Ooops!'
    exit
fi
export $(egrep -v '^#' .env | grep -v 'GITHUB_APP_PRIVATE_KEY'| xargs)

docker network create $DOCKER_NETWORK --driver overlay
docker build -t coolify -f scripts/Dockerfile .