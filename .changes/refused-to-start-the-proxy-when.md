---
type: fixed
---
Refused to start a proxy bound to one IP address when another service listened on the same port of a different IP address, such as a Tailscale address

- When the proxy ports have a host IP, for example `203.0.113.5:443:443`, only listeners on all addresses or on that host IP count as a port conflict
