---
type: security
---
Hardened the first SSO sign-in of accounts that a team invitation created

- An instance admin can run `php artisan user:allow-sso-link <email>` when email is not set up
