---
type: fixed
commit: 4b9fe035dc
---
Builds that failed with x509 errors behind a TLS-inspecting proxy, although the server trusts the proxy CA ([#7568](https://github.com/coollabsio/coolify/issues/7568))

- The helper container mounts the CA bundle of the server read-only
