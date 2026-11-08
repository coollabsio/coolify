---
type: fixed
commit: ed03a31b3a
---
Deleted `coolify.yaml` and an unreachable dashboard on a server with the IP `host.docker.internal` that is not the default localhost server ([#12444](https://github.com/coollabsio/coolify/issues/12444))

- Coolify keeps treating this server as localhost after a save, for example after a proxy restart
