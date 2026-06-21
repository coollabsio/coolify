# v5 Deploy Flows

These flows are written from the user-functionality view. Coolify owns the
state machine; Flux routes primitive requests; coold executes host operations.

## Flow: deploy Docker image app `nginx:alpine`

User intent:

```text
Run nginx:alpine on host H1, expose port 80, and route nginx.example.com to it.
```

### Steps

| Step | Coolify | Flux | coold |
| --- | --- | --- | --- |
| Create app | Stores app, source type `docker_image`, image `nginx:alpine`, target host, port, domain. | No action. | No action. |
| Start deploy | Creates deployment record and enters deploy state machine. | No action until dispatch. | No action. |
| Pull image | Sends `images.pull` to H1. | Routes request to H1's stream. | Pulls image through Podman. |
| Prepare network/volume | Sends required `networks.create` / `volumes.create` operations. | Routes requests. | Creates idempotent host resources. |
| Create container | Sends `containers.create` with image, name, env, labels, network, port, health check, mounts, DNS. | Routes request. | Applies deny filter and creates container through Podman. |
| Start container | Sends `containers.start`. | Routes request. | Starts container. |
| Check status | Sends `containers.inspect` or reads status stream. | Routes request. | Reads local Podman state. |
| Register endpoint | Sends `services.register`. | Routes request. | Writes this host's endpoint row to Corrosion. |
| Allow traffic | Sends `firewall.allow` for proxy-to-container traffic when required. | Routes request. | Writes iptables and nft allow rules. |
| Configure ingress | Renders proxy config from Coolify domain/resource state. If reload is needed, sends `containers.exec` or equivalent primitive for the proxy container. | Routes runtime reload request. | Executes the reload primitive only. |
| Mark running | Stores final status, deployment result, and user-facing logs. | Resolves final responses. | Keeps container running and reports future state. |

### Expected data locations

| Data | Owner |
| --- | --- |
| App name, team, project, env, domain, image ref, desired port | Coolify database |
| Deployment state and history | Coolify database |
| Open host stream and pending request IDs | Flux memory |
| Pulled image and running container | Host Podman storage |
| Endpoint rows for this host | Corrosion via coold |
| Firewall tuples and snapshots | Host kernel + `/etc/coolify` via coold |

### No build path

`nginx:alpine` is a prebuilt image. This flow does not use Git clone,
Dockerfile, buildpacks, Railpack, or the builder subprocess.

## Flow: delete the nginx app

| Step | Coolify | Flux | coold |
| --- | --- | --- | --- |
| User deletes resource | Validates permission and starts cleanup state machine. | No action until dispatch. | No action. |
| Stop container | Sends `containers.stop`. | Routes request. | Stops container. |
| Remove container | Sends `containers.delete`. | Routes request. | Deletes container. |
| Remove endpoint | Sends `services.unregister`. | Routes request. | Removes service endpoint row. |
| Remove firewall rule | Sends `firewall.revoke`. | Routes request. | Removes iptables and nft allow rules. |
| Update proxy | Removes rendered route and reloads proxy if needed. | Routes reload primitive. | Executes proxy reload primitive. |
| Finish cleanup | Marks resource deleted and records outcome. | Resolves responses. | No product state retained. |

## Flow: Git app with build

A Git app adds a build phase before container creation.

```text
Coolify resolves source + secrets + build config
        ↓
Flux routes build request to a host with builder capability
        ↓
coold supervises builder subprocess
        ↓
builder writes image/result
        ↓
Coolify deploy state machine continues with image/container primitives
```

coold supervises the builder process. The builder owns build implementation.
Coolify owns the decision to build, the build configuration, and the deployment
state transitions around the build.
