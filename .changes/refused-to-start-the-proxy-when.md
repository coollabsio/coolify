---
type: fixed
---
Refused to start the proxy when another service listened on port 80 or 443 of one specific IP address, such as a Tailscale address

- Only listeners on all addresses (`0.0.0.0`, `*`, `[::]`) count as a port conflict
