---
type: fixed
commit: b7defddc32
---
`DNS matches` on a domain in a connected Cloudflare zone that goes through Cloudflare to a different server, which then failed with Cloudflare error 526 and a Let's Encrypt error ([#12436](https://github.com/coollabsio/coolify/issues/12436))

- The domain shows `DNS mismatch`
- CNAME records, for example of a Cloudflare Tunnel, are still accepted
- A clone of an application or a service, or a new one through the API, gets a Cloudflare DNS record for each domain in a zone with automatic DNS, a clone only for the domains that the source does not use
