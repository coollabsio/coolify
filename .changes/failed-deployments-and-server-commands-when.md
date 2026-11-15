---
type: fixed
---
Failed deployments and server commands when sshd closed a new SSH connection before the key exchange (`kex_exchange_identification`) ([#6422](https://github.com/coollabsio/coolify/issues/6422))

- Coolify tries such a connection up to 5 times in about 30 seconds, and a deployment command also tries again when ssh exits with code 255 and prints nothing. Set `SSH_HANDSHAKE_MAX_RETRIES` to change the number of attempts
