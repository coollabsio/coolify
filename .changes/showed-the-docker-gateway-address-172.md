---
type: fixed
---
Showed the Docker gateway address (172.x.0.1) instead of the real client address of IPv6 visitors to applications behind the proxy ([#3436](https://github.com/coollabsio/coolify/issues/3436))

- Coolify creates the `coolify` network and new destination networks with IPv6 when Docker can allocate it, and without IPv6 when it cannot. Existing networks are not changed: to fix a server, recreate its network with the steps in [Applications see the Docker gateway IP](https://coolify.io/docs/troubleshoot/applications/wrong-client-ip)
