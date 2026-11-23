---
type: fixed
---
Deployments that failed with certificate errors when they cloned over HTTPS from a Git server with a private CA, although the server trusts the CA ([#5725](https://github.com/coollabsio/coolify/issues/5725))

- The helper container adds the CA bundle of the server to the CAs of git and curl. Git LFS trusts it since v4.7.0
