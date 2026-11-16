---
type: added
---
Docker socket for services of a stack on a cluster, for templates such as `Dozzle`

- A service that mounts `docker.sock` or `podman.sock` runs when every server of the cluster has Sentinel with `workload.container_socket.v1`. Only a team admin or owner can create, change, or deploy it, and its page shows a warning
