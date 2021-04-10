WHO=$(whoami)
if [ $WHO != 'root' ]; then
    echo 'You are not root. Ooops!'
    exit
fi

docker build --label coolify-reserve=true -t coolify-base -f scripts/Dockerfile-base .
docker run --rm -w /usr/src/app coolify-base node scripts/check.js

export $(egrep -v '^#' .env | grep -v 'GITHUB_APP_PRIVATE_KEY'| xargs)

docker network create $DOCKER_NETWORK --driver overlay
docker build -t coolify -f scripts/Dockerfile .