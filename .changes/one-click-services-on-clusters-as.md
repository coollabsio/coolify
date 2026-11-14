---
type: added
---
One-click services on clusters, as stacks of cluster applications

- Each Compose service runs as a cluster application on the selected server and reaches the other services of its stack by its service name
- The template list shows `Not available on Nodes` with the reason for a template that needs the Docker socket, a host path, privileged mode, published ports, or a volume that two services share
