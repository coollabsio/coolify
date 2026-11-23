---
type: fixed
---
Missed port conflicts of the proxy on servers without `ss`, when another service, such as a Docker container, listened on the port over IPv4 and IPv6, or on an address other than `127.0.0.1`

- The port check reports every listener on a proxy port as a conflict, except the Coolify proxy container. When neither `ss` nor `netstat` is installed, it reads the socket table of the kernel, so it needs no other tool
