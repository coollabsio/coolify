---
type: fixed
commit: 6ab538d0f1
---
Rebuilt Docker layers when a short-lived container joins or leaves the network between two builds ([#12452](https://github.com/coollabsio/coolify/issues/12452))

- Database backup and S3 import helpers, Sentinel and the proxy error page container stay out of `--add-host`
