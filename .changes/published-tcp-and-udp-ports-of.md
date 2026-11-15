---
type: added
---
Published TCP and UDP ports of cluster applications, databases, and stack services on every server of the cluster

- The `Published Ports` page sets the protocol, the container port, the server port, and the allowed sources. An empty source list opens the port to everyone who can reach a server of the cluster
- A `ports:` entry `HOST:CONTAINER` of a Compose file is a published port of its service
