---
type: fixed
---
Showed no reason when a deployment could not clone a repository that the server could read, for example after the Docker Compose file loaded ([#2912](https://github.com/coollabsio/coolify/issues/2912))

- The deployment log now tells that the helper container could not connect to the Git host, and which firewall and DNS settings to check
