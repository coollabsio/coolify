#!/bin/bash

set -e
set -u
set -o pipefail

MAX_WAIT=30
waited=0

while [ $waited -lt $MAX_WAIT ]; do
    if docker ps >/dev/null 2>&1; then
        echo "Docker daemon is ready."
        exit 0
    fi
    sleep 1
    waited=$((waited + 1))
done

echo "ERROR: Docker daemon is not ready."
exit 1
