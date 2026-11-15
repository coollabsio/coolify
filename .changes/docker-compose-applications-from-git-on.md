---
type: added
---
Docker Compose applications from Git on clusters, as stacks of cluster applications

- The `Docker Compose` build pack of the Git pages reads the Compose file at `Compose file location`, and each service with `build:` builds its image on a cluster server
- A stack deploy builds every service first, then deploys the services in `depends_on` order. A push webhook deploys the whole stack
- Each stack deploy reads the Compose file again and adds, changes, and removes services to match it. A value that you changed on a service stays, and the stack page shows the last change of the file
