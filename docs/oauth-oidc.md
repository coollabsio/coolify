# Generic OpenID Connect

Coolify can sign users in through any OIDC-compliant identity provider (Keycloak, Authentik, Pocket ID, Okta, Auth0, Nextcloud, and others) without a hardcoded driver for that vendor.

## Settings

Instance admins configure the provider at **Settings → Authentication → OpenID Connect**:

| Field | Required | Notes |
| --- | --- | --- |
| Redirect URI | No | Defaults to `/auth/oidc/callback`. Register this exact URL on the IdP. |
| Issuer URL | Yes | The OIDC issuer, **without** `/.well-known/openid-configuration`. Coolify discovers authorization, token, userinfo, and JWKS endpoints from that document. Must be HTTPS. |
| Client ID | Yes | Confidential client created on the IdP. |
| Client secret | Yes | Stored encrypted. |
| Scopes | No | Space-separated. Defaults to `openid email profile`. Must include `openid`. |
| Clock skew | No | Seconds of leeway for `exp` / `nbf` / `iat` (default 60). |
| Login button label | No | Defaults to the translated “Login with SSO” string. |
| Use PKCE | No | Enabled by default. |

Environment overrides: `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_REDIRECT_URI`, `OIDC_BASE_URL`, `OIDC_LOGIN_LABEL`.

## IdP checklist

1. Create a confidential OIDC client.
2. Set the redirect URI to `https://<coolify-host>/auth/oidc/callback`.
3. Request at least the `openid` and `email` scopes so Coolify can match or create the local user.
4. Paste the issuer, client id, and client secret into Coolify and enable the provider.
