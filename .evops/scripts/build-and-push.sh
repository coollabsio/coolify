#!/bin/bash

set -e

registry="docker-registry.platformshape.local"
project="coollabsio"

getContainerVersion() {
  containerName=$1
  gh api "/orgs/coollabsio/packages/container/${containerName}/versions?per_page=1" | jq -r '.[0].metadata.container.tags[0]'
}

coolifyRealtimeVersion=$(getContainerVersion "coolify-realtime")
coolifyHelperVersion=$(getContainerVersion "coolify-helper")

serviceToDirMapping=(
  "coolify-helper:coolify-helper:${coolifyHelperVersion}"
  "coolify-helper:coolify-helper:latest"
  "coolify:production:latest"
  "coolify-realtime:coolify-realtime:${coolifyRealtimeVersion}"
  "coolify-realtime:coolify-realtime:latest"
)


for serviceMapping in "${serviceToDirMapping[@]}"; do
  echo "Building and pushing $service"
  # Extract the service name and directory from the mapping
  # Split the service mapping into service name, directory, and version
  IFS=':' read -r service serviceDir imageVersion <<< "$serviceMapping"


  # Check if the service directory was found
  if [ -z "$serviceDir" ]; then
    echo "No directory mapping found for $service"
    continue
  fi

  echo "Building Docker image for $service in directory $serviceDir - version $imageVersion"
  # Build the Docker image
  docker build \
    -f "docker/${serviceDir}/Dockerfile" . \
    -t "${registry}/${project}/${service}:${imageVersion}" \
    --progress plain \
    --platform linux/amd64 \
    --label "coolify.managed=true"

  # Push the Docker image to the registry
  docker push "${registry}/${project}/${service}:${imageVersion}"
done
