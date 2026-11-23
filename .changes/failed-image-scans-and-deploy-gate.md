---
type: fixed
---
Failed image scans and deploy gate checks of Docker Compose applications whose service images are set with a variable ([#12622](https://github.com/coollabsio/coolify/issues/12622))

- The scan resolves `${VAR}` and `${VAR:-default}` from the environment variables of the application, and the `Security` page lists these services
