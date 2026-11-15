---
type: added
---
Docker Compose applications from Git on clusters, as stacks of cluster applications

- The `Docker Compose` build pack of the Git pages reads the Compose file at `Compose file location`, and each service with `build:` builds its image on a cluster server
- A stack deploy builds every service first, then deploys the services in `depends_on` order. A push webhook deploys the whole stack
