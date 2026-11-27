---
type: added
---
Concurrent builds on cluster servers, with a build queue for each cluster and limits for BuildKit

- The `Builds` section of the server page and `PATCH /api/v1/nodes/{uuid}/builds` set `Concurrent builds` (default 1) and the memory, CPU, and cache limits of BuildKit. A build without a free builder waits in a queue of at most 50 builds and shows its position in the build log and in the deployment history
