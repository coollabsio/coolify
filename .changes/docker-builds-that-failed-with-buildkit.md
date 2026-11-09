---
type: fixed
commit: 8c6774e17f
---
Docker builds that failed with "BuildKit is enabled but the buildx component is missing or broken" when an application has a build-time variable `HOME` or `DOCKER_CONFIG` ([#9726](https://github.com/coollabsio/coolify/issues/9726))

- The Docker CLI in the helper container always uses `/root/.docker`
- A `HOME` build argument keeps its value
