---
type: added
---
Builds and placement of cluster applications by the CPU architecture of the servers

- A build runs on a builder server with the architecture of the servers of the application, without emulation
- Coolify places, moves, and deploys an application only on servers with an architecture of its image, and shows the reason in the `Move to another server` dialog and on create
- The cluster server tables and the `Deployment target` list show the architecture of each server
- The application page shows a warning when Coolify cannot read the CPU architectures of the image
