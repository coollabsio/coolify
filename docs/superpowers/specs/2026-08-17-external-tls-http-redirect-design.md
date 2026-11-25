# External TLS HTTP Redirect Design

## Problem

The Cloudflare Tunnel all-resource setup sends public HTTPS requests to Coolify's proxy through `http://localhost:80`. When a resource domain is stored as `https://` and Coolify redirects HTTP traffic to HTTPS, the tunneled request repeatedly returns to the HTTP entrypoint and causes `TOO_MANY_REDIRECTS`.

The current documentation avoids the loop by telling users to store the public domain as `http://`. That misrepresents the public URL and can produce incorrect secure cookies, OAuth callback URLs, and canonical links. Applications can already disable forced HTTPS in advanced settings, but the control is not near domain configuration. Service applications always enable the redirect in generated proxy configuration.

## Goals

- Store the externally visible URL accurately as `https://`.
- Let an upstream proxy such as Cloudflare handle the HTTP-to-HTTPS redirect.
- Apply the behavior consistently to applications and service applications.
- Keep existing resources secure and behaviorally unchanged by default.
- Keep the feature generic rather than coupling it to Cloudflare or a server-wide tunnel mode.

## Non-goals

- Detect Cloudflare automatically.
- Add a server-wide all-resource tunnel mode.
- Configure trusted forwarded-header networks.
- Replace the end-to-end origin TLS workflow.
- Change the default redirect behavior of existing or new resources.

## User Experience

The Domains page shows a boolean control named **Redirect HTTP to HTTPS** when a resource has at least one `https://` domain.

The control defaults to enabled. Its help text explains:

> Disable this when HTTPS and redirects are handled by Cloudflare Tunnel or another reverse proxy that connects to Coolify over HTTP.

A Cloudflare Tunnel user configures `https://app.example.com` and disables the control. A directly exposed resource leaves it enabled.

For regular and Docker Compose applications, the control edits the existing `ApplicationSetting::is_force_https_enabled` value. The existing Advanced-page control must not become an independent source of truth; it should either be removed from that page or remain bound to the same setting with the clearer label.

For service applications, the Domains page provides the same control for each application service. Database-only service entries do not expose it.

## Data Model

Add `is_force_https_enabled` to service applications as a non-null boolean with a default of `true`. Existing service applications therefore keep their current behavior after migration.

Regular applications continue using the existing application setting. No Cloudflare-specific state is stored.

## Proxy Configuration

Domain scheme and redirect policy remain independent:

- An `https://` domain continues generating the HTTPS router/listener.
- Its HTTP router/listener is also generated.
- When redirect is enabled, the HTTP router applies the HTTPS redirect middleware.
- When redirect is disabled, the HTTP router forwards the request to the resource without that middleware.

The stored service-application setting replaces the currently hardcoded `true` passed into Traefik and Caddy label generation. Existing path stripping, gzip, authentication, noindex, and www/non-www middleware behavior remains unchanged.

Preview deployments inherit the parent application's existing redirect setting, matching current application behavior.

## Validation and Authorization

The new service-application value is validated as a boolean. Updating it uses the same authorization checks as other service domain settings. Changing the value marks proxy configuration as changed and follows the existing save/redeploy flow used by domain configuration.

The control is relevant only when an HTTPS domain exists. Hiding it for HTTP-only resources does not reset the stored value.

## Documentation

Update the Cloudflare all-resource guide to instruct users to:

1. Store the public resource domain using `https://`.
2. Disable **Redirect HTTP to HTTPS** for that resource.
3. Let Cloudflare perform the public redirect and TLS termination.

The guide should retain the full TLS guide as the alternative for users who want TLS between cloudflared and Coolify's HTTPS entrypoint.

## Testing

Automated tests must cover:

- Application HTTPS domains with redirects enabled and disabled.
- Service-application HTTPS domains with redirects enabled and disabled.
- The service-application default remains enabled.
- Traefik and Caddy omit only the redirect behavior when disabled.
- Other middleware remains present when the redirect is disabled.
- HTTP-only resources do not show an irrelevant control.
- The Domains UI persists changes with existing authorization rules.

A manual smoke test should route a Cloudflare Tunnel hostname to `http://localhost:80`, save the Coolify resource as `https://`, disable the redirect, and verify the public HTTPS URL loads without a redirect loop.

## Compatibility

The database default of `true` preserves service behavior. Existing application values are unchanged. No automatic migration attempts to infer which resources are behind Cloudflare.
