---
name: socialite-development
description: "Manages OAuth social authentication with Laravel Socialite. Activate when adding social login providers; configuring OAuth redirect/callback flows; retrieving authenticated user details; customizing scopes or parameters; setting up community providers; testing with Socialite fakes; or when the user mentions social login, OAuth, Socialite, or third-party authentication."
license: MIT
metadata:
  author: laravel
---

# Socialite Authentication

## Documentation

Use `search-docs` for detailed Socialite patterns and documentation (installation, configuration, routing, callbacks, testing, scopes, stateless auth).

## Available Providers

Built-in: `facebook`, `twitter`, `twitter-oauth-2`, `linkedin`, `linkedin-openid`, `google`, `github`, `gitlab`, `bitbucket`, `slack`, `slack-openid`, `twitch`

Community: 150+ additional providers at [socialiteproviders.com](https://socialiteproviders.com). For provider-specific setup, use `WebFetch` on `https://socialiteproviders.com/{provider-name}`.

Configuration key in `config/services.php` must match the driver name exactly — note the hyphenated keys: `twitter-oauth-2`, `linkedin-openid`, `slack-openid`.

Twitter/X: Use `twitter-oauth-2` (OAuth 2.0) for new projects. The legacy `twitter` driver is OAuth 1.0. Driver names remain unchanged despite the platform rebrand.

Community providers differ from built-in providers in the following ways:
- Installed via `composer require socialiteproviders/{name}`
- Must register via event listener — NOT auto-discovered like built-in providers
- Use `search-docs` for the registration pattern

## Adding a Provider

### 1. Configure the provider

Add the provider's `client_id`, `client_secret`, and `redirect` to `config/services.php`. The config key must match the driver name exactly.

### 2. Create redirect and callback routes

Two routes are needed: one that calls `Socialite::driver('provider')->redirect()` to send the user to the OAuth provider, and one that calls `Socialite::driver('provider')->user()` to receive the callback and retrieve user details.

### 3. Authenticate and store the user

In the callback, use `updateOrCreate` to find or create a user record from the provider's response (`id`, `name`, `email`, `token`, `refreshToken`), then call `Auth::login()`.

### 4. Customize the redirect (optional)

- `scopes()` — merge additional scopes with the provider's defaults
- `setScopes()` — replace all scopes entirely
- `with()` — pass optional parameters (e.g., `['hd' => 'example.com']` for Google)
- `asBotUser()` — Slack only; generates a bot token (`xoxb-`) instead of a user token (`xoxp-`). Must be called before both `redirect()` and `user()`. Only the `token` property will be hydrated on the user object.
- `stateless()` — for API/SPA contexts where session state is not maintained

### 5. Verify

1. Config key matches driver name exactly (check the list above for hyphenated names)
2. `client_id`, `client_secret`, and `redirect` are all present
3. Redirect URL matches what is registered in the provider's OAuth dashboard
4. Callback route handles denied grants (when user declines authorization)

Use `search-docs` for complete code examples of each step.

## Additional Features

Use `search-docs` for usage details on: `enablePKCE()`, `userFromToken($token)`, `userFromTokenAndSecret($token, $secret)` (OAuth 1.0), retrieving user details.

User object: `getId()`, `getName()`, `getEmail()`, `getAvatar()`, `getNickname()`, `token`, `refreshToken`, `expiresIn`, `approvedScopes`

## Testing

Socialite provides `Socialite::fake()` for testing redirects and callbacks. Use `search-docs` for faking redirects, callback user data, custom token properties, and assertion methods.

## Common Pitfalls

- Config key must match driver name exactly — hyphenated drivers need hyphenated keys (`linkedin-openid`, `slack-openid`, `twitter-oauth-2`). Mismatch silently fails.
- Every provider needs `client_id`, `client_secret`, and `redirect` in `config/services.php`. Missing any one causes cryptic errors.
- `scopes()` merges with defaults; `setScopes()` replaces all scopes entirely.
- Missing `stateless()` in API/SPA contexts causes `InvalidStateException`.
- Redirect URL in `config/services.php` must exactly match the provider's OAuth dashboard (including trailing slashes and protocol).
- Do not pass `state`, `response_type`, `client_id`, `redirect_uri`, or `scope` via `with()` — these are reserved.
- Community providers require event listener registration via `SocialiteWasCalled`.
- `user()` throws when the user declines authorization. Always handle denied grants.
