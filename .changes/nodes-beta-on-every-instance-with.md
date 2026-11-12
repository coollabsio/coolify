---
type: added
---
Nodes (beta) on every instance, with Flux, the connection service of Nodes, in the Coolify container

- An instance admin turns them on in `Settings` > `Advanced` > `Nodes and clusters`. They are off by default
- Nodes connect to the `Flux host` and `Flux port` of that section. The default host is the instance domain, else the public IP address, and the default port is 7443
- Allow TCP port 7443 from every Node to the Coolify server. Node onboarding checks this connection before it installs Sentinel
- Coolify publishes port 7443 on every install. Set `FLUX_PORT` in `/data/coolify/source/.env` to use another host port
