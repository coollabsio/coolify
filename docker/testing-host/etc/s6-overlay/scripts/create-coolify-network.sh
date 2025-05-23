#!/bin/bash

set -euo pipefail

main() {
    local -r MAX_WAIT=30
    local waited=0

    while [ "$waited" -lt "$MAX_WAIT" ]; do
        if docker ps >/dev/null 2>&1; then
            echo "Docker daemon is ready."
            echo "Creating Coolify network if it doesn't exist…"
            docker network create coolify >/dev/null 2>&1 || true
            return 0
        fi
        sleep 1
        waited=$((waited + 1))
    done

    echo "ERROR: Docker daemon is not ready and Coolify network was not created."
    return 1
}

main
exit
