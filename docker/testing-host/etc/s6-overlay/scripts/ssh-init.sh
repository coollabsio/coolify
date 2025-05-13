#!/bin/bash

set -euo pipefail

main() {
    if [ ! -f /etc/ssh/ssh_host_ed25519_key ]; then
        ssh-keygen -A
    fi

    echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68 root@coolify-dev" >>/root/.ssh/authorized_keys
    chmod 600 /root/.ssh/authorized_keys
    return 0
}

main
exit $?
