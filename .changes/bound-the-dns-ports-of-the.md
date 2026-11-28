---
type: security
---
Bound the DNS ports of the `Pi-hole` service template to `127.0.0.1` by default, so a new Pi-hole on a public server is not an open DNS resolver ([#12699](https://github.com/coollabsio/coolify/issues/12699))

- Set `PIHOLE_DNS_BIND_ADDRESS` to a LAN or VPN address to serve a network. The DHCP and NTP ports are not published by default
