---
type: added
---
A `Status for unknown hosts` setting on the proxy page, so that the proxy can answer unknown hosts with 404 and keep 503 for the domains of stopped resources

- With 404, requests for a domain that no application or service on the server uses get `404 Not Found`, so search engines remove the URLs of deleted resources
- The domains of applications and services on the server still get `503 Service Unavailable` while the resource is stopped, deploying, or unhealthy, so the site keeps its search ranking during downtime
- New servers use 404. Existing servers keep 503, and the proxy page shows a note about the new option
- The setting applies to Traefik and Caddy when `Unknown requests` is set to `Use custom request handling` without a redirect URL. A redirect URL still redirects all requests
- The API has the `unknown_host_status` field (404 or 503) on `GET` and `PATCH /api/v1/servers/{uuid}/proxy`
- The error page has a 404 version and a 503 version. A custom error page is used for both
- With 404, Coolify writes the catch-all again on the next server check when a resource domain is added or removed
- The catch-all file is now `default_redirect_404.yaml` or `default_redirect_404.caddy`. Coolify removes the old `default_redirect_503` file on the next server check after the upgrade
