---
type: fixed
---
False `DNS mismatch` for servers that Coolify reaches over a private IP ([#12559](https://github.com/coollabsio/coolify/issues/12559))

- Set `Public IPv4` and `Public IPv6` on the server `General` page or in the server API. DNS checks and DNS records use these addresses instead of the server IP address. Localhost keeps using the instance public IPs
