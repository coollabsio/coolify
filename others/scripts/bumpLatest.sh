#!/bin/bash
VERSION=$(cat ./package.json | jq -r .version)
IMAGE=coollabsio/coolify
echo "Pulling $IMAGE:$VERSION"
docker pull $IMAGE:$VERSION

echo "Tagging $IMAGE:$VERSION as $IMAGE:latest"
docker tag $IMAGE:$VERSION $IMAGE:latest

echo "Pushing $IMAGE:latest"
read -p "Are you sure you want to push $IMAGE:latest? (y/n) " -n 1 -r
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborting"
    exit 1
fi

docker push $IMAGE:latest
