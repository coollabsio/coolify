# Cloudflare Tunnel HTTP origin

Coolify can publish HTTP apps through a Cloudflare Tunnel instead of opening ports 80/443 or pointing DNS at the server public IP. This is the recommended path for homelabs, CGNAT, and dynamic WAN addresses.

## Direct vs Tunnel

- **Direct** (default): A/AAAA records to the server IP, sslip.io helpers, Let’s Encrypt HTTP-01 on `:80`.
- **Cloudflare Tunnel (HTTP)**: proxied CNAME to `{tunnel-id}.cfargotunnel.com`. Recheck does **not** compare to the WAN IP. Router ports 80/443 stay closed.

The server **SSH** Cloudflare Tunnel (ProxyCommand) is unchanged and separate from HTTP origin.

## Recommended setup

1. Servers → {server} → **Cloudflare Tunnel** → **HTTP origin**.
2. Paste a Cloudflare API token (Tunnel Edit, Account Read, Zone DNS Edit, Zone Read).
3. Pick account/zone, hostname or `*.example.com`, optionally a dashboard hostname.
4. Coolify creates or attaches a remotely-managed tunnel, sets catch-all ingress to `http://127.0.0.1:80`, creates missing proxied CNAMEs only, and runs host-network `coolify-cloudflared` (or `coolify-http-cloudflared` if SSH cloudflared is already present).
5. Add `http://app.example.com` on the resource. Redirect HTTP to HTTPS stays off. Use **Publish this hostname** if the CNAME is still missing.

Manual fallback: deploy the one-click Cloudflared **service** template, then **I already run cloudflared** so Coolify stops recommending WAN A records.

## DNS

Success: CNAME (or flattened CNAME) to `*.cfargotunnel.com`, or Cloudflare proxy IPs while HTTP tunnel mode is on.

Failure copy mentions the tunnel CNAME. It never tells you to create an A record to the server public IPv4.

## GitHub App

Set **Settings → Instance Domain** to the tunneled HTTPS hostname (for example `https://coolify.example.com`). GitHub OAuth/webhook callbacks cannot use `http://192.168.x.x:8000`. Put [Cloudflare Access](https://developers.cloudflare.com/cloudflare-one/policies/access/) in front of the dashboard.

## Out of scope

Full TLS / origin certificates, per-app Cloudflare ingress, and Cloudflare Access policy builders are not part of this flow.
