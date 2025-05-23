#!/bin/bash

set -euo pipefail

main() {
    ssh-keygen -A

    if ! grep -Fxq "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68 root@coolify-dev" /root/.ssh/authorized_keys 2>/dev/null; then
        echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68 root@coolify-dev" >>/root/.ssh/authorized_keys
        chmod 600 /root/.ssh/authorized_keys
    fi
    return 0
}

main
exit
