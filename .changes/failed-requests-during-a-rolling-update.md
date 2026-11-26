---
type: fixed
---
Failed requests during a rolling update with Traefik, for up to the stop grace period, when the proxy labels of the application changed

- With Traefik, on the first deploy that adds the retry labels, the old container stops at once, without the stop grace period, because Traefik does not route to the application while both containers run. Later deploys and Caddy stop the old container with the stop grace period
