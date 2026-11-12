---
type: added
---
The `Sentinel runtime` setting, which runs Sentinel as a systemd service on the host instead of a Docker container

- In `Settings` > `Advanced` > `Sentinel`, `Host service (systemd)` moves each server to `sentinel.service` at its next Sentinel check. A server without systemd keeps the Docker container until the next Sentinel version. A server with a failed install keeps the Docker container and is tried again after one day. The Coolify host (localhost) stays on the Docker container. The metrics and traffic history is kept. On the host, Sentinel also measures the size of bind mounts. Servers stay on the Docker container until Sentinel 1.1.6 is available
