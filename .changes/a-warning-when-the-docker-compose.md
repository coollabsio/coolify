---
type: added
commit: fc027be248
---
A warning when the Docker Compose file of an application is in a subdirectory of the base directory, with a button that moves the directory of the file into the base directory ([#9525](https://github.com/coollabsio/coolify/issues/9525))

- Coolify runs Docker Compose in the base directory, so relative paths such as `build.context: ..` resolve from there
