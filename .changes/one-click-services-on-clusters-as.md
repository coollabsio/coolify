---
type: added
---
One-click services on clusters, as stacks of cluster applications

- Each Compose service runs as a cluster application on the selected server and reaches the other services of its stack by its service name
- Services of a stack share a volume that the Compose file mounts in several services, also with `volumes_from`, read-write or read-only. The services that share a volume run on its server, and `Move to another server` of one of them moves all of them with the data
- The template list shows `Not available on Nodes` with the reason for a template that needs the Docker socket, a host path, privileged mode, or published ports
