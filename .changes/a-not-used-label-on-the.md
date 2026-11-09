---
type: added
commit: 777362cd97
---
A `Not used` label on the TLS certificates of the Traefik proxy page, and a `Delete unused` button that deletes them in one step ([#12447](https://github.com/coollabsio/coolify/issues/12447))

- A certificate is unused when no route and no resource on the server uses its domain
- Traefik renews every certificate in `acme.json` until you delete it and restart the proxy
