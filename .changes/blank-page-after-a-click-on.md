---
type: fixed
---
Blank page after a click on a sidebar link when a proxy such as Cloudflare Rocket Loader rewrites the dashboard HTML ([#5798](https://github.com/coollabsio/coolify/issues/5798))

- Dashboard pages send `Cache-Control: no-transform`, so proxies do not change their scripts
